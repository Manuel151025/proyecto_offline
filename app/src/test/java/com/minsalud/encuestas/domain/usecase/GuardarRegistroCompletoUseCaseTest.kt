package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.ColaSincronizacion
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.model.EstadoSync
import com.minsalud.encuestas.domain.repository.EncuestaRepository
import com.minsalud.encuestas.domain.repository.PersonaRepository
import com.minsalud.encuestas.domain.repository.SyncRepository
import com.minsalud.encuestas.domain.util.TimeProvider
import com.minsalud.encuestas.domain.util.TransactionRunner
import com.minsalud.encuestas.testutil.encuestaDePrueba
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

/**
 * El registro completo es la operación crítica de la app: persona + encuesta +
 * entrada en la cola de sincronización deben ocurrir como una sola unidad. Si la
 * cola no se escribe, el dato queda solo en el dispositivo y nunca llega al servidor.
 */
class GuardarRegistroCompletoUseCaseTest {

    private lateinit var personaRepository: PersonaRepository
    private lateinit var encuestaRepository: EncuestaRepository
    private lateinit var syncRepository: SyncRepository
    private lateinit var timeProvider: TimeProvider
    private lateinit var transactionRunner: TransactionRunner
    private lateinit var useCase: GuardarRegistroCompletoUseCase

    private val ahora = 1_700_000_000_000L

    @Before
    fun setUp() {
        personaRepository = mockk(relaxed = true)
        encuestaRepository = mockk(relaxed = true)
        syncRepository = mockk(relaxed = true)
        timeProvider = mockk()
        every { timeProvider.getCurrentTimeMillis() } returns ahora

        // El runner real delega en una transacción de Room; aquí simplemente
        // ejecutamos el bloque para verificar que todo se invoca dentro de él.
        transactionRunner = mockk()
        coEvery { transactionRunner.invoke<Any>(any()) } coAnswers {
            val bloque = firstArg<suspend () -> Any>()
            bloque()
        }

        useCase = GuardarRegistroCompletoUseCase(
            personaRepository, encuestaRepository, syncRepository, timeProvider, transactionRunner
        )
    }

    @Test
    fun `persiste persona encuesta y cola dentro de la misma transaccion`() = runTest {
        val resultado = useCase(personaDePrueba(), encuestaDePrueba())

        assertTrue(resultado is Result.Success)
        coVerify(exactly = 1) { personaRepository.savePersona(any()) }
        coVerify(exactly = 1) { encuestaRepository.saveEncuesta(any()) }
        coVerify(exactly = 1) { syncRepository.addToOutbox(any()) }
        coVerify(exactly = 1) { transactionRunner.invoke<Any>(any()) }
    }

    @Test
    fun `encola el registro en estado PENDING y sin intentos previos`() = runTest {
        val enCola = slot<ColaSincronizacion>()
        coEvery { syncRepository.addToOutbox(capture(enCola)) } returns Unit

        useCase(personaDePrueba(), encuestaDePrueba(id = "enc-abc"))

        assertEquals(EstadoSync.PENDING, enCola.captured.estado)
        assertEquals("enc-abc", enCola.captured.idEncuesta)
        assertEquals(0, enCola.captured.intentos)
    }

    @Test
    fun `rechaza el registro si la encuesta no trae id`() = runTest {
        val resultado = useCase(personaDePrueba(), encuestaDePrueba(id = ""))

        assertTrue(resultado is Result.Error)
        assertTrue((resultado as Result.Error).error is DomainError.InvalidData)
        coVerify(exactly = 0) { personaRepository.savePersona(any()) }
        coVerify(exactly = 0) { syncRepository.addToOutbox(any()) }
    }

    @Test
    fun `rechaza el registro si la persona no trae campos obligatorios`() = runTest {
        val resultado = useCase(personaDePrueba(nombres = ""), encuestaDePrueba())

        assertTrue(resultado is Result.Error)
        assertTrue((resultado as Result.Error).error is DomainError.InvalidData)
        coVerify(exactly = 0) { syncRepository.addToOutbox(any()) }
    }

    @Test
    fun `no deja la encuesta encolada si la persistencia falla`() = runTest {
        coEvery { personaRepository.savePersona(any()) } throws RuntimeException("Room cerrado")

        val resultado = useCase(personaDePrueba(), encuestaDePrueba())

        assertTrue(resultado is Result.Error)
        coVerify(exactly = 0) { syncRepository.addToOutbox(any()) }
    }
}
