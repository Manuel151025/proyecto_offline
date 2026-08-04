package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.model.Persona
import com.minsalud.encuestas.domain.repository.PersonaRepository
import com.minsalud.encuestas.domain.util.TimeProvider
import com.minsalud.encuestas.testutil.personaDePrueba
import io.mockk.coEvery
import io.mockk.coVerify
import io.mockk.every
import io.mockk.mockk
import io.mockk.slot
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import java.io.IOException

/**
 * Validaciones y reglas de negocio al guardar una persona.
 * Cubre: validación de campos obligatorios, sellado de updatedAt (clave para el
 * algoritmo Last-Write-Wins del servidor) y traducción de excepciones a DomainError.
 */
class GuardarPersonaUseCaseTest {

    private lateinit var personaRepository: PersonaRepository
    private lateinit var timeProvider: TimeProvider
    private lateinit var useCase: GuardarPersonaUseCase

    private val ahora = 1_700_000_000_000L

    @Before
    fun setUp() {
        personaRepository = mockk(relaxed = true)
        timeProvider = mockk()
        every { timeProvider.getCurrentTimeMillis() } returns ahora
        useCase = GuardarPersonaUseCase(personaRepository, timeProvider)
    }

    @Test
    fun `guarda la persona cuando los datos son validos`() = runTest {
        val resultado = useCase(personaDePrueba())

        assertTrue(resultado is Result.Success)
        coVerify(exactly = 1) { personaRepository.savePersona(any()) }
    }

    @Test
    fun `sella updatedAt con la hora actual para que el LWW del servidor funcione`() = runTest {
        val capturada = slot<Persona>()
        coEvery { personaRepository.savePersona(capture(capturada)) } returns Unit

        // La persona llega con un updatedAt viejo; el caso de uso debe reemplazarlo.
        useCase(personaDePrueba(updatedAt = 1L))

        assertEquals(ahora, capturada.captured.updatedAt)
    }

    @Test
    fun `rechaza persona sin numero de documento`() = runTest {
        val resultado = useCase(personaDePrueba(numeroDocumento = ""))

        assertTrue(resultado is Result.Error)
        assertTrue((resultado as Result.Error).error is DomainError.InvalidData)
        coVerify(exactly = 0) { personaRepository.savePersona(any()) }
    }

    @Test
    fun `rechaza persona sin nombres`() = runTest {
        val resultado = useCase(personaDePrueba(nombres = "   "))

        assertTrue(resultado is Result.Error)
        coVerify(exactly = 0) { personaRepository.savePersona(any()) }
    }

    @Test
    fun `rechaza persona sin apellidos`() = runTest {
        val resultado = useCase(personaDePrueba(apellidos = ""))

        assertTrue(resultado is Result.Error)
        coVerify(exactly = 0) { personaRepository.savePersona(any()) }
    }

    @Test
    fun `traduce un fallo de persistencia a UnknownError sin propagar la excepcion`() = runTest {
        coEvery { personaRepository.savePersona(any()) } throws IOException("disco lleno")

        val resultado = useCase(personaDePrueba())

        assertTrue(resultado is Result.Error)
        assertTrue((resultado as Result.Error).error is DomainError.UnknownError)
    }
}
