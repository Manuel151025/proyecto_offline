package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.model.ResumenSync
import com.minsalud.encuestas.domain.repository.SyncRepository

class SincronizarPendientesUseCase(
    private val syncRepository: SyncRepository
) {
    suspend operator fun invoke(): Result<ResumenSync> {
        return try {
            Result.Success(syncRepository.sincronizarPendientes())
        } catch (e: DomainError) {
            Result.Error(e)
        } catch (e: Exception) {
            Result.Error(DomainError.UnknownError(originalError = e))
        }
    }
}
