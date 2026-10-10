package com.minsalud.encuestas.domain.catalogo

import com.minsalud.encuestas.domain.model.Eps
import com.minsalud.encuestas.domain.model.Municipio
import java.text.Normalizer

/**
 * Búsqueda en los catálogos de municipios y EPS, en Kotlin puro.
 *
 * Es el mismo algoritmo que buscarMunicipios/buscarEps de pwa/js/catalogos.js:
 * «popa» → Popayán, «cauca» → los municipios del Cauca con la capital primero,
 * «cali» → Santiago de Cali antes que Calima. Sin tildes ni mayúsculas.
 */
object BuscadorCatalogo {

    /** Código DANE de Bogotá: es a la vez municipio y departamento. */
    const val BOGOTA = "11001"

    private val MARCAS = Regex("\\p{Mn}+")
    private val ESPACIOS = Regex("\\s+")

    fun normalizar(texto: String): String =
        Normalizer.normalize(texto, Normalizer.Form.NFD)
            .replace(MARCAS, "")
            .lowercase()
            .replace(ESPACIOS, " ")
            .trim()

    private fun empiezaPalabra(texto: String, prefijo: String) =
        texto.startsWith(prefijo) || texto.contains(" $prefijo") || texto.contains("-$prefijo")

    fun buscarMunicipios(lista: List<Municipio>, consulta: String, limite: Int = 60): List<Municipio> {
        val q = normalizar(consulta)
        if (q.isEmpty()) {
            return lista.filter { it.principal }
                .sortedWith(compareByDescending<Municipio> { it.capital }.thenBy { normalizar(it.nombre) })
                .take(limite)
        }
        val palabras = q.split(" ")
        return lista.mapNotNull { m ->
            val nombre = normalizar(m.nombre)
            val depto = normalizar(m.departamento)
            val texto = "$nombre $depto"
            if (!palabras.all { texto.contains(it) }) return@mapNotNull null
            val puntaje = when {
                nombre == q -> 0
                depto == q -> 1
                nombre.split(' ', '-').contains(q) -> 2
                nombre.startsWith(q) -> 3
                empiezaPalabra(nombre, q) -> 4
                palabras.all { empiezaPalabra(nombre, it) } -> 5
                depto.startsWith(q) || palabras.all { empiezaPalabra(depto, it) || empiezaPalabra(nombre, it) } -> 6
                else -> 7
            }
            Triple(m, puntaje, nombre)
        }.sortedWith(
            compareBy<Triple<Municipio, Int, String>> { it.second }
                .thenByDescending { it.first.capital }
                .thenByDescending { it.first.principal }
                .thenBy { it.third }
        ).take(limite).map { it.first }
    }

    fun buscarEps(lista: List<Eps>, consulta: String, limite: Int = 40): List<Eps> {
        val q = normalizar(consulta)
        if (q.isEmpty()) return lista.take(limite)
        val palabras = q.split(" ")
        return lista.mapNotNull { e ->
            val nombre = normalizar(e.nombre)
            val texto = "$nombre ${normalizar(e.detalle)}"
            if (!palabras.all { texto.contains(it) }) return@mapNotNull null
            val puntaje = when {
                nombre.startsWith(q) -> 0
                empiezaPalabra(nombre, q) -> 1
                nombre.contains(q) -> 2
                else -> 3
            }
            e to puntaje
        }.sortedBy { it.second }.take(limite).map { it.first }
    }

    /** Texto que queda en el campo al elegir: «Popayán, Cauca», «Bogotá D.C.». */
    fun etiqueta(m: Municipio): String =
        if (m.codigo == BOGOTA) m.nombre else "${m.nombre}, ${m.departamento}"
}
