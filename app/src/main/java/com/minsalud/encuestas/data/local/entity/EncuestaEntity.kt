package com.minsalud.encuestas.data.local.entity

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "encuestas")
data class EncuestaEntity(
    @PrimaryKey
    @ColumnInfo(name = "id") val id: String,
    @ColumnInfo(name = "tipo_documento") val tipoDocumento: TipoDocumentoEntity,
    @ColumnInfo(name = "numero_documento") val numeroDocumento: String,
    @ColumnInfo(name = "id_encuestador") val idEncuestador: Int,
    @ColumnInfo(name = "fecha_encuesta") val fechaEncuesta: Long,
    @ColumnInfo(name = "fecha_sincronizacion") val fechaSincronizacion: Long?,
    @ColumnInfo(name = "device_id") val deviceId: String,
    @ColumnInfo(name = "accion") val accion: AccionEncuestaEntity
)
