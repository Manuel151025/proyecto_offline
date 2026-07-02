package com.minsalud.encuestas.data.repository

import com.minsalud.encuestas.data.local.dao.ColaSincronizacionDao
import com.minsalud.encuestas.data.local.dao.EncuestaDao
import com.minsalud.encuestas.data.local.dao.PersonaDao
import com.minsalud.encuestas.data.remote.api.ApiService
import com.minsalud.encuestas.data.remote.dto.EncuestaSyncDto
import com.minsalud.encuestas.data.remote.dto.PersonaSyncDto
import com.minsalud.encuestas.data.remote.dto.SyncRequestDto
import com.minsalud.encuestas.domain.model.ColaSincronizacion
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.repository.SyncRepository
import com.minsalud.encuestas.data.mapper.toEntity
import java.io.IOException
import javax.inject.Inject

class SyncRepositoryImpl @Inject constructor(
    private val colaDao: ColaSincronizacionDao,
    private val personaDao: PersonaDao,
    private val encuestaDao: EncuestaDao,
    private val apiService: ApiService
) : SyncRepository {

    override suspend fun addToOutbox(item: ColaSincronizacion) {
        colaDao.insertColaSincronizacion(item.toEntity())
    }

    override suspend fun sincronizarPendientes() {
        val pendientes = colaDao.getPendientes()
        if (pendientes.isEmpty()) return

        var hasNetworkError = false
        var hasFatalError = false

        for (item in pendientes) {
            try {
                val encuestaEntity = encuestaDao.getEncuesta(item.idEncuesta)
                if (encuestaEntity == null) {
                    colaDao.marcarError(item.idCola, "Encuesta eliminada o huérfana")
                    continue
                }

                val personaEntity = personaDao.getPersona(encuestaEntity.tipoDocumento, encuestaEntity.numeroDocumento)
                if (personaEntity == null) {
                    colaDao.marcarError(item.idCola, "Persona eliminada localmente")
                    continue
                }

                val personaSync = PersonaSyncDto(
                    tipoDocumento = personaEntity.tipoDocumento.name,
                    numeroDocumento = personaEntity.numeroDocumento,
                    nombres = personaEntity.nombres,
                    apellidos = personaEntity.apellidos,
                    fechaNacimiento = personaEntity.fechaNacimiento,
                    telefono = personaEntity.telefono,
                    email = personaEntity.email,
                    direccion = personaEntity.direccion,
                    eps = personaEntity.eps,
                    ocupacion = personaEntity.ocupacion,
                    estrato = personaEntity.estrato,
                    municipioCodigo = personaEntity.municipioCodigo,
                    updatedAt = personaEntity.updatedAt,
                    deviceId = personaEntity.deviceId,
                    deletedAt = personaEntity.deletedAt
                )

                val encuestaSync = EncuestaSyncDto(
                    id = encuestaEntity.id,
                    tipoDocumento = encuestaEntity.tipoDocumento.name,
                    numeroDocumento = encuestaEntity.numeroDocumento,
                    idEncuestador = encuestaEntity.idEncuestador,
                    fechaEncuesta = encuestaEntity.fechaEncuesta,
                    deviceId = encuestaEntity.deviceId,
                    accion = encuestaEntity.accion.name
                )

                val request = SyncRequestDto(
                    personas = listOf(personaSync),
                    encuestas = listOf(encuestaSync)
                )

                val response = apiService.syncData(request)

                if (response.isSuccessful) {
                    colaDao.marcarEnviado(item.idCola)
                } else {
                    val code = response.code()
                    if (code in 400..499) {
                        colaDao.marcarError(item.idCola, "HTTP $code: ${response.message()}")
                        hasFatalError = true
                    } else {
                        colaDao.incrementarIntento(item.idCola, "HTTP $code: ${response.message()}")
                        hasNetworkError = true
                    }
                }

            } catch (e: IOException) {
                colaDao.incrementarIntento(item.idCola, "Fallo de Red: ${e.message}")
                hasNetworkError = true
            } catch (e: Exception) {
                colaDao.incrementarIntento(item.idCola, "Error Desconocido: ${e.message}")
                hasFatalError = true
            }
        }

        if (hasNetworkError) {
            throw DomainError.NetworkError("Existen fallos de red por reintentar")
        }
        if (hasFatalError) {
            throw DomainError.InvalidData("Existen errores 4xx que no se pudieron procesar")
        }
    }
}
