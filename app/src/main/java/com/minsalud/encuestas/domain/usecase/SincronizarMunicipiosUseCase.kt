package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.repository.MunicipioRepository

class SincronizarMunicipiosUseCase(
    private val municipioRepository: MunicipioRepository
) {
    suspend operator fun invoke(): Result<Unit> {
        return try {
            municipioRepository.syncMunicipios()
            Result.Success(Unit)
        } catch (e: DomainError) {
            Result.Error(e)
        } catch (e: Exception) {
            Result.Error(DomainError.NetworkError("Error al sincronizar municipios"))
        }
    }
}
