package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.model.Persona
import com.minsalud.encuestas.domain.repository.PersonaRepository
import com.minsalud.encuestas.domain.util.TimeProvider

class GuardarPersonaUseCase(
    private val personaRepository: PersonaRepository,
    private val timeProvider: TimeProvider
) {
    suspend operator fun invoke(persona: Persona): Result<Unit> {
        return try {
            if (persona.numeroDocumento.isBlank() || persona.nombres.isBlank() || persona.apellidos.isBlank()) {
                return Result.Error(DomainError.InvalidData("Faltan campos obligatorios en Persona"))
            }
            
            val personaToSave = persona.copy(updatedAt = timeProvider.getCurrentTimeMillis())
            
            personaRepository.savePersona(personaToSave)
            Result.Success(Unit)
        } catch (e: DomainError) {
            Result.Error(e)
        } catch (e: Exception) {
            Result.Error(DomainError.UnknownError(originalError = e))
        }
    }
}
