package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.model.Municipio
import com.minsalud.encuestas.domain.repository.MunicipioRepository
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.catch
import kotlinx.coroutines.flow.map

class ObtenerMunicipiosUseCase(
    private val municipioRepository: MunicipioRepository
) {
    operator fun invoke(): Flow<Result<List<Municipio>>> {
        return municipioRepository.getAllMunicipios()
            .map { Result.Success(it) as Result<List<Municipio>> }
            .catch { emit(Result.Error(DomainError.UnknownError(originalError = Exception(it)))) }
    }
}
