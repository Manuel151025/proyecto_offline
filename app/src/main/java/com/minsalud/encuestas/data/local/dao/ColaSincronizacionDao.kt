package com.minsalud.encuestas.data.local.dao

import androidx.room.*
import com.minsalud.encuestas.data.local.entity.ColaSincronizacionEntity
import kotlinx.coroutines.flow.Flow

/** Fila de [ColaSincronizacionDao.conteoPorEstado]. */
data class ConteoEstado(
    @ColumnInfo(name = "estado") val estado: String,
    @ColumnInfo(name = "total") val total: Int
)

/** Fila de [ColaSincronizacionDao.rechazados]. */
data class RechazoLocal(
    @ColumnInfo(name = "tipo_documento") val tipoDocumento: String,
    @ColumnInfo(name = "numero_documento") val numeroDocumento: String,
    @ColumnInfo(name = "motivo") val motivo: String
)

@Dao
interface ColaSincronizacionDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertColaSincronizacion(cola: ColaSincronizacionEntity)

    // Claves "tipo|numero" de personas con sincronización pendiente (o con
    // error). Los RECHAZADOS no cuentan: no se van a enviar nunca, y contarlos
    // dejaba a la persona marcada como «Pendiente» para siempre.
    @Query(
        """
        SELECT DISTINCT e.tipo_documento || '|' || e.numero_documento
        FROM encuestas e
        INNER JOIN cola_sincronizacion c ON c.id_encuesta = e.id
        WHERE c.estado NOT IN ('SENT', 'RECHAZADO')
        """
    )
    fun getPendingPersonaKeys(): Flow<List<String>>

    /**
     * Misma consulta, en una sola lectura. La descarga la necesita como valor
     * puntual: no puede quedarse observando un Flow mientras decide qué
     * sobrescribir.
     */
    @Query(
        """
        SELECT DISTINCT e.tipo_documento || '|' || e.numero_documento
        FROM encuestas e
        INNER JOIN cola_sincronizacion c ON c.id_encuesta = e.id
        WHERE c.estado NOT IN ('SENT', 'RECHAZADO')
        """
    )
    suspend fun getPendingPersonaKeysList(): List<String>

    /** Conteo por estado, para la pantalla de sincronización. */
    @Query("SELECT estado, COUNT(*) AS total FROM cola_sincronizacion GROUP BY estado")
    fun conteoPorEstado(): Flow<List<ConteoEstado>>

    /** Registros rechazados por el servidor, con su motivo. */
    @Query(
        """
        SELECT e.tipo_documento AS tipo_documento, e.numero_documento AS numero_documento,
               COALESCE(c.ultimo_error, '') AS motivo
        FROM cola_sincronizacion c
        INNER JOIN encuestas e ON e.id = c.id_encuesta
        WHERE c.estado = 'RECHAZADO'
        ORDER BY c.id_cola DESC
        """
    )
    fun rechazados(): Flow<List<RechazoLocal>>

    // Reintentables: PENDING y también ERROR (para que un fallo transitorio no
    // deje el registro varado para siempre). RECHAZADO queda fuera: el servidor
    // ya dijo que ese registro es inválido, así que reenviarlo solo consume
    // datos móviles y arrastra al resto del lote en cada intento.
    @Query(
        "SELECT * FROM cola_sincronizacion " +
            "WHERE estado NOT IN ('SENT', 'RECHAZADO') ORDER BY id_cola ASC"
    )
    suspend fun getPendientes(): List<ColaSincronizacionEntity>

    @Query("UPDATE cola_sincronizacion SET estado = 'RECHAZADO', ultimo_error = :motivo WHERE id_cola = :idCola")
    suspend fun marcarRechazado(idCola: Int, motivo: String)

    @Query("UPDATE cola_sincronizacion SET estado = 'SENT', ultimo_error = NULL WHERE id_cola = :idCola")
    suspend fun marcarEnviado(idCola: Int)

    @Query("UPDATE cola_sincronizacion SET intentos = intentos + 1, ultimo_error = :error WHERE id_cola = :idCola")
    suspend fun incrementarIntento(idCola: Int, error: String)

    @Query("UPDATE cola_sincronizacion SET estado = 'ERROR', ultimo_error = :error WHERE id_cola = :idCola")
    suspend fun marcarError(idCola: Int, error: String)
}
