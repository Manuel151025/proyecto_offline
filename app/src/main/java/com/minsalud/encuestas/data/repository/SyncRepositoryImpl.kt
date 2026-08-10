package com.minsalud.encuestas.data.repository

import com.minsalud.encuestas.data.local.dao.ColaSincronizacionDao
import com.minsalud.encuestas.data.local.dao.EncuestaDao
import com.minsalud.encuestas.data.local.dao.PersonaDao
import com.minsalud.encuestas.data.local.entity.ColaSincronizacionEntity
import com.minsalud.encuestas.data.local.entity.PersonaEntity
import com.minsalud.encuestas.data.local.entity.TipoDocumentoEntity
import com.minsalud.encuestas.data.local.prefs.SessionManager
import com.minsalud.encuestas.data.remote.api.ApiService
import com.minsalud.encuestas.data.remote.dto.EncuestaSyncDto
import com.minsalud.encuestas.data.remote.dto.PersonaRemotaDto
import com.minsalud.encuestas.data.remote.dto.PersonaSyncDto
import com.minsalud.encuestas.data.remote.dto.SyncRequestDto
import com.minsalud.encuestas.domain.model.ColaSincronizacion
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.repository.SyncRepository
import com.minsalud.encuestas.domain.sync.DecisionMezcla
import com.minsalud.encuestas.domain.sync.decidirMezcla
import com.minsalud.encuestas.data.mapper.toEntity
import kotlinx.coroutines.flow.Flow
import java.io.IOException
import javax.inject.Inject

/**
 * Sincronización por LOTES.
 *
 * Antes se enviaba una petición HTTP por cada elemento de la cola. Un
 * encuestador que volvía del campo con 200 encuestas hacía 200 viajes de ida y
 * vuelta, cada uno con su handshake TLS, sobre la conexión intermitente que
 * justifica que esta app sea offline-first. La PWA ya agrupaba; Android no.
 *
 * El servidor acepta hasta 500 registros por envío (MAX_LOTE en sync.php). Aquí
 * se usa un tamaño menor para que un fallo no obligue a reintentar un lote
 * enorme y para acotar la memoria al construir el JSON.
 */
