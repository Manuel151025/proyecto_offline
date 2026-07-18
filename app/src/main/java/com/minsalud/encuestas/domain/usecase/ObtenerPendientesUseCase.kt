package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.domain.repository.SyncRepository
import kotlinx.coroutines.flow.Flow

/** Emite el conjunto de claves "tipo|numero" de personas con sync pendiente. */
class ObtenerPendientesUseCase(
    private val syncRepository: SyncRepository
) {
    operator fun invoke(): Flow<List<String>> = syncRepository.pendingPersonaKeys()
}
