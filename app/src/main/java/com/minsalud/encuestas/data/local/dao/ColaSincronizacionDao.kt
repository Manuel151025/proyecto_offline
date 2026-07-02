package com.minsalud.encuestas.data.local.dao

import androidx.room.*
import com.minsalud.encuestas.data.local.entity.ColaSincronizacionEntity

@Dao
interface ColaSincronizacionDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertColaSincronizacion(cola: ColaSincronizacionEntity)

    @Query("SELECT * FROM cola_sincronizacion WHERE estado = 'PENDING' ORDER BY idCola ASC")
    suspend fun getPendientes(): List<ColaSincronizacionEntity>

    @Query("UPDATE cola_sincronizacion SET estado = 'SENT', ultimo_error = NULL WHERE idCola = :idCola")
    suspend fun marcarEnviado(idCola: Int)

    @Query("UPDATE cola_sincronizacion SET intentos = intentos + 1, ultimo_error = :error WHERE idCola = :idCola")
    suspend fun incrementarIntento(idCola: Int, error: String)

    @Query("UPDATE cola_sincronizacion SET estado = 'FAILED', ultimo_error = :error WHERE idCola = :idCola")
    suspend fun marcarError(idCola: Int, error: String)
}
