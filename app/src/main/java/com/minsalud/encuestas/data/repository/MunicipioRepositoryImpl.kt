package com.minsalud.encuestas.data.repository

import com.minsalud.encuestas.data.local.dao.MunicipioDao
import com.minsalud.encuestas.data.local.seed.MunicipiosSeed
import com.minsalud.encuestas.data.mapper.toDomain
import com.minsalud.encuestas.data.remote.api.ApiService
import com.minsalud.encuestas.domain.model.Municipio
import com.minsalud.encuestas.domain.repository.MunicipioRepository
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map
import javax.inject.Inject

class MunicipioRepositoryImpl @Inject constructor(
    private val municipioDao: MunicipioDao,
    private val apiService: ApiService
) : MunicipioRepository {

    override fun getAllMunicipios(): Flow<List<Municipio>> {
        return municipioDao.getAllMunicipios().map { list -> list.map { it.toDomain() } }
    }

    override suspend fun syncMunicipios() {
        try {
            val response = apiService.getMunicipios()
            if (response.isSuccessful) {
                response.body()?.let { dtos ->
                    val entities = dtos.map { it.toEntity() }
                    municipioDao.insertAll(entities)
                }
            }
        } catch (e: Exception) {
            // Manejado silenciosamente, se mostrarÃ¡ listado local
        }
    }

    override suspend fun seedMunicipiosIfEmpty() {
        if (municipioDao.count() == 0) {
            municipioDao.insertAll(MunicipiosSeed.data)
        }
    }
}
