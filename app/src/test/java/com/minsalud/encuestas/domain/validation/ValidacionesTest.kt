package com.minsalud.encuestas.domain.validation

import com.minsalud.encuestas.domain.model.TipoDocumento
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
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
            "encuestador@coloffline.co",
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
    fun `la cedula va de 6 a 10 digitos y sin letras`() {
        assertTrue(Validaciones.esDocumentoValido("123456"))
        assertTrue(Validaciones.esDocumentoValido("1098765432"))
        assertFalse(Validaciones.esDocumentoValido("12345"))
        assertFalse(Validaciones.esDocumentoValido("10987654321"))
        assertFalse(Validaciones.esDocumentoValido("sdscf1ds5ds1c"))
        assertFalse(Validaciones.esDocumentoValido(""))
        assertFalse(Validaciones.esDocumentoValido("   "))
    }

    @Test
    fun `cada tipo de documento tiene su formato`() {
        assertTrue(Validaciones.esDocumentoValido("1061702334", TipoDocumento.TI))
        assertFalse(Validaciones.esDocumentoValido("123456", TipoDocumento.TI))
        assertTrue(Validaciones.esDocumentoValido("AB123456", TipoDocumento.PP))
        assertFalse(Validaciones.esDocumentoValido("AB-123456", TipoDocumento.PP))
        assertTrue(Validaciones.esDocumentoValido("900123456", TipoDocumento.NIT))
        assertFalse(Validaciones.esDocumentoValido("90012345A", TipoDocumento.NIT))
    }

    @Test
    fun `al escribir el documento solo entra lo que admite el tipo`() {
        assertEquals("1561", Validaciones.limpiarDocumento("sdscf1ds5ds6c1", TipoDocumento.CC))
        assertEquals("1234567890", Validaciones.limpiarDocumento("123456789012", TipoDocumento.CC))
        assertEquals("AB12", Validaciones.limpiarDocumento("ab-1.2", TipoDocumento.PP))
    }

    @Test
    fun `los nombres solo llevan letras espacios guion o apostrofo`() {
        assertTrue(Validaciones.esNombreValido("María Fernanda"))
        assertTrue(Validaciones.esNombreValido("Ríos Peña"))
        assertTrue(Validaciones.esNombreValido("D'Angelo Pérez-Gómez"))
        assertFalse(Validaciones.esNombreValido("Maria2"))
        assertFalse(Validaciones.esNombreValido("Velasquez.,s"))
        assertFalse(Validaciones.esNombreValido("A"))
        assertFalse(Validaciones.esNombreValido(""))
        assertEquals("Jairo", Validaciones.limpiarNombre("584Jairo"))
        assertEquals("Velasquezs", Validaciones.limpiarNombre("Velasquez.,s65"))
    }

    @Test
    fun `el telefono es opcional pero si viene es celular o fijo de 10 digitos`() {
        assertTrue("vacío es válido: el campo es opcional", Validaciones.esTelefonoValido(""))
        assertTrue(Validaciones.esTelefonoValido("3001234567"))
        assertTrue(Validaciones.esTelefonoValido("6012345678"))
        assertFalse(Validaciones.esTelefonoValido("6012345"))
        assertFalse(Validaciones.esTelefonoValido("1234567890"))
        assertEquals("", Validaciones.limpiarTelefono("saddc"))
    }

    @Test
    fun `textos libres con largo y caracteres permitidos`() {
        assertNull(Validaciones.errorTexto("Calle 5 # 10-20", Validaciones.TextoLibre.DIRECCION))
        assertNull(Validaciones.errorTexto("Nueva EPS", Validaciones.TextoLibre.EPS))
        assertNull(Validaciones.errorTexto("", Validaciones.TextoLibre.OCUPACION))
        assertTrue(Validaciones.errorTexto("ab", Validaciones.TextoLibre.VEREDA) != null)
        assertTrue(Validaciones.errorTexto("12345", Validaciones.TextoLibre.EPS) != null)
        assertEquals("Agricultor", Validaciones.limpiarTexto("Agricultor9", Validaciones.TextoLibre.OCUPACION))
    }

    @Test
    fun `la fecha de nacimiento no puede ser futura`() {
        val ahora = 1_800_000_000_000L
        assertTrue(Validaciones.esFechaNacimientoValida(null, ahora))
        assertTrue(Validaciones.esFechaNacimientoValida(ahora - 1, ahora))
        assertFalse(Validaciones.esFechaNacimientoValida(ahora + 86_400_000L, ahora))
        assertFalse(Validaciones.esFechaNacimientoValida(Validaciones.FECHA_MINIMA - 1, ahora))
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
