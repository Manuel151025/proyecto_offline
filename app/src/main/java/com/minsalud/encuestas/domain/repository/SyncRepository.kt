package com.minsalud.encuestas.domain.repository

import com.minsalud.encuestas.domain.model.ColaSincronizacion

interface SyncRepository {
    suspend fun sincronizarPendientes()
    suspend fun addToOutbox(item: ColaSincronizacion)
}
