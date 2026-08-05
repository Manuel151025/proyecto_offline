package com.minsalud.encuestas.domain.validation

/**
 * Reglas de validación de campos, en Kotlin puro.
 *
 * Vivían en el ViewModel usando `android.util.Patterns`, lo que las ataba al
 * framework: son reglas de negocio, y en pruebas JVM `Patterns` no existe, así
 * que la validación del correo no se podía comprobar sin levantar un emulador.
 */
object Validaciones {

    /**
     * Correo electrónico. Deliberadamente permisiva, como la expresión de
     * Android: comprueba la forma (algo@algo.dominio), no que la dirección
     * exista. Validar correo con precisión es imposible sin enviarle un mensaje.
     */
    private val EMAIL = Regex(
        "^[A-Za-z0-9._%+\\-]+@[A-Za-z0-9.\\-]+\\.[A-Za-z]{2,}$"
    )

    fun esEmailValido(email: String): Boolean = EMAIL.matches(email)

    /** El documento se captura solo con dígitos; aquí se exige longitud mínima. */
    fun esDocumentoValido(documento: String): Boolean =
        documento.isNotBlank() && documento.length >= 6

    /** Un nombre con dígitos casi siempre es un error de digitación. */
    fun esNombreValido(nombre: String): Boolean =
        nombre.isNotBlank() && nombre.none { it.isDigit() }

    /** Opcional: si viene, debe tener al menos 7 dígitos. */
    fun esTelefonoValido(telefono: String): Boolean =
        telefono.isBlank() || telefono.length >= 7

    /** Opcional: si viene, debe estar entre 1 y 6 (DANE). */
    fun esEstratoValido(estrato: String): Boolean {
        if (estrato.isBlank()) return true
        return estrato.toIntOrNull() in 1..6
    }
}
