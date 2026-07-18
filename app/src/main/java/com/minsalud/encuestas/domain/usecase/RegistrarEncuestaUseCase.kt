package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.model.Encuesta
import com.minsalud.encuestas.domain.repository.EncuestaRepository

class RegistrarEncuestaUseCase(
    private val encuestaRepository: EncuestaRepository
) {
    suspend operator fun invoke(encuesta: Encuesta): Result<Unit> {
        return try {
            if (encuesta.id.isBlank() || encuesta.numeroDocumento.isBlank()) {
                return Result.Error(DomainError.InvalidData("Datos de encuesta inválidos"))
            }
            encuestaRepository.saveEncuesta(encuesta)
            Result.Success(Unit)
        } catch (e: DomainError) {
            Result.Error(e)
        } catch (e: Exception) {
            Result.Error(DomainError.UnknownError(originalError = e))
        }
    }
}
