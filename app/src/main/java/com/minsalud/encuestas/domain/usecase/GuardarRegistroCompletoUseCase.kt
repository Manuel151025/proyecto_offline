package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.ColaSincronizacion
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.model.Encuesta
import com.minsalud.encuestas.domain.model.EstadoSync
import com.minsalud.encuestas.domain.model.Persona
import com.minsalud.encuestas.domain.repository.EncuestaRepository
import com.minsalud.encuestas.domain.repository.PersonaRepository
import com.minsalud.encuestas.domain.repository.SyncRepository
import com.minsalud.encuestas.domain.util.TimeProvider
import com.minsalud.encuestas.domain.util.TransactionRunner

class GuardarRegistroCompletoUseCase(
    private val personaRepository: PersonaRepository,
    private val encuestaRepository: EncuestaRepository,
    private val syncRepository: SyncRepository,
    private val timeProvider: TimeProvider,
    private val transactionRunner: TransactionRunner
) {
    suspend operator fun invoke(persona: Persona, encuesta: Encuesta): Result<Unit> {
        return try {
            if (persona.numeroDocumento.isBlank() || persona.nombres.isBlank() || persona.apellidos.isBlank()) {
                return Result.Error(DomainError.InvalidData("Faltan campos obligatorios en Persona"))
            }
            if (encuesta.id.isBlank()) {
                return Result.Error(DomainError.InvalidData("Faltan datos de la encuesta"))
            }

            val timestamp = timeProvider.getCurrentTimeMillis()
            val personaToSave = persona.copy(updatedAt = timestamp)
            val encuestaToSave = encuesta.copy(fechaEncuesta = timestamp)

            val outboxItem = ColaSincronizacion(
                idCola = 0,
                idEncuesta = encuesta.id,
                payload = "", // El payload real podrÃ­a serializarse aquÃ­ o delegarse a la capa Data
                estado = EstadoSync.PENDING,
                intentos = 0,
                ultimoError = null
            )

            // Todo se ejecuta como una Ãºnica transacciÃ³n de negocio
            transactionRunner {
                personaRepository.savePersona(personaToSave)
                encuestaRepository.saveEncuesta(encuestaToSave)
                syncRepository.addToOutbox(outboxItem)
            }

            Result.Success(Unit)
        } catch (e: DomainError) {
            Result.Error(e)
        } catch (e: Exception) {
            Result.Error(DomainError.UnknownError(originalError = e))
        }
    }
}