class SyncRepositoryImpl @Inject constructor(
    private val colaDao: ColaSincronizacionDao,
    private val personaDao: PersonaDao,
    private val encuestaDao: EncuestaDao,
    private val apiService: ApiService,
    private val sessionManager: SessionManager
) : SyncRepository {

    private companion object {
        /** Muy por debajo del máximo del servidor: un reintento cuesta poco. */
        const val TAMANO_LOTE = 100

        /** Personas por página al descargar. El servidor admite hasta 500. */
        const val TAMANO_PAGINA = 200

        /** Tope de páginas por sincronización, como cortafuegos. */
        const val MAX_PAGINAS = 10
    }

    /** Elemento de la cola ya resuelto contra la base local, listo para enviar. */
    private data class Preparado(
        val cola: ColaSincronizacionEntity,
        val persona: PersonaSyncDto,
        val encuesta: EncuestaSyncDto
    )

    override suspend fun addToOutbox(item: ColaSincronizacion) {
        colaDao.insertColaSincronizacion(item.toEntity())
    }

    override fun pendingPersonaKeys(): Flow<List<String>> = colaDao.getPendingPersonaKeys()

    /**
     * Sincronización completa: primero sube, después baja.
     *
     * El orden importa. Si se bajara primero, un registro editado en el
     * teléfono y todavía sin enviar podría ser sobrescrito por la versión
     * anterior del servidor. Subiendo antes, lo que vuelve ya incluye el
     * cambio propio. La regla de mezcla protege igual el caso, pero no
     * conviene depender solo de ella.
     *
     * La descarga se intenta aunque la subida falle: el trabajo ajeno debería
     * llegar aunque el propio se atasque. Si la subida tenía errores, se
     * relanzan al final para que el WorkManager reprograme.
     */
    override suspend fun sincronizarPendientes() {
        val falloDeSubida = runCatching { subirPendientes() }.exceptionOrNull()

        try {
            descargarCambios()
        } catch (e: Exception) {
            // Si la subida ya venía fallando, ese error es el informativo.
            if (falloDeSubida == null) throw traducir(e)
        }

        if (falloDeSubida != null) throw falloDeSubida
    }

    /** Envía la cola de salida por lotes. */
    private suspend fun subirPendientes() {
        val pendientes = colaDao.getPendientes()
        if (pendientes.isEmpty()) return

        // 1. Resolver contra la base local. Los huérfanos se descartan aquí,
        //    sin gastar red: no tiene sentido enviarlos nunca.
        val preparados = mutableListOf<Preparado>()
        for (item in pendientes) {
            val encuesta = encuestaDao.getEncuesta(item.idEncuesta)
            if (encuesta == null) {
                colaDao.marcarError(item.idCola, "Encuesta eliminada o huérfana")
                continue
            }
            val persona = personaDao.getPersona(encuesta.tipoDocumento.name, encuesta.numeroDocumento)
            if (persona == null) {
                colaDao.marcarError(item.idCola, "Persona eliminada localmente")
                continue
            }

            preparados += Preparado(
                cola = item,
                persona = PersonaSyncDto(
                    tipoDocumento = persona.tipoDocumento.name,
                    numeroDocumento = persona.numeroDocumento,
                    nombres = persona.nombres,
                    apellidos = persona.apellidos,
                    fechaNacimiento = persona.fechaNacimiento,
                    telefono = persona.telefono,
                    email = persona.email,
                    direccion = persona.direccion,
                    vereda = persona.vereda,
                    eps = persona.eps,
                    ocupacion = persona.ocupacion,
                    estrato = persona.estrato,
                    municipioCodigo = persona.municipioCodigo,
                    updatedAt = persona.updatedAt,
                    deviceId = persona.deviceId,
                    deletedAt = persona.deletedAt
                ),
                encuesta = EncuestaSyncDto(
                    id = encuesta.id,
                    tipoDocumento = encuesta.tipoDocumento.name,
                    numeroDocumento = encuesta.numeroDocumento,
                    idEncuestador = encuesta.idEncuestador,
                    fechaEncuesta = encuesta.fechaEncuesta,
                    deviceId = encuesta.deviceId,
                    accion = encuesta.accion.name
                )
            )
        }

        if (preparados.isEmpty()) return

        var hayFalloDeRed = false
        var hayFalloFatal = false

        // 2. Enviar por lotes.
        for (lote in preparados.chunked(TAMANO_LOTE)) {
            // Varias encuestas pueden apuntar a la misma persona (crear y luego
            // editar). Se envía una sola vez, la versión más reciente: el
            // servidor resolvería igual por Last-Write-Wins, pero así el
            // payload no lleva copias redundantes.
            val personas = lote
                .map { it.persona }
                .groupBy { it.tipoDocumento to it.numeroDocumento }
                .map { (_, versiones) -> versiones.maxBy { it.updatedAt } }

            val request = SyncRequestDto(
                personas = personas,
                encuestas = lote.map { it.encuesta }
            )

            try {
                val response = apiService.syncData(request)

                if (response.isSuccessful) {
                    // Solo se marca como enviado lo que el servidor confirma.
                    // Antes se daba por bueno todo el envío; si el servidor
                    // ignoraba un registro, la app lo creía sincronizado.
                    val confirmadas = response.body()?.processedEncuestas.orEmpty().toSet()
                    for (p in lote) {
                        if (p.encuesta.id in confirmadas) {
                            colaDao.marcarEnviado(p.cola.idCola)
                        } else {
                            colaDao.incrementarIntento(p.cola.idCola, "El servidor no confirmó la encuesta")
                            hayFalloDeRed = true
                        }
                    }
                } else {
                    val codigo = response.code()
                    val detalle = "HTTP $codigo: ${response.message()}"
                    if (codigo in 400..499) {
                        // 4xx: el lote es inválido o el token no sirve. Reintentar
                        // sin cambios daría el mismo resultado.
                        lote.forEach { colaDao.marcarError(it.cola.idCola, detalle) }
                        hayFalloFatal = true
                    } else {
                        lote.forEach { colaDao.incrementarIntento(it.cola.idCola, detalle) }
                        hayFalloDeRed = true
                    }
                }
            } catch (e: IOException) {
                lote.forEach { colaDao.incrementarIntento(it.cola.idCola, "Fallo de red: ${e.message}") }
                hayFalloDeRed = true
            } catch (e: Exception) {
                lote.forEach { colaDao.incrementarIntento(it.cola.idCola, "Error desconocido: ${e.message}") }
                hayFalloFatal = true
            }
        }

        if (hayFalloDeRed) {
            throw DomainError.NetworkError("Existen fallos de red por reintentar")
        }
        if (hayFalloFatal) {
            throw DomainError.InvalidData("Existen errores 4xx que no se pudieron procesar")
        }
    }

    /**
     * Descarga incremental desde la marca de agua guardada.
     *
     * Pide páginas hasta que el servidor deja de decir "hay más". El tope de
     * páginas evita que un error de marca (por ejemplo, si quedara clavada)
     * convierta la sincronización en un bucle infinito sobre datos móviles;
     * lo que falte llega en la siguiente sincronización.
     */
    private suspend fun descargarCambios() {
        var marca = sessionManager.marcaDescarga()

        repeat(MAX_PAGINAS) {
            val response = apiService.getCambios(desde = marca, limite = TAMANO_PAGINA)
            if (!response.isSuccessful) {
                throw DomainError.NetworkError("La descarga falló: HTTP ${response.code()}")
            }
            val cuerpo = response.body() ?: return
            if (cuerpo.personas.isEmpty()) return

            mezclar(cuerpo.personas)

            // La marca solo avanza si el servidor devolvió una mayor. Guardarla
            // después de mezclar: si la app muere a mitad, se repite la página
            // en vez de saltársela, y repetir es inofensivo.
            if (cuerpo.marca > marca) {
                marca = cuerpo.marca
                sessionManager.setMarcaDescarga(marca)
            }

            if (!cuerpo.hayMas) return
        }
    }

    /** Aplica la regla de mezcla a cada persona recibida. */
    private suspend fun mezclar(remotas: List<PersonaRemotaDto>) {
        val conPendientes = colaDao.getPendingPersonaKeysList().toSet()

        for (remota in remotas) {
            // Un tipo desconocido se salta en vez de tumbar la descarga: perder
            // una persona es malo, perder la página entera es peor.
            val tipo = runCatching { TipoDocumentoEntity.valueOf(remota.tipoDocumento) }
                .getOrNull() ?: continue

            val local = personaDao.getPersona(tipo.name, remota.numeroDocumento)

            val decision = decidirMezcla(
                existeEnLocal = local != null,
                tienePendienteDeEnvio = "${tipo.name}|${remota.numeroDocumento}" in conPendientes,
                updatedAtLocal = local?.updatedAt ?: 0L,
                updatedAtRemoto = remota.updatedAt
            )
            if (decision == DecisionMezcla.CONSERVAR) continue

            personaDao.upsert(
                PersonaEntity(
                    tipoDocumento = tipo,
                    numeroDocumento = remota.numeroDocumento,
                    nombres = remota.nombres,
                    apellidos = remota.apellidos,
                    fechaNacimiento = remota.fechaNacimiento,
                    telefono = remota.telefono,
                    email = remota.email,
                    direccion = remota.direccion,
                    vereda = remota.vereda,
                    eps = remota.eps,
                    ocupacion = remota.ocupacion,
                    estrato = remota.estrato,
                    municipioCodigo = remota.municipioCodigo,
                    updatedAt = remota.updatedAt,
                    deviceId = remota.deviceId,
                    deletedAt = remota.deletedAt,
                    serverUpdatedAt = remota.serverUpdatedAt
                )
            )
        }
    }

    /** Convierte lo que lance la descarga en el error de dominio que toque. */
    private fun traducir(e: Exception): Exception = when (e) {
        is DomainError -> e
        is IOException -> DomainError.NetworkError("Fallo de red al descargar: ${e.message}")
        else -> DomainError.NetworkError("Error al descargar cambios: ${e.message}")
    }
}
