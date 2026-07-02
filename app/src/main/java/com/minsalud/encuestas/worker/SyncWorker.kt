package com.minsalud.encuestas.worker

import android.content.Context
import androidx.hilt.work.HiltWorker
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.usecase.SincronizarPendientesUseCase
import dagger.assisted.Assisted
import dagger.assisted.AssistedInject

@HiltWorker
class SyncWorker @AssistedInject constructor(
    @Assisted context: Context,
    @Assisted workerParams: WorkerParameters,
    private val sincronizarPendientesUseCase: SincronizarPendientesUseCase
) : CoroutineWorker(context, workerParams) {

    override suspend fun doWork(): Result {
        return when (val result = sincronizarPendientesUseCase()) {
            is com.minsalud.encuestas.core.Result.Success -> {
                Result.success()
            }
            is com.minsalud.encuestas.core.Result.Error -> {
                when (result.error) {
                    is DomainError.NetworkError,
                    is DomainError.UnknownError -> {
                        // Error temporal de red o servidor 5xx: Backoff exponencial entra en acción
                        Result.retry()
                    }
                    is DomainError.InvalidData,
                    is DomainError.NotFound -> {
                        // Errores de negocio 4xx o inconsistencia local: no reintentar
                        Result.failure()
                    }
                }
            }
        }
    }
}
