package com.minsalud.encuestas.domain.model

data class Reporte(
    val rutaArchivo: String,
    val registrosIncluidos: Int,
    val fechaGeneracion: Long
)
