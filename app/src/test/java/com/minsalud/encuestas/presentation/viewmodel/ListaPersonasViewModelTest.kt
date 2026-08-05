package com.minsalud.encuestas.presentation.viewmodel

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.data.local.prefs.SessionManager
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.model.Persona
import com.minsalud.encuestas.domain.usecase.ObtenerPendientesUseCase
import com.minsalud.encuestas.domain.usecase.ObtenerPersonasUseCase
import com.minsalud.encuestas.testutil.MainDispatcherRule
import com.minsalud.encuestas.testutil.personaDePrueba
import io.mockk.every
import io.mockk.mockk
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test

/**
 * Estado de la lista de personas.
 *
 * Lo que aquí importa es la insignia de sincronización: combina dos flujos
 * independientes (las personas y las claves pendientes) y una equivocación al
 * cruzarlos haría que el encuestador crea sincronizado algo que sigue en la
 * cola, o al revés. Es información en la que basa su trabajo.
 */
class ListaPersonasViewModelTest {

    @get:Rule
    val mainDispatcherRule = MainDispatcherRule()

    private lateinit var obtenerPersonas: ObtenerPersonasUseCase
    private lateinit var obtenerPendientes: ObtenerPendientesUseCase
    private lateinit var sessionManager: SessionManager

    @Before
    fun setUp() {
        obtenerPersonas = mockk()
        obtenerPendientes = mockk()
        sessionManager = mockk(relaxed = true)
        every { sessionManager.nombre() } returns "Docente Demo"
    }

    private fun crear() = ListaPersonasViewModel(obtenerPersonas, obtenerPendientes, sessionManager)

    /** Clave con la que el ViewModel cruza ambos flujos. */
    private fun clave(p: Persona) = "${p.tipoDocumento.name}|${p.numeroDocumento}"

    @Test
    fun `marca como pendiente solo a quien esta en la cola`() = runTest {
        val enCola = personaDePrueba(numeroDocumento = "111")
        val sincronizada = personaDePrueba(numeroDocumento = "222")

        every { obtenerPersonas() } returns flowOf(Result.Success(listOf(enCola, sincronizada)))
        every { obtenerPendientes() } returns flowOf(listOf(clave(enCola)))

        val vm = crear()
        val estado = vm.uiState.value

        assertEquals(2, estado.personas.size)
        assertTrue("la que está en la cola debe salir pendiente",
            estado.personas.first { it.persona.numeroDocumento == "111" }.pendiente)
        assertFalse("la que no está en la cola no debe salir pendiente",
            estado.personas.first { it.persona.numeroDocumento == "222" }.pendiente)
    }

    @Test
    fun `sin pendientes ninguna aparece marcada`() = runTest {
        every { obtenerPersonas() } returns flowOf(
            Result.Success(listOf(personaDePrueba(numeroDocumento = "111")))
        )
        every { obtenerPendientes() } returns flowOf(emptyList())

        val estado = crear().uiState.value

        assertTrue(estado.personas.none { it.pendiente })
    }

    @Test
    fun `la clave distingue el tipo de documento`() = runTest {
        // Mismo número, distinto tipo: son personas diferentes y no deben
        // contagiarse el estado de pendiente.
        val cc = personaDePrueba(
            tipoDocumento = com.minsalud.encuestas.domain.model.TipoDocumento.CC,
            numeroDocumento = "999"
        )
        val ti = personaDePrueba(
            tipoDocumento = com.minsalud.encuestas.domain.model.TipoDocumento.TI,
            numeroDocumento = "999"
        )

        every { obtenerPersonas() } returns flowOf(Result.Success(listOf(cc, ti)))
        every { obtenerPendientes() } returns flowOf(listOf(clave(cc)))

        val estado = crear().uiState.value

        assertTrue(estado.personas.first { it.persona.tipoDocumento.name == "CC" }.pendiente)
        assertFalse(estado.personas.first { it.persona.tipoDocumento.name == "TI" }.pendiente)
    }

    @Test
    fun `deja de cargar cuando llegan los datos`() = runTest {
        every { obtenerPersonas() } returns flowOf(Result.Success(emptyList()))
        every { obtenerPendientes() } returns flowOf(emptyList())

        assertFalse(crear().uiState.value.isLoading)
    }

    @Test
    fun `un error se refleja en el estado y no deja la pantalla cargando`() = runTest {
        every { obtenerPersonas() } returns flowOf(
            Result.Error(DomainError.UnknownError("Base de datos corrupta"))
        )
        every { obtenerPendientes() } returns flowOf(emptyList())

        val estado = crear().uiState.value

        assertNotNull(estado.errorMessage)
        assertFalse("dejarla cargando mostraría un spinner eterno", estado.isLoading)
    }

    @Test
    fun `expone el nombre del encuestador para el saludo`() = runTest {
        every { obtenerPersonas() } returns flowOf(Result.Success(emptyList()))
        every { obtenerPendientes() } returns flowOf(emptyList())

        assertEquals("Docente Demo", crear().uiState.value.nombreEncuestador)
    }
}
