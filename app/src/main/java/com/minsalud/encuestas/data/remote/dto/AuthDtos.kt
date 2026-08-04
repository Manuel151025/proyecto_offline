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

data class EncuestadorDto(
    @SerializedName("id") val id: Int,
    @SerializedName("nombre") val nombre: String,
    @SerializedName("numero_documento") val numeroDocumento: String
)
