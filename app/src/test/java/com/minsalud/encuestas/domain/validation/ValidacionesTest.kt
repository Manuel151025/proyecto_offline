package com.minsalud.encuestas.domain.validation

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Reglas de validación de campos.
 *
 * Vivían en el ViewModel apoyadas en `android.util.Patterns`, que en pruebas
 * JVM no existe: la validación del correo era el único punto del formulario
 * que no se podía comprobar sin emulador. Al extraerlas a Kotlin puro quedan
 * cubiertas aquí, que además es donde corresponden por ser reglas de negocio.
 */
class ValidacionesTest {

    @Test
    fun `acepta correos con forma valida`() {
        listOf(
            "maria.rios@example.com",
            "encuestador@minsalud.gov.co",
            "a@b.co",
            "nombre+etiqueta@dominio.org",
            "con_guion-bajo@sub.dominio.com"
        ).forEach {
            assertTrue("debería aceptar $it", Validaciones.esEmailValido(it))
        }
    }

    @Test
    fun `rechaza correos mal formados`() {
        listOf(
            "sin-arroba.com",
            "@sindominio.com",
            "sinusuario@",
            "doble@@arroba.com",
            "sin.punto@dominio",
            "con espacio@dominio.com",
            ""
        ).forEach {
            assertFalse("no debería aceptar '$it'", Validaciones.esEmailValido(it))
        }
    }

    @Test
    fun `el documento exige al menos seis digitos`() {
        assertTrue(Validaciones.esDocumentoValido("123456"))
        assertTrue(Validaciones.esDocumentoValido("1098765432"))
        assertFalse(Validaciones.esDocumentoValido("12345"))
        assertFalse(Validaciones.esDocumentoValido(""))
        assertFalse(Validaciones.esDocumentoValido("   "))
    }

    @Test
    fun `los nombres no admiten digitos`() {
        assertTrue(Validaciones.esNombreValido("María Fernanda"))
        assertTrue(Validaciones.esNombreValido("Ríos Peña"))
        assertFalse(Validaciones.esNombreValido("Maria2"))
        assertFalse(Validaciones.esNombreValido(""))
    }

    @Test
    fun `el telefono es opcional pero si viene necesita siete digitos`() {
        assertTrue("vacío es válido: el campo es opcional", Validaciones.esTelefonoValido(""))
        assertTrue(Validaciones.esTelefonoValido("3001234567"))
        assertTrue(Validaciones.esTelefonoValido("6012345"))
        assertFalse(Validaciones.esTelefonoValido("300123"))
    }

    @Test
    fun `el estrato es opcional y va de uno a seis`() {
        assertTrue("vacío es válido: el campo es opcional", Validaciones.esEstratoValido(""))
        (1..6).forEach { assertTrue("estrato $it", Validaciones.esEstratoValido("$it")) }
        assertFalse(Validaciones.esEstratoValido("0"))
        assertFalse(Validaciones.esEstratoValido("7"))
        assertFalse(Validaciones.esEstratoValido("x"))
    }
}
