package com.minsalud.encuestas.domain.repository

import com.minsalud.encuestas.domain.model.ColaSincronizacion
import com.minsalud.encuestas.domain.model.EstadoCola
import com.minsalud.encuestas.domain.model.ResumenSync
import kotlinx.coroutines.flow.Flow

interface SyncRepository {
    suspend fun sincronizarPendientes(): ResumenSync

    /** Estado de la cola para la pantalla de sincronización. */
    fun estadoCola(): Flow<EstadoCola>
    suspend fun addToOutbox(item: ColaSincronizacion)

    /** Claves "tipo|numero" de personas con sincronización pendiente. */
    fun pendingPersonaKeys(): Flow<List<String>>
}
