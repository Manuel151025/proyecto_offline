package com.minsalud.encuestas.domain.repository

import com.minsalud.encuestas.domain.model.Municipio
import kotlinx.coroutines.flow.Flow

interface MunicipioRepository {
    fun getAllMunicipios(): Flow<List<Municipio>>
    suspend fun syncMunicipios()

    /** Siembra los municipios base (offline-first) si la tabla local está vacía. */
    suspend fun seedMunicipiosIfEmpty()
}
