package com.minsalud.encuestas.data.local.dao

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import com.minsalud.encuestas.data.local.entity.MunicipioEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface MunicipioDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertAll(municipios: List<MunicipioEntity>)

    @Query("SELECT * FROM municipios")
    fun getAllMunicipios(): Flow<List<MunicipioEntity>>

    @Query("SELECT COUNT(*) FROM municipios")
    suspend fun count(): Int
}
