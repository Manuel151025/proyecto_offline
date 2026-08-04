package com.minsalud.encuestas.testutil

import com.minsalud.encuestas.domain.model.AccionEncuesta
import com.minsalud.encuestas.domain.model.Encuesta
import com.minsalud.encuestas.domain.model.Persona
import com.minsalud.encuestas.domain.model.TipoDocumento

/**
 * Constructores de datos de prueba. Cada test solo sobrescribe los campos que
 * le importan, así queda a la vista qué condición está ejercitando.
 */

fun personaDePrueba(
    tipoDocumento: TipoDocumento = TipoDocumento.CC,
    numeroDocumento: String = "1098765432",
    nombres: String = "María Fernanda",
    apellidos: String = "Ríos Peña",
    updatedAt: Long = 1_000L,
    deletedAt: Long? = null
) = Persona(
    tipoDocumento = tipoDocumento,
    numeroDocumento = numeroDocumento,
    nombres = nombres,
    apellidos = apellidos,
    fechaNacimiento = 631_152_000_000L,
    telefono = "3001234567",
    email = "maria.rios@example.com",
    direccion = "Vereda El Progreso, finca 3",
    vereda = "El Progreso",
    eps = "Nueva EPS",
    ocupacion = "Agricultora",
    estrato = 2,
    municipioCodigo = "05001",
    updatedAt = updatedAt,
    deviceId = "device-test-01",
    deletedAt = deletedAt
)

fun encuestaDePrueba(
    id: String = "enc-uuid-0001",
    numeroDocumento: String = "1098765432",
    idEncuestador: Int = 1,
    fechaEncuesta: Long = 1_000L
) = Encuesta(
    id = id,
    tipoDocumento = TipoDocumento.CC,
    numeroDocumento = numeroDocumento,
    idEncuestador = idEncuestador,
    fechaEncuesta = fechaEncuesta,
    fechaSincronizacion = null,
    deviceId = "device-test-01",
    accion = AccionEncuesta.CREACION
)
