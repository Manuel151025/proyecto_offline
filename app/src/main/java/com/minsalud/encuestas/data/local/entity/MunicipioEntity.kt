package com.minsalud.encuestas.data.local.entity

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "municipios")
data class MunicipioEntity(
    @PrimaryKey
    @ColumnInfo(name = "codigo") val codigo: String,
    @ColumnInfo(name = "nombre") val nombre: String,
    @ColumnInfo(name = "departamento") val departamento: String
)
