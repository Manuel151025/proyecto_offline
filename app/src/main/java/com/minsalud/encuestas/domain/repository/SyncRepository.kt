package com.minsalud.encuestas.domain.repository

import com.minsalud.encuestas.domain.model.ColaSincronizacion
import kotlinx.coroutines.flow.Flow

interface SyncRepository {
    suspend fun sincronizarPendientes()
    suspend fun addToOutbox(item: ColaSincronizacion)

    /** Claves "tipo|numero" de personas con sincronización pendiente. */
    fun pendingPersonaKeys(): Flow<List<String>>
}
