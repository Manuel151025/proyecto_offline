package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.repository.SyncRepository
import io.mockk.coEvery
import io.mockk.coVerify
import io.mockk.mockk
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * Manejo de errores de sincronización. La distinción importa: un fallo de red es
 * reintentable y la cola debe conservarse, mientras que un 4xx (por ejemplo, token
 * vencido o payload inválido) requiere intervención del usuario.
 */
class SincronizarPendientesUseCaseTest {

    private lateinit var syncRepository: SyncRepository
    private lateinit var useCase: SincronizarPendientesUseCase

    @Before
    fun setUp() {
        syncRepository = mockk(relaxed = true)
        useCase = SincronizarPendientesUseCase(syncRepository)
    }

    @Test
    fun `sincroniza y reporta exito cuando no hay fallos`() = runTest {
        coEvery { syncRepository.sincronizarPendientes() } returns Unit

        val resultado = useCase()

        assertTrue(resultado is Result.Success)
        coVerify(exactly = 1) { syncRepository.sincronizarPendientes() }
    }

    @Test
    fun `conserva el tipo NetworkError para que la cola se reintente`() = runTest {
        coEvery { syncRepository.sincronizarPendientes() } throws
            DomainError.NetworkError("Existen fallos de red por reintentar")

        val resultado = useCase()

        assertTrue(resultado is Result.Error)
        assertTrue((resultado as Result.Error).error is DomainError.NetworkError)
    }

    @Test
    fun `conserva el tipo InvalidData ante errores 4xx del servidor`() = runTest {
        coEvery { syncRepository.sincronizarPendientes() } throws
            DomainError.InvalidData("Token inválido")

        val resultado = useCase()

        assertTrue(resultado is Result.Error)
        assertTrue((resultado as Result.Error).error is DomainError.InvalidData)
    }

    @Test
    fun `envuelve excepciones inesperadas en UnknownError`() = runTest {
        coEvery { syncRepository.sincronizarPendientes() } throws IllegalStateException("boom")

        val resultado = useCase()

        assertTrue(resultado is Result.Error)
        assertTrue((resultado as Result.Error).error is DomainError.UnknownError)
    }
}
