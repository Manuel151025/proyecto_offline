package com.minsalud.encuestas.domain.model

data class Persona(
    val tipoDocumento: TipoDocumento,
    val numeroDocumento: String,
    val nombres: String,
    val apellidos: String,
    val fechaNacimiento: Long?,
    val telefono: String?,
    val email: String?,
    val direccion: String?,
    val eps: String?,
    val ocupacion: String?,
    val estrato: Int?,
    val municipioCodigo: String?,
    val updatedAt: Long,
    val deviceId: String,
    val deletedAt: Long?
)
