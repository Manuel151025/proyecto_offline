package com.minsalud.encuestas.domain.repository

import com.minsalud.encuestas.domain.model.Persona
import com.minsalud.encuestas.domain.model.TipoDocumento
import kotlinx.coroutines.flow.Flow

interface PersonaRepository {
    suspend fun savePersona(persona: Persona)
    suspend fun getPersona(tipo: TipoDocumento, numero: String): Persona?
    fun getAllPersonas(): Flow<List<Persona>>
}
