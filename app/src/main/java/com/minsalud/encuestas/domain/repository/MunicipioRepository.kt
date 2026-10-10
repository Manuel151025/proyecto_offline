package com.minsalud.encuestas.domain.repository

import com.minsalud.encuestas.domain.model.Eps
import com.minsalud.encuestas.domain.model.Municipio
import kotlinx.coroutines.flow.Flow

/** Catálogos de municipios (DIVIPOLA) y EPS. */
interface MunicipioRepository {
    fun getAllMunicipios(): Flow<List<Municipio>>
    suspend fun syncMunicipios()

    /**
     * Pone la tabla local al día con el catálogo que trae la app. Antes solo se
     * sembraba si la tabla estaba vacía, así que un teléfono se quedaba para
     * siempre con la primera lista que tuvo.
     */
    suspend fun asegurarCatalogo()

    suspend fun getEps(): List<Eps>
}
