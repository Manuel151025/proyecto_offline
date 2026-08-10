package com.minsalud.encuestas.data.remote.dto

import com.google.gson.annotations.SerializedName

data class SyncRequestDto(
    @SerializedName("personas") val personas: List<PersonaSyncDto>,
    @SerializedName("encuestas") val encuestas: List<EncuestaSyncDto>
)

data class PersonaSyncDto(
    @SerializedName("tipo_documento") val tipoDocumento: String,
    @SerializedName("numero_documento") val numeroDocumento: String,
    @SerializedName("nombres") val nombres: String,
    @SerializedName("apellidos") val apellidos: String,
    @SerializedName("fecha_nacimiento") val fechaNacimiento: Long?,
    @SerializedName("telefono") val telefono: String?,
    @SerializedName("email") val email: String?,
    @SerializedName("direccion") val direccion: String?,
    @SerializedName("vereda") val vereda: String?,
    @SerializedName("eps") val eps: String?,
    @SerializedName("ocupacion") val ocupacion: String?,
    @SerializedName("estrato") val estrato: Int?,
    @SerializedName("municipio_codigo") val municipioCodigo: String?,
    @SerializedName("updated_at") val updatedAt: Long,
    @SerializedName("device_id") val deviceId: String,
    @SerializedName("deleted_at") val deletedAt: Long?
)

data class EncuestaSyncDto(
    @SerializedName("id") val id: String,
    @SerializedName("tipo_documento") val tipoDocumento: String,
    @SerializedName("numero_documento") val numeroDocumento: String,
    @SerializedName("id_encuestador") val idEncuestador: Int,
    @SerializedName("fecha_encuesta") val fechaEncuesta: Long,
    @SerializedName("device_id") val deviceId: String,
    @SerializedName("accion") val accion: String
)

data class SyncResponseDto(
    @SerializedName("success") val success: Boolean,
    @SerializedName("message") val message: String?,
    @SerializedName("processed_encuestas") val processedEncuestas: List<String>
)

data class MunicipioDto(
    @SerializedName("codigo") val codigo: String,
    @SerializedName("nombre") val nombre: String,
    @SerializedName("departamento") val departamento: String
) {
    fun toEntity(): com.minsalud.encuestas.data.local.entity.MunicipioEntity {
        return com.minsalud.encuestas.data.local.entity.MunicipioEntity(
            codigo = codigo,
            nombre = nombre,
            departamento = departamento
        )
    }
}

/**
 * Respuesta de la descarga incremental (api/personas/cambios.php).
 *
 * `marca` es la nueva marca de agua: la mayor `server_updated_at` entregada.
 * `hayMas` indica que la página venía llena y probablemente quedan más.
 */
data class CambiosResponseDto(
    @SerializedName("success") val success: Boolean,
    @SerializedName("personas") val personas: List<PersonaRemotaDto>,
    @SerializedName("marca") val marca: Long,
    @SerializedName("hay_mas") val hayMas: Boolean
)

/** Persona tal como la devuelve el servidor, con su sello incluido. */
data class PersonaRemotaDto(
    @SerializedName("tipo_documento") val tipoDocumento: String,
    @SerializedName("numero_documento") val numeroDocumento: String,
    @SerializedName("nombres") val nombres: String,
    @SerializedName("apellidos") val apellidos: String,
    @SerializedName("fecha_nacimiento") val fechaNacimiento: Long?,
    @SerializedName("telefono") val telefono: String?,
    @SerializedName("email") val email: String?,
    @SerializedName("direccion") val direccion: String?,
    @SerializedName("vereda") val vereda: String?,
    @SerializedName("eps") val eps: String?,
    @SerializedName("ocupacion") val ocupacion: String?,
    @SerializedName("estrato") val estrato: Int?,
    @SerializedName("municipio_codigo") val municipioCodigo: String?,
    @SerializedName("updated_at") val updatedAt: Long,
    @SerializedName("device_id") val deviceId: String,
    @SerializedName("deleted_at") val deletedAt: Long?,
    @SerializedName("server_updated_at") val serverUpdatedAt: Long
)
