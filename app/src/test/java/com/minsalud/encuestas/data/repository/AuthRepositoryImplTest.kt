package com.minsalud.encuestas.data.repository

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.data.local.prefs.AlmacenCredenciales
import com.minsalud.encuestas.data.local.prefs.CredencialGuardada
import com.minsalud.encuestas.data.local.prefs.SessionManager
import com.minsalud.encuestas.data.remote.api.ApiService
import com.minsalud.encuestas.data.remote.dto.EncuestadorDto
import com.minsalud.encuestas.data.remote.dto.LoginResponseDto
import com.minsalud.encuestas.data.remote.dto.RespuestaSimpleDto
import com.minsalud.encuestas.data.local.prefs.HashCredencial
import com.minsalud.encuestas.domain.model.DomainError
import io.mockk.coEvery
import io.mockk.mockk
import io.mockk.verify
import kotlinx.coroutines.test.runTest
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
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
    private lateinit var credenciales: CredencialesEnMemoria

    /** Almacén falso: lo que importa es qué se guarda, no SharedPreferences. */
    private class CredencialesEnMemoria : AlmacenCredenciales {
        val guardadas = mutableMapOf<String, CredencialGuardada>()
        override fun guardar(credencial: CredencialGuardada) { guardadas[credencial.documento] = credencial }
        override fun buscar(documento: String) = guardadas[documento]
    }

    @Before
    fun setUp() {
        apiService = mockk()
        sessionManager = mockk(relaxed = true)
        credenciales = CredencialesEnMemoria()
        repository = AuthRepositoryImpl(apiService, sessionManager, credenciales)
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

    /**
     * El fallo que motivó el cambio: el respaldo sin conexión era una lista
     * escrita en el código con solo la cuenta demo. Una cuenta real creada en
     * el panel no podía volver a entrar sin señal.
     */
    @Test
    fun `una cuenta real entra sin conexion despues de un login en linea`() = runTest {
        coEvery { apiService.login(any()) } returns Response.success(
            LoginResponseDto(true, null, "tok", 9_999L, EncuestadorDto(42, "Yesenia Palacios", "1077123456"))
        )
        repository.login("1077123456", "ClaveDeCampo2026")

        coEvery { apiService.login(any()) } throws IOException("sin red")
        val resultado = repository.login("1077123456", "ClaveDeCampo2026")

        assertTrue(resultado is Result.Success)
        assertEquals(42, (resultado as Result.Success).data.id)
        assertEquals("Yesenia Palacios", resultado.data.nombre)
    }

    @Test
    fun `sin conexion una cuenta real rechaza la clave equivocada`() = runTest {
        coEvery { apiService.login(any()) } returns Response.success(
            LoginResponseDto(true, null, "tok", 9_999L, EncuestadorDto(42, "Yesenia Palacios", "1077123456"))
        )
        repository.login("1077123456", "ClaveDeCampo2026")
        coEvery { apiService.login(any()) } throws IOException("sin red")

        assertTrue(repository.login("1077123456", "otra-clave") is Result.Error)
    }

    @Test
    fun `la credencial guardada no contiene la contrasena`() = runTest {
        coEvery { apiService.login(any()) } returns respuestaOk()

        repository.login("1000000001", "Demo2026Salud")

        val guardada = credenciales.guardadas.getValue("1000000001")
        assertFalse(guardada.hash.contains("Demo2026Salud"))
        assertEquals(64, guardada.hash.length) // 256 bits en hexadecimal
    }

    @Test
    fun `pedir el codigo devuelve el mensaje del servidor`() = runTest {
        coEvery { apiService.pedirCodigoRecuperacion(any()) } returns
            Response.success(RespuestaSimpleDto(true, "Si tu cuenta tiene un correo registrado, te enviamos un código"))

        val r = repository.pedirCodigoRecuperacion("1077123456")

        assertTrue(r is Result.Success)
        assertTrue((r as Result.Success).data.contains("código"))
    }

    @Test
    fun `un codigo invalido muestra el mensaje del error del servidor`() = runTest {
        coEvery { apiService.restablecerContrasena(any()) } returns Response.error(
            400, """{"success":false,"message":"El código no es válido o ya venció. Pide uno nuevo."}"""
                .toResponseBody("application/json".toMediaTypeOrNull())
        )

        val r = repository.restablecerContrasena("1077123456", "000000", "ClaveNueva2026")

        assertTrue(r is Result.Error)
        assertEquals("El código no es válido o ya venció. Pide uno nuevo.", (r as Result.Error).error.message)
    }

    @Test
    fun `sin red la recuperacion explica que necesita senal`() = runTest {
        coEvery { apiService.pedirCodigoRecuperacion(any()) } throws IOException("sin red")

        val r = repository.pedirCodigoRecuperacion("1077123456")

        assertTrue((r as Result.Error).error is DomainError.NetworkError)
        assertTrue(r.error.message!!.contains("señal"))
    }

    @Test
    fun `tras cambiar la contrasena el login sin red acepta la nueva y no la vieja`() = runTest {
        coEvery { apiService.login(any()) } returns respuestaOk()
        repository.login("1000000001", "ClaveVieja2026")
        coEvery { apiService.restablecerContrasena(any()) } returns
            Response.success(RespuestaSimpleDto(true, "Listo."))

        repository.restablecerContrasena("1000000001", "123456", "ClaveNueva2026")

        val guardada = credenciales.guardadas.getValue("1000000001")
        assertTrue(HashCredencial.coincide("ClaveNueva2026", guardada))
        assertFalse(HashCredencial.coincide("ClaveVieja2026", guardada))
    }

    @Test
    fun `rechaza credenciales vacias sin llamar al servidor`() = runTest {
        val resultado = repository.login("", "")

        assertTrue(resultado is Result.Error)
        assertTrue((resultado as Result.Error).error is DomainError.InvalidData)
    }
}
