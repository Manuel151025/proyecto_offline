package com.minsalud.encuestas.data.repository

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.data.local.prefs.SessionManager
import com.minsalud.encuestas.data.remote.api.ApiService
import com.minsalud.encuestas.data.remote.dto.EncuestadorDto
import com.minsalud.encuestas.data.remote.dto.LoginResponseDto
import com.minsalud.encuestas.domain.model.DomainError
import io.mockk.coEvery
import io.mockk.mockk
import io.mockk.verify
import kotlinx.coroutines.test.runTest
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import retrofit2.Response
import java.io.IOException

/**
 * Autenticación híbrida. Lo que se protege aquí:
 *  - que un login exitoso guarde el token que exige /api/personas/sync.php,
 *  - que un rechazo del servidor NO se pueda sortear con el respaldo local,
 *  - que la caída de red sí permita entrar sin conexión (requisito del proyecto).
 */
class AuthRepositoryImplTest {

    private lateinit var apiService: ApiService
    private lateinit var sessionManager: SessionManager
    private lateinit var repository: AuthRepositoryImpl

    @Before
    fun setUp() {
        apiService = mockk()
        sessionManager = mockk(relaxed = true)
        repository = AuthRepositoryImpl(apiService, sessionManager)
    }

    private fun respuestaOk(token: String? = "token-abc123", expiraEn: Long = 9_999L) =
        Response.success(
            LoginResponseDto(
                success = true,
                message = null,
                token = token,
                expiraEn = expiraEn,
                encuestador = EncuestadorDto(7, "Docente Demo", "1000000001")
            )
        )

    private fun respuestaError(codigo: Int) = Response.error<LoginResponseDto>(
        codigo,
        """{"success":false,"message":"Documento o contraseña incorrectos"}"""
            .toResponseBody("application/json".toMediaTypeOrNull())
    )

    @Test
    fun `login en linea devuelve el encuestador del servidor`() = runTest {
        coEvery { apiService.login(any()) } returns respuestaOk()

        val resultado = repository.login("1000000001", "Demo2026Salud")

        assertTrue(resultado is Result.Success)
        assertEquals(7, (resultado as Result.Success).data.id)
    }

    @Test
    fun `login en linea guarda el token para poder sincronizar despues`() = runTest {
        coEvery { apiService.login(any()) } returns respuestaOk(token = "token-abc123", expiraEn = 4_242L)

        repository.login("1000000001", "Demo2026Salud")

        verify(exactly = 1) { sessionManager.saveToken("token-abc123", 4_242L) }
    }

    @Test
    fun `credenciales rechazadas por el servidor no caen al respaldo local`() = runTest {
        // Aunque la contraseña coincida con la cuenta sembrada localmente, el
        // servidor respondió 401: su respuesta es autoritativa.
        coEvery { apiService.login(any()) } returns respuestaError(401)

        val resultado = repository.login("1000000001", "Demo2026Salud")

        assertTrue(resultado is Result.Error)
        assertTrue((resultado as Result.Error).error is DomainError.InvalidData)
        verify(exactly = 0) { sessionManager.saveToken(any(), any()) }
    }

    @Test
    fun `sin conexion permite entrar con la cuenta sembrada en el dispositivo`() = runTest {
        coEvery { apiService.login(any()) } throws IOException("sin red")

        val resultado = repository.login("1000000001", "Demo2026Salud")

        assertTrue(resultado is Result.Success)
        assertEquals("Docente Demo", (resultado as Result.Success).data.nombre)
    }

    @Test
    fun `sin conexion rechaza una contrasena incorrecta`() = runTest {
        coEvery { apiService.login(any()) } throws IOException("sin red")

        val resultado = repository.login("1000000001", "clave-equivocada")

        assertTrue(resultado is Result.Error)
        assertTrue((resultado as Result.Error).error is DomainError.InvalidData)
    }

    @Test
    fun `sin conexion rechaza un documento desconocido`() = runTest {
        coEvery { apiService.login(any()) } throws IOException("sin red")

        val resultado = repository.login("9999999999", "Demo2026Salud")

        assertTrue(resultado is Result.Error)
    }

    @Test
    fun `rechaza credenciales vacias sin llamar al servidor`() = runTest {
        val resultado = repository.login("", "")

        assertTrue(resultado is Result.Error)
        assertTrue((resultado as Result.Error).error is DomainError.InvalidData)
    }
}
