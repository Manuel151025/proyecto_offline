package com.minsalud.encuestas.domain.repository

import com.minsalud.encuestas.domain.model.Encuesta

interface EncuestaRepository {
    suspend fun saveEncuesta(encuesta: Encuesta)
    suspend fun getEncuesta(id: String): Encuesta?
}
