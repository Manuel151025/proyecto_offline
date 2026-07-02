package com.minsalud.encuestas.data.local.dao

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import com.minsalud.encuestas.data.local.entity.EncuestaEntity

@Dao
interface EncuestaDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insert(encuesta: EncuestaEntity)

    @Query("SELECT * FROM encuestas WHERE id = :id")
    suspend fun getEncuesta(id: String): EncuestaEntity?
}
