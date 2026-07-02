package com.minsalud.encuestas.data.repository

import com.minsalud.encuestas.data.local.dao.PersonaDao
import com.minsalud.encuestas.data.mapper.toDomain
import com.minsalud.encuestas.data.mapper.toEntity
import com.minsalud.encuestas.domain.model.Persona
import com.minsalud.encuestas.domain.repository.PersonaRepository
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map
import javax.inject.Inject

class PersonaRepositoryImpl @Inject constructor(
    private val personaDao: PersonaDao
) : PersonaRepository {
    
    override suspend fun savePersona(persona: Persona) {
        personaDao.upsert(persona.toEntity())
    }

    override suspend fun getPersona(tipo: TipoDocumento, numero: String): Persona? {
        return personaDao.getPersona(tipo.name, numero)?.toDomain()
    }

    override fun getAllPersonas(): Flow<List<Persona>> {
        return personaDao.getAllPersonas().map { list -> list.map { it.toDomain() } }
    }
}
