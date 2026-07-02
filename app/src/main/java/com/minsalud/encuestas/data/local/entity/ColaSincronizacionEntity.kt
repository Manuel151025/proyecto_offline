package com.minsalud.encuestas.data.local.entity

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "cola_sincronizacion")
data class ColaSincronizacionEntity(
    @PrimaryKey(autoGenerate = true)
    @ColumnInfo(name = "id_cola") val idCola: Int = 0,
    @ColumnInfo(name = "id_encuesta") val idEncuesta: String,
    @ColumnInfo(name = "payload") val payload: String,
    @ColumnInfo(name = "estado") val estado: EstadoSyncEntity = EstadoSyncEntity.PENDING,
    @ColumnInfo(name = "intentos") val intentos: Int = 0,
    @ColumnInfo(name = "ultimo_error") val ultimoError: String? = null
)
