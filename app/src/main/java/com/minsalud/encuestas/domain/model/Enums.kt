package com.minsalud.encuestas.domain.model

/**
 * Tipos de documento admitidos.
 *
 * Debe coincidir con TIPOS_DOCUMENTO de api/personas/sync.php y con la lista
 * del formulario de la PWA. Antes tenía solo cuatro: al activar la descarga,
 * una persona registrada desde la web con RC, PP o PE no se podía representar
 * y rompía la lectura de la base.
 */
enum class TipoDocumento(val descripcion: String) {
    CC("Cédula de ciudadanía"),
    TI("Tarjeta de identidad"),
    RC("Registro civil"),
    CE("Cédula de extranjería"),
    PP("Pasaporte"),
    NIT("NIT"),
    PE("Permiso especial de permanencia")
}
enum class AccionEncuesta { CREACION, ACTUALIZACION }
enum class EstadoSync { PENDING, SENT, ERROR }
