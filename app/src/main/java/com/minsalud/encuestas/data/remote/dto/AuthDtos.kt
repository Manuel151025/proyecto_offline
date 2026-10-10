package com.minsalud.encuestas.data.remote.dto

import com.google.gson.annotations.SerializedName

data class LoginRequestDto(
    @SerializedName("numero_documento") val numeroDocumento: String,
    @SerializedName("password") val password: String
)

data class LoginResponseDto(
    @SerializedName("success") val success: Boolean,
    @SerializedName("message") val message: String?,
    @SerializedName("token") val token: String?,
    @SerializedName("expira_en") val expiraEn: Long?,
    @SerializedName("encuestador") val encuestador: EncuestadorDto?
)

/** «¿Olvidaste tu contraseña?», paso 1. */
data class RecuperarRequestDto(
    @SerializedName("numero_documento") val numeroDocumento: String
)

/** Paso 2: el código del correo y la contraseña nueva. */
data class RestablecerRequestDto(
    @SerializedName("numero_documento") val numeroDocumento: String,
    @SerializedName("codigo") val codigo: String,
    @SerializedName("password") val password: String
)

/** Respuesta de la API con solo éxito y mensaje (también la de los errores). */
data class RespuestaSimpleDto(
    @SerializedName("success") val success: Boolean,
    @SerializedName("message") val message: String?
)

data class EncuestadorDto(
    @SerializedName("id") val id: Int,
    @SerializedName("nombre") val nombre: String,
    @SerializedName("numero_documento") val numeroDocumento: String
)
