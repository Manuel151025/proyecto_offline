package com.minsalud.encuestas.domain.sync

import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * Estas pruebas cubren el punto donde la descarga puede destruir datos sin
 * avisar. Son las mismas situaciones que verifica la PWA en
 * `pwa/tests/mezcla.test.mjs`; si una de las dos cambia de criterio, los
 * clientes empiezan a mostrar cosas distintas.
 */
class DecisionMezclaTest {

    @Test
    fun `persona desconocida en el dispositivo se guarda`() {
        assertEquals(
            DecisionMezcla.GUARDAR,
            decidirMezcla(
                existeEnLocal = false,
                tienePendienteDeEnvio = false,
                updatedAtLocal = 0L,
                updatedAtRemoto = 1_000L
            )
        )
    }

    @Test
    fun `version remota mas reciente reemplaza a la local`() {
        assertEquals(
            DecisionMezcla.GUARDAR,
            decidirMezcla(
                existeEnLocal = true,
                tienePendienteDeEnvio = false,
                updatedAtLocal = 1_000L,
                updatedAtRemoto = 2_000L
            )
        )
    }

    @Test
    fun `version remota mas antigua no pisa la local`() {
        assertEquals(
            DecisionMezcla.CONSERVAR,
            decidirMezcla(
                existeEnLocal = true,
                tienePendienteDeEnvio = false,
                updatedAtLocal = 2_000L,
                updatedAtRemoto = 1_000L
            )
        )
    }

    @Test
    fun `empate conserva la local y evita escrituras inutiles`() {
        assertEquals(
            DecisionMezcla.CONSERVAR,
            decidirMezcla(
                existeEnLocal = true,
                tienePendienteDeEnvio = false,
                updatedAtLocal = 1_500L,
                updatedAtRemoto = 1_500L
            )
        )
    }

    /**
     * El caso que justifica todo lo demás: una encuesta hecha en el campo,
     * todavía en la cola de salida, no puede desaparecer porque el servidor
     * devuelva una versión con fecha mayor. Esa fecha viene del reloj de otro
     * teléfono y no es de fiar.
     */
    @Test
    fun `cambios locales sin enviar sobreviven aunque el remoto parezca mas nuevo`() {
        assertEquals(
            DecisionMezcla.CONSERVAR,
            decidirMezcla(
                existeEnLocal = true,
                tienePendienteDeEnvio = true,
                updatedAtLocal = 1_000L,
                updatedAtRemoto = 9_999_999L
            )
        )
    }

    /**
     * Un dispositivo con el reloj adelantado escribe marcas en el futuro. La
     * regla no las trata distinto a propósito: es el mismo Last-Write-Wins que
     * aplica el servidor, y hacer que los dos lados discrepen sería peor que
     * el problema del reloj.
     */
    @Test
    fun `una marca remota en el futuro gana, igual que en el servidor`() {
        val dentroDeUnAno = System.currentTimeMillis() + 365L * 24 * 60 * 60 * 1000
        assertEquals(
            DecisionMezcla.GUARDAR,
            decidirMezcla(
                existeEnLocal = true,
                tienePendienteDeEnvio = false,
                updatedAtLocal = System.currentTimeMillis(),
                updatedAtRemoto = dentroDeUnAno
            )
        )
    }
}
