package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.model.Persona
import com.minsalud.encuestas.domain.model.TipoDocumento
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
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * Regla de negocio central del modelo offline-first: eliminar NO borra la fila,
 * hace un borrado lógico (deletedAt). Si se borrara físicamente, el registro
 * volvería a aparecer en la siguiente sincronización desde otro dispositivo.
 */
class EliminarPersonaUseCaseTest {

    private lateinit var personaRepository: PersonaRepository
    private lateinit var timeProvider: TimeProvider
    private lateinit var useCase: EliminarPersonaUseCase

    private val ahora = 1_700_000_000_000L

    @Before
    fun setUp() {
        personaRepository = mockk(relaxed = true)
        timeProvider = mockk()
        every { timeProvider.getCurrentTimeMillis() } returns ahora
        useCase = EliminarPersonaUseCase(personaRepository, timeProvider)
    }

    @Test
    fun `marca deletedAt en lugar de borrar el registro`() = runTest {
        val capturada = slot<Persona>()
        coEvery { personaRepository.getPersona(any(), any()) } returns personaDePrueba()
        coEvery { personaRepository.savePersona(capture(capturada)) } returns Unit

        val resultado = useCase(TipoDocumento.CC, "1098765432")

        assertTrue(resultado is Result.Success)
        assertNotNull(capturada.captured.deletedAt)
        assertEquals(ahora, capturada.captured.deletedAt)
    }

    @Test
    fun `actualiza updatedAt para que el borrado gane el Last-Write-Wins`() = runTest {
        val capturada = slot<Persona>()
        coEvery { personaRepository.getPersona(any(), any()) } returns personaDePrueba(updatedAt = 1L)
        coEvery { personaRepository.savePersona(capture(capturada)) } returns Unit

        useCase(TipoDocumento.CC, "1098765432")

        assertEquals(ahora, capturada.captured.updatedAt)
    }

    @Test
    fun `devuelve NotFound si la persona no existe`() = runTest {
        coEvery { personaRepository.getPersona(any(), any()) } returns null

        val resultado = useCase(TipoDocumento.CC, "0000000000")

        assertTrue(resultado is Result.Error)
        assertTrue((resultado as Result.Error).error is DomainError.NotFound)
        coVerify(exactly = 0) { personaRepository.savePersona(any()) }
    }
}
