package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.model.TipoDocumento
import com.minsalud.encuestas.domain.repository.PersonaRepository
import com.minsalud.encuestas.domain.util.TimeProvider

class EliminarPersonaUseCase(
    private val personaRepository: PersonaRepository,
    private val timeProvider: TimeProvider
) {
    suspend operator fun invoke(tipoDocumento: TipoDocumento, numeroDocumento: String): Result<Unit> {
        return try {
            val persona = personaRepository.getPersona(tipoDocumento, numeroDocumento)
            if (persona != null) {
                val now = timeProvider.getCurrentTimeMillis()
                val softDeletedPersona = persona.copy(
                    deletedAt = now,
                    updatedAt = now
                )
                personaRepository.savePersona(softDeletedPersona)
                Result.Success(Unit)
            } else {
                Result.Error(DomainError.NotFound("Persona no encontrada para eliminar"))
            }
        } catch (e: DomainError) {
            Result.Error(e)
        } catch (e: Exception) {
            Result.Error(DomainError.UnknownError(originalError = e))
        }
    }
}
