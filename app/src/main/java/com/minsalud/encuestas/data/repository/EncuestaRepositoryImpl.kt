package com.minsalud.encuestas.data.repository

import com.minsalud.encuestas.data.local.dao.EncuestaDao
import com.minsalud.encuestas.data.mapper.toDomain
import com.minsalud.encuestas.data.mapper.toEntity
import com.minsalud.encuestas.domain.model.Encuesta
import com.minsalud.encuestas.domain.repository.EncuestaRepository
import javax.inject.Inject

class EncuestaRepositoryImpl @Inject constructor(
    private val encuestaDao: EncuestaDao
) : EncuestaRepository {

    override suspend fun saveEncuesta(encuesta: Encuesta) {
        encuestaDao.insert(encuesta.toEntity())
    }

    override suspend fun getEncuesta(id: String): Encuesta? {
        return encuestaDao.getEncuesta(id)?.toDomain()
    }
}
