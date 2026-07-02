package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.model.Persona
import com.minsalud.encuestas.domain.model.TipoDocumento
import com.minsalud.encuestas.domain.repository.PersonaRepository

class ObtenerPersonaUseCase(
    private val personaRepository: PersonaRepository
) {
    suspend operator fun invoke(tipoDocumento: TipoDocumento, numeroDocumento: String): Result<Persona?> {
        return try {
            val persona = personaRepository.getPersona(tipoDocumento, numeroDocumento)
            if (persona == null) {
                Result.Error(DomainError.NotFound("Persona no encontrada"))
            } else {
                Result.Success(persona)
            }
        } catch (e: DomainError) {
            Result.Error(e)
        } catch (e: Exception) {
            Result.Error(DomainError.UnknownError(originalError = e))
        }
    }
}
