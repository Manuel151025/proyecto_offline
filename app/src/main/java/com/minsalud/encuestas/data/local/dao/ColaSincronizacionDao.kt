package com.minsalud.encuestas.data.local.dao

import androidx.room.*
import com.minsalud.encuestas.data.local.entity.ColaSincronizacionEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface ColaSincronizacionDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertColaSincronizacion(cola: ColaSincronizacionEntity)

    // Claves "tipo|numero" de personas con sincronización pendiente (o con error).
    @Query(
        """
        SELECT DISTINCT e.tipo_documento || '|' || e.numero_documento
        FROM encuestas e
        INNER JOIN cola_sincronizacion c ON c.id_encuesta = e.id
        WHERE c.estado != 'SENT'
        """
    )
    fun getPendingPersonaKeys(): Flow<List<String>>

    // Reintentables: PENDING y también ERROR (para que un fallo transitorio no
    // deje el registro varado para siempre).
    @Query("SELECT * FROM cola_sincronizacion WHERE estado != 'SENT' ORDER BY id_cola ASC")
    suspend fun getPendientes(): List<ColaSincronizacionEntity>

    @Query("UPDATE cola_sincronizacion SET estado = 'SENT', ultimo_error = NULL WHERE id_cola = :idCola")
    suspend fun marcarEnviado(idCola: Int)

    @Query("UPDATE cola_sincronizacion SET intentos = intentos + 1, ultimo_error = :error WHERE id_cola = :idCola")
    suspend fun incrementarIntento(idCola: Int, error: String)

    @Query("UPDATE cola_sincronizacion SET estado = 'ERROR', ultimo_error = :error WHERE id_cola = :idCola")
    suspend fun marcarError(idCola: Int, error: String)
}
