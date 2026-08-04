package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.repository.EncuestaRepository
import com.minsalud.encuestas.testutil.encuestaDePrueba
import io.mockk.coEvery
import io.mockk.coVerify
import io.mockk.mockk
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * Validaciones al registrar una encuesta. El id es un UUID generado en el
 * dispositivo y es la clave primaria en el servidor: sin él, el INSERT IGNORE
 * de sync.php no puede garantizar idempotencia.
 */
class RegistrarEncuestaUseCaseTest {

    private lateinit var encuestaRepository: EncuestaRepository
    private lateinit var useCase: RegistrarEncuestaUseCase

    @Before
    fun setUp() {
        encuestaRepository = mockk(relaxed = true)
        useCase = RegistrarEncuestaUseCase(encuestaRepository)
    }

    @Test
    fun `registra la encuesta cuando los datos son validos`() = runTest {
        val resultado = useCase(encuestaDePrueba())

        assertTrue(resultado is Result.Success)
        coVerify(exactly = 1) { encuestaRepository.saveEncuesta(any()) }
    }

    @Test
    fun `rechaza encuesta sin id`() = runTest {
        val resultado = useCase(encuestaDePrueba(id = ""))

        assertTrue(resultado is Result.Error)
        assertTrue((resultado as Result.Error).error is DomainError.InvalidData)
        coVerify(exactly = 0) { encuestaRepository.saveEncuesta(any()) }
    }

    @Test
    fun `rechaza encuesta sin numero de documento`() = runTest {
        val resultado = useCase(encuestaDePrueba(numeroDocumento = ""))

        assertTrue(resultado is Result.Error)
        coVerify(exactly = 0) { encuestaRepository.saveEncuesta(any()) }
    }

    @Test
    fun `traduce fallos de persistencia a UnknownError`() = runTest {
        coEvery { encuestaRepository.saveEncuesta(any()) } throws RuntimeException("Room cerrado")

        val resultado = useCase(encuestaDePrueba())

        assertTrue(resultado is Result.Error)
        assertTrue((resultado as Result.Error).error is DomainError.UnknownError)
    }
}
