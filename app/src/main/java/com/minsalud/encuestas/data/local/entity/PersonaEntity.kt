package com.minsalud.encuestas.data.local.entity

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.Index

/**
 * El índice compuesto sirve a las dos consultas de listado del DAO, que filtran
 * por `deleted_at IS NULL` y ordenan por `updated_at DESC`. Sin él, SQLite
 * recorre la tabla entera y ordena en memoria en cada emisión del Flow.
 *
 * No se añade el equivalente en MySQL: el servidor solo accede a `personas` por
 * clave primaria (ver la consulta de sync.php), así que allí no aportaría nada.
 */
@Entity(
    tableName = "personas",
    primaryKeys = ["tipo_documento", "numero_documento"],
    indices = [Index(value = ["deleted_at", "updated_at"], name = "idx_personas_listado")]
)
data class PersonaEntity(
    @ColumnInfo(name = "tipo_documento") val tipoDocumento: TipoDocumentoEntity,
    @ColumnInfo(name = "numero_documento") val numeroDocumento: String,
    @ColumnInfo(name = "nombres") val nombres: String,
    @ColumnInfo(name = "apellidos") val apellidos: String,
    @ColumnInfo(name = "fecha_nacimiento") val fechaNacimiento: Long?,
    @ColumnInfo(name = "telefono") val telefono: String?,
    @ColumnInfo(name = "email") val email: String?,
    @ColumnInfo(name = "direccion") val direccion: String?,
    @ColumnInfo(name = "vereda") val vereda: String?,
    @ColumnInfo(name = "eps") val eps: String?,
    @ColumnInfo(name = "ocupacion") val ocupacion: String?,
    @ColumnInfo(name = "estrato") val estrato: Int?,
    @ColumnInfo(name = "municipio_codigo") val municipioCodigo: String?,
    @ColumnInfo(name = "updated_at") val updatedAt: Long,
    @ColumnInfo(name = "device_id") val deviceId: String,
    @ColumnInfo(name = "deleted_at") val deletedAt: Long?,

    /**
     * Sello del SERVIDOR, no del dispositivo. Es la marca de agua de la
     * descarga incremental: `updatedAt` lo pone el teléfono y puede ir hacia
     * atrás si su reloj está mal, así que no sirve para preguntar "dame lo
     * cambiado desde X". Null en los registros creados en local que todavía
     * no han subido.
     */
    @ColumnInfo(name = "server_updated_at") val serverUpdatedAt: Long? = null
)
