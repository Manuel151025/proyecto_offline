package com.minsalud.encuestas.data.repository

import com.minsalud.encuestas.data.local.dao.ColaSincronizacionDao
import com.minsalud.encuestas.data.local.dao.EncuestaDao
import com.minsalud.encuestas.data.local.dao.PersonaDao
import com.minsalud.encuestas.data.local.entity.ColaSincronizacionEntity
import com.minsalud.encuestas.data.remote.api.ApiService
import com.minsalud.encuestas.data.remote.dto.EncuestaSyncDto
import com.minsalud.encuestas.data.remote.dto.PersonaSyncDto
import com.minsalud.encuestas.data.remote.dto.SyncRequestDto
import com.minsalud.encuestas.domain.model.ColaSincronizacion
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.repository.SyncRepository
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
    private val apiService: ApiService
) : SyncRepository {

    private companion object {
        /** Muy por debajo del máximo del servidor: un reintento cuesta poco. */
        const val TAMANO_LOTE = 100
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

    override suspend fun sincronizarPendientes() {
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
}
