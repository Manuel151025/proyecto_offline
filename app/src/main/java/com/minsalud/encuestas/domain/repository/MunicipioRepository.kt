package com.minsalud.encuestas.domain.repository

import com.minsalud.encuestas.domain.model.Municipio
import kotlinx.coroutines.flow.Flow

interface MunicipioRepository {
    fun getAllMunicipios(): Flow<List<Municipio>>
    suspend fun syncMunicipios()
}
