package com.minsalud.encuestas.domain.model

data class Encuesta(
    val id: String,
    val tipoDocumento: TipoDocumento,
    val numeroDocumento: String,
    val idEncuestador: Int,
    val fechaEncuesta: Long,
    val fechaSincronizacion: Long?,
    val deviceId: String,
    val accion: AccionEncuesta
)
