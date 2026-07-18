package com.minsalud.encuestas.data.local.dao

import androidx.room.Dao
import androidx.room.Query
import androidx.room.Upsert
import com.minsalud.encuestas.data.local.entity.PersonaEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface PersonaDao {
    @Upsert
    suspend fun upsert(persona: PersonaEntity)

    @Query("SELECT * FROM personas WHERE tipo_documento = :tipo AND numero_documento = :numero")
    suspend fun getPersona(tipo: String, numero: String): PersonaEntity?

    @Query("SELECT * FROM personas WHERE deleted_at IS NULL")
    fun getAllPersonas(): Flow<List<PersonaEntity>>

    @Query("SELECT * FROM personas WHERE deleted_at IS NULL ORDER BY updated_at DESC")
    suspend fun getAllPersonasList(): List<PersonaEntity>
}
