package com.minsalud.encuestas.domain.catalogo

import com.minsalud.encuestas.domain.model.Eps
import com.minsalud.encuestas.domain.model.Municipio
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

/**
 * El buscador de municipios y EPS, con el catálogo real que trae el APK
 * (src/main/assets/catalogos). Son los mismos casos que pwa/tests/catalogos.test.mjs.
 */
class BuscadorCatalogoTest {

    private val municipios: List<Municipio> by lazy {
        val texto = File("src/main/assets/catalogos/municipios.json").readText(Charsets.UTF_8)
        Regex("""\["(\d{5})","([^"]*)","([^"]*)",([01])]""").findAll(texto).map {
            val (codigo, nombre, depto, principal) = it.destructured
            Municipio(codigo, nombre, depto, principal == "1")
        }.toList()
    }

    private val eps: List<Eps> by lazy {
        val texto = File("src/main/assets/catalogos/eps.json").readText(Charsets.UTF_8)
        Regex(""""nombre":"([^"]*)","detalle":"([^"]*)","tipo":"([^"]*)"""").findAll(texto).map {
            val (nombre, detalle, tipo) = it.destructured
            Eps(nombre, detalle, tipo)
        }.toList()
    }

    private fun primero(q: String) = BuscadorCatalogo.buscarMunicipios(municipios, q).first()

    @Test
    fun `el catalogo trae los 1122 municipios y los 33 departamentos`() {
        assertEquals(1122, municipios.size)
        assertEquals(33, municipios.map { it.departamento }.toSet().size)
        assertEquals(32, municipios.count { it.capital })
    }

    @Test
    fun `nombres con tildes y sin caracteres dañados`() {
        assertTrue(municipios.none { Regex("Ã|Â|â€|�").containsMatchIn(it.nombre + it.departamento) })
        listOf("Medellín", "Popayán", "Itagüí", "San José de Cúcuta", "Bogotá D.C.").forEach { nombre ->
            assertTrue(nombre, municipios.any { it.nombre == nombre })
        }
    }

    @Test
    fun `busca sin tildes ni mayusculas`() {
        assertEquals("popayan", BuscadorCatalogo.normalizar("  POPAYÁN "))
        assertEquals("Popayán", primero("popa").nombre)
        assertEquals("Medellín", primero("medellin").nombre)
        assertEquals("Itagüí", primero("itagui").nombre)
    }

    @Test
    fun `el nombre de uso comun encuentra el oficial`() {
        assertEquals("Santiago de Cali", primero("cali").nombre)
        assertEquals("San José de Cúcuta", primero("cucuta").nombre)
        assertEquals("Bogotá D.C.", primero("bogota").nombre)
    }

    @Test
    fun `el departamento lista sus municipios con la capital primero`() {
        val cauca = BuscadorCatalogo.buscarMunicipios(municipios, "cauca")
        assertEquals("Popayán", cauca.first().nombre)
        assertTrue(cauca.take(42).all { it.departamento == "Cauca" })
    }

    @Test
    fun `sin consulta ofrece las ciudades principales`() {
        val r = BuscadorCatalogo.buscarMunicipios(municipios, "")
        assertTrue(r.isNotEmpty() && r.all { it.principal })
        assertTrue(r.first().capital)
    }

    @Test
    fun `etiqueta al elegir`() {
        assertEquals("Popayán, Cauca", BuscadorCatalogo.etiqueta(primero("popayan")))
        assertEquals("Bogotá D.C.", BuscadorCatalogo.etiqueta(primero("bogota")))
        assertEquals("Arauca, Arauca", BuscadorCatalogo.etiqueta(municipios.first { it.codigo == "81001" }))
    }

    @Test
    fun `eps por nombre o descripcion`() {
        assertTrue(eps.size >= 25)
        assertEquals("EPS Sanitas", BuscadorCatalogo.buscarEps(eps, "sanit").first().nombre)
        assertEquals("Asmet Salud", BuscadorCatalogo.buscarEps(eps, "ASMET").first().nombre)
        assertEquals("Comfachocó", BuscadorCatalogo.buscarEps(eps, "comfachoco").first().nombre)
        assertTrue(BuscadorCatalogo.buscarEps(eps, "indigena").all { it.tipo == "indigena" })
    }
}
