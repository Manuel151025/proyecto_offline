package com.minsalud.encuestas.data.local.entity

import androidx.room.ColumnInfo
import androidx.room.Entity

@Entity(
    tableName = "personas",
    primaryKeys = ["tipo_documento", "numero_documento"]
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
    @ColumnInfo(name = "deleted_at") val deletedAt: Long?
)
