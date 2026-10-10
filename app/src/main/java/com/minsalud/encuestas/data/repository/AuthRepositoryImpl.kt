package com.minsalud.encuestas.data.repository

import com.minsalud.encuestas.BuildConfig
import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.data.local.prefs.AlmacenCredenciales
import com.minsalud.encuestas.data.local.prefs.CredencialGuardada
import com.minsalud.encuestas.data.local.prefs.HashCredencial
import com.minsalud.encuestas.data.local.prefs.SessionManager
import com.minsalud.encuestas.data.remote.api.ApiService
import com.minsalud.encuestas.data.remote.dto.LoginRequestDto
import com.minsalud.encuestas.data.remote.dto.RecuperarRequestDto
import com.minsalud.encuestas.data.remote.dto.RespuestaSimpleDto
import com.minsalud.encuestas.data.remote.dto.RestablecerRequestDto
import com.google.gson.Gson
import retrofit2.Response
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.model.Encuestador
import com.minsalud.encuestas.domain.repository.AuthRepository
import java.io.IOException
import javax.inject.Inject

/**
 * Autenticación híbrida (offline-first):
 *
 *  - Con red: valida contra el servidor, guarda el token que exige
 *    /api/personas/sync.php y deja una credencial con hash PBKDF2 para entrar
 *    después sin conexión. Es la única vía para obtener un token nuevo.
 *  - Sin red: valida contra esa credencial guardada, conservando el token
 *    emitido la última vez que hubo conexión.
 *
 * Antes el respaldo sin conexión era una lista escrita en el código con solo la
 * cuenta demo: un encuestador real que cerraba sesión sin señal no podía volver
 * a entrar. La cuenta demo queda solo en compilaciones de depuración.
 */
class AuthRepositoryImpl @Inject constructor(
    private val apiService: ApiService,
    private val sessionManager: SessionManager,
    private val credenciales: AlmacenCredenciales
) : AuthRepository {

    override suspend fun login(numeroDocumento: String, password: String): Result<Encuestador> {
        val doc = numeroDocumento.trim()
        if (doc.isBlank() || password.isBlank()) {
            return Result.Error(DomainError.InvalidData("Documento y contraseña son requeridos"))
        }

        return try {
            val response = apiService.login(LoginRequestDto(doc, password))
            val body = response.body()

            if (response.isSuccessful && body?.success == true && body.encuestador != null) {
                body.token?.let { sessionManager.saveToken(it, body.expiraEn ?: 0L) }
                val sal = HashCredencial.nuevaSal()
                credenciales.guardar(
                    CredencialGuardada(
                        documento = doc,
                        idEncuestador = body.encuestador.id,
                        nombre = body.encuestador.nombre,
                        sal = sal,
                        hash = HashCredencial.calcular(password, sal)
                    )
                )
                Result.Success(
                    Encuestador(
                        id = body.encuestador.id,
                        nombre = body.encuestador.nombre,
                        numeroDocumento = body.encuestador.numeroDocumento
                    )
                )
            } else {
                // El servidor respondió y rechazó las credenciales: su respuesta
                // es autoritativa, no tiene sentido intentar el respaldo local.
                Result.Error(DomainError.InvalidData(body?.message ?: "Documento o contraseña incorrectos"))
            }
        } catch (e: IOException) {
            // Sin conexión: respaldo local.
            loginLocal(doc, password)
        } catch (e: Exception) {
            Result.Error(DomainError.UnknownError(originalError = e))
        }
    }

    /**
     * Revoca el token en el servidor y borra la sesión local.
     *
     * La revocación es en el mejor esfuerzo: si no hay red, la sesión local se
     * cierra igual. La credencial para el login sin conexión se conserva.
     */
    override suspend fun logout() {
        try {
            apiService.logout()
        } catch (e: Exception) {
            // Sin conexión o servidor caído: no impide cerrar sesión.
        } finally {
            sessionManager.clear()
        }
    }

    override suspend fun pedirCodigoRecuperacion(numeroDocumento: String): Result<String> =
        llamarRecuperacion { apiService.pedirCodigoRecuperacion(RecuperarRequestDto(numeroDocumento.trim())) }

    override suspend fun restablecerContrasena(numeroDocumento: String, codigo: String, nueva: String): Result<String> {
        val doc = numeroDocumento.trim()
        val resultado = llamarRecuperacion {
            apiService.restablecerContrasena(RestablecerRequestDto(doc, codigo.trim(), nueva))
        }
        // El login sin conexión debe aceptar la contraseña nueva y no la vieja.
        if (resultado is Result.Success) {
            credenciales.buscar(doc)?.let { vieja ->
                val sal = HashCredencial.nuevaSal()
                credenciales.guardar(vieja.copy(sal = sal, hash = HashCredencial.calcular(nueva, sal)))
            }
        }
        return resultado
    }

    /**
     * Llama a un paso de la recuperación y traduce la respuesta. Los errores
     * (400, 429, 503) traen en el cuerpo un mensaje pensado para mostrarse tal
     * cual: «El código no es válido o ya venció», «Demasiados intentos…».
     */
    private suspend fun llamarRecuperacion(llamada: suspend () -> Response<RespuestaSimpleDto>): Result<String> =
        try {
            val respuesta = llamada()
            val cuerpo = respuesta.body()
            if (respuesta.isSuccessful && cuerpo?.success == true) {
                Result.Success(cuerpo.message ?: "Listo.")
            } else {
                val mensaje = cuerpo?.message ?: runCatching {
                    Gson().fromJson(respuesta.errorBody()?.string(), RespuestaSimpleDto::class.java)?.message
                }.getOrNull()
                Result.Error(DomainError.InvalidData(mensaje ?: "No se pudo completar. Intenta de nuevo."))
            }
        } catch (e: IOException) {
            Result.Error(DomainError.NetworkError("Necesitas señal para recuperar tu contraseña: el código llega por correo."))
        } catch (e: Exception) {
            Result.Error(DomainError.UnknownError(originalError = e))
        }

    private fun loginLocal(documento: String, password: String): Result<Encuestador> {
        val guardada = credenciales.buscar(documento)
        if (guardada != null) {
            return if (HashCredencial.coincide(password, guardada)) {
                Result.Success(Encuestador(guardada.idEncuestador, guardada.nombre, guardada.documento))
            } else {
                Result.Error(DomainError.InvalidData("Documento o contraseña incorrectos"))
            }
        }

        if (BuildConfig.DEBUG && documento == DEMO_DOCUMENTO && password == DEMO_PASSWORD) {
            return Result.Success(Encuestador(1, "Docente Demo", DEMO_DOCUMENTO))
        }

        return Result.Error(
            DomainError.InvalidData(
                "Este teléfono no tiene datos guardados para ese documento. " +
                    "Conéctate e inicia sesión una vez; después podrás entrar sin conexión."
            )
        )
    }

    private companion object {
        // Cuenta de prueba (docente), solo en depuración.
        const val DEMO_DOCUMENTO = "1000000001"
        const val DEMO_PASSWORD = "Demo2026Salud"
    }
}
