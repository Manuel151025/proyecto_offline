package com.minsalud.encuestas.domain.validation

import com.minsalud.encuestas.domain.model.TipoDocumento

/**
 * Reglas de validación de campos, en Kotlin puro.
 *
 * Son las MISMAS que pwa/js/validacion.js y api/personas/validacion.php: lo
 * que el servidor rechazaría no se debe poder guardar en el teléfono, porque
 * el encuestador ya no estará frente a la persona cuando llegue el rechazo.
 *
 * Dos capas: `limpiar*` filtra lo que se escribe (lo que no puede ir ni
 * siquiera entra) y `es*Valido` revisa el valor completo al guardar.
 */
object Validaciones {

    /** Formato del número por tipo. Solo el pasaporte lleva letras. */
    data class FormatoDocumento(val min: Int, val max: Int, val letras: Boolean, val ayuda: String)

    fun formato(tipo: TipoDocumento): FormatoDocumento = when (tipo) {
        TipoDocumento.CC -> FormatoDocumento(6, 10, false, "Cédula: de 6 a 10 dígitos")
        TipoDocumento.TI -> FormatoDocumento(10, 11, false, "Tarjeta de identidad: 10 u 11 dígitos")
        TipoDocumento.RC -> FormatoDocumento(10, 11, false, "Registro civil (NUIP): 10 u 11 dígitos")
        TipoDocumento.CE -> FormatoDocumento(6, 10, false, "Cédula de extranjería: de 6 a 10 dígitos")
        TipoDocumento.PP -> FormatoDocumento(6, 12, true, "Pasaporte: de 6 a 12 letras o dígitos")
        TipoDocumento.NIT -> FormatoDocumento(9, 10, false, "NIT: 9 o 10 dígitos, sin guion")
        TipoDocumento.PE -> FormatoDocumento(6, 15, false, "Permiso especial: de 6 a 15 dígitos")
    }

    /**
     * Correo electrónico. Deliberadamente permisiva, como la expresión de
     * Android: comprueba la forma (algo@algo.dominio), no que la dirección
     * exista. Validar correo con precisión es imposible sin enviarle un mensaje.
     */
    private val EMAIL = Regex(
        "^[A-Za-z0-9._%+\\-]+@[A-Za-z0-9.\\-]+\\.[A-Za-z]{2,}$"
    )
    private val NOMBRE = Regex("^\\p{L}[\\p{L}\\p{M} '\\-]*$")
    /** Celular (3xx) o fijo con indicativo nacional (60x): siempre 10 dígitos. */
    private val TELEFONO = Regex("^(3\\d{9}|60\\d{8})$")

    const val MAX_NOMBRE = 60

    /** Textos libres opcionales: mínimo, máximo y caracteres admitidos. */
    enum class TextoLibre(val min: Int, val max: Int, val permitidos: String) {
        DIRECCION(5, 150, "\\p{L}\\p{M}0-9 #\\-.,/°º"),
        VEREDA(3, 100, "\\p{L}\\p{M}0-9 .'\\-"),
        EPS(3, 50, "\\p{L}\\p{M}0-9 .&\\-"),
        OCUPACION(3, 60, "\\p{L}\\p{M} ,.\\-")
    }

    // ---- Filtros mientras se escribe ----

    fun limpiarDocumento(valor: String, tipo: TipoDocumento): String {
        val f = formato(tipo)
        val limpio = if (f.letras) valor.uppercase().filter { it in 'A'..'Z' || it.isDigit() }
                     else valor.filter { it.isDigit() }
        return limpio.take(f.max)
    }

    fun limpiarNombre(valor: String): String =
        sinEspaciosDobles(valor.filter { it.isLetter() || it == ' ' || it == '\'' || it == '-' })
            .take(MAX_NOMBRE)

    fun limpiarTelefono(valor: String): String = valor.filter { it.isDigit() }.take(10)

    fun limpiarTexto(valor: String, campo: TextoLibre): String {
        val fuera = Regex("[^${campo.permitidos}]")
        return sinEspaciosDobles(valor.replace(fuera, "")).take(campo.max)
    }

    fun limpiarEstrato(valor: String): String = valor.filter { it in '1'..'6' }.take(1)

    private fun sinEspaciosDobles(v: String) = v.replace(Regex(" {2,}"), " ").trimStart()

    // ---- Validación al guardar ----

    fun esEmailValido(email: String): Boolean = email.length <= 100 && EMAIL.matches(email)

    /** Devuelve el mensaje de error del documento, o null si es válido. */
    fun errorDocumento(documento: String, tipo: TipoDocumento): String? {
        val f = formato(tipo)
        val doc = documento.trim()
        return when {
            doc.isEmpty() -> "El documento es obligatorio"
            f.letras && !doc.all { it in 'A'..'Z' || it in 'a'..'z' || it.isDigit() } ->
                "Solo letras y dígitos, sin puntos ni espacios"
            !f.letras && !doc.all { it.isDigit() } -> "Solo dígitos, sin puntos ni espacios"
            doc.length < f.min || doc.length > f.max -> f.ayuda
            else -> null
        }
    }

    fun esDocumentoValido(documento: String, tipo: TipoDocumento = TipoDocumento.CC): Boolean =
        errorDocumento(documento, tipo) == null

    /** Devuelve el mensaje de error de nombres/apellidos, o null si es válido. */
    fun errorNombre(nombre: String): String? {
        val v = nombre.trim()
        return when {
            v.isEmpty() -> "Es obligatorio"
            v.any { it.isDigit() } -> "No debe contener números"
            !NOMBRE.matches(v) -> "Solo letras, espacios, guion o apóstrofo"
            v.length < 2 -> "Debe tener al menos 2 letras"
            v.length > MAX_NOMBRE -> "Máximo $MAX_NOMBRE caracteres"
            else -> null
        }
    }

    fun esNombreValido(nombre: String): Boolean = errorNombre(nombre) == null

    /** Opcional: si viene, celular de 10 dígitos (3…) o fijo de 10 (60…). */
    fun esTelefonoValido(telefono: String): Boolean =
        telefono.isBlank() || TELEFONO.matches(telefono.trim())

    /** Opcional: devuelve el mensaje de error del texto libre, o null. */
    fun errorTexto(valor: String, campo: TextoLibre): String? {
        val v = valor.trim()
        if (v.isEmpty()) return null
        return when {
            v.length < campo.min -> "Mínimo ${campo.min} caracteres"
            v.length > campo.max -> "Máximo ${campo.max} caracteres"
            !Regex("^[${campo.permitidos}]+$").matches(v) || v.none { it.isLetter() } ->
                "Tiene caracteres no permitidos"
            else -> null
        }
    }

    /** Opcional: si viene, debe estar entre 1 y 6 (DANE). */
    fun esEstratoValido(estrato: String): Boolean {
        if (estrato.isBlank()) return true
        return estrato.toIntOrNull() in 1..6
    }

    /** La fecha de nacimiento no puede ser futura ni anterior a 1900. */
    fun esFechaNacimientoValida(fechaMs: Long?, ahoraMs: Long = System.currentTimeMillis()): Boolean =
        fechaMs == null || (fechaMs >= FECHA_MINIMA && fechaMs <= ahoraMs)

    /** Medianoche UTC del 1 de enero de 1900. */
    const val FECHA_MINIMA = -2208988800000L
}
