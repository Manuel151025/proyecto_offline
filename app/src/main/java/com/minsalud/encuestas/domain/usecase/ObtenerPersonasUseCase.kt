package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.model.Persona
import com.minsalud.encuestas.domain.repository.PersonaRepository
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.catch
import kotlinx.coroutines.flow.map

class ObtenerPersonasUseCase(
    private val personaRepository: PersonaRepository
) {
    operator fun invoke(): Flow<Result<List<Persona>>> {
        return personaRepository.getAllPersonas()
            .map { Result.Success(it) as Result<List<Persona>> }
            .catch { emit(Result.Error(DomainError.UnknownError(originalError = Exception(it)))) }
    }
}
