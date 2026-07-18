package com.minsalud.encuestas.data.mapper

import com.minsalud.encuestas.data.local.entity.*
import com.minsalud.encuestas.domain.model.*

// Persona Mapper
fun PersonaEntity.toDomain(): Persona {
    return Persona(
        tipoDocumento = TipoDocumento.valueOf(tipoDocumento.name),
        numeroDocumento = numeroDocumento,
        nombres = nombres,
        apellidos = apellidos,
        fechaNacimiento = fechaNacimiento,
        telefono = telefono,
        email = email,
        direccion = direccion,
        vereda = vereda,
        eps = eps,
        ocupacion = ocupacion,
        estrato = estrato,
        municipioCodigo = municipioCodigo,
        updatedAt = updatedAt,
        deviceId = deviceId,
        deletedAt = deletedAt
    )
}

fun Persona.toEntity(): PersonaEntity {
    return PersonaEntity(
        tipoDocumento = TipoDocumentoEntity.valueOf(tipoDocumento.name),
        numeroDocumento = numeroDocumento,
        nombres = nombres,
        apellidos = apellidos,
        fechaNacimiento = fechaNacimiento,
        telefono = telefono,
        email = email,
        direccion = direccion,
        vereda = vereda,
        eps = eps,
        ocupacion = ocupacion,
        estrato = estrato,
        municipioCodigo = municipioCodigo,
        updatedAt = updatedAt,
        deviceId = deviceId,
        deletedAt = deletedAt
    )
}

// Encuesta Mapper
fun EncuestaEntity.toDomain(): Encuesta {
    return Encuesta(
        id = id,
        tipoDocumento = TipoDocumento.valueOf(tipoDocumento.name),
        numeroDocumento = numeroDocumento,
        idEncuestador = idEncuestador,
        fechaEncuesta = fechaEncuesta,
        fechaSincronizacion = fechaSincronizacion,
        deviceId = deviceId,
        accion = AccionEncuesta.valueOf(accion.name)
    )
}

fun Encuesta.toEntity(): EncuestaEntity {
    return EncuestaEntity(
        id = id,
        tipoDocumento = TipoDocumentoEntity.valueOf(tipoDocumento.name),
        numeroDocumento = numeroDocumento,
        idEncuestador = idEncuestador,
        fechaEncuesta = fechaEncuesta,
        fechaSincronizacion = fechaSincronizacion,
        deviceId = deviceId,
        accion = AccionEncuestaEntity.valueOf(accion.name)
    )
}

// Municipio Mapper
fun MunicipioEntity.toDomain(): Municipio {
    return Municipio(
        codigo = codigo,
        nombre = nombre,
        departamento = departamento
    )
}

// ColaSincronizacion Mapper
fun ColaSincronizacionEntity.toDomain(): ColaSincronizacion {
    return ColaSincronizacion(
        idCola = idCola,
        idEncuesta = idEncuesta,
        payload = payload,
        estado = EstadoSync.valueOf(estado.name),
        intentos = intentos,
        ultimoError = ultimoError
    )
}

fun ColaSincronizacion.toEntity(): ColaSincronizacionEntity {
    return ColaSincronizacionEntity(
        idCola = idCola,
        idEncuesta = idEncuesta,
        payload = payload,
        estado = EstadoSyncEntity.valueOf(estado.name),
        intentos = intentos,
        ultimoError = ultimoError
    )
}
