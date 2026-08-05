package com.minsalud.encuestas.data.repository

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.data.local.prefs.SessionManager
import com.minsalud.encuestas.data.remote.api.ApiService
import com.minsalud.encuestas.data.remote.dto.LoginRequestDto
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.model.Encuestador
import com.minsalud.encuestas.domain.repository.AuthRepository
import java.io.IOException
import java.security.MessageDigest
import javax.inject.Inject

/**
 * Autenticación híbrida (offline-first):
 *
 *  - Con red: valida contra el servidor y guarda el token que exige
 *    /api/personas/sync.php. Es la única vía para obtener un token nuevo.
 *  - Sin red: cae al respaldo local sembrado en el dispositivo, conservando
 *    el token emitido la última vez que hubo conexión. Así el encuestador
 *    entra en campo y la cola se envía al recuperar la señal.
 *
 * Las contraseñas del respaldo local se comparan por hash SHA-256; nunca se
 * guarda la contraseña en claro.
 */
class AuthRepositoryImpl @Inject constructor(
    private val apiService: ApiService,
    private val sessionManager: SessionManager
) : AuthRepository {

    private data class Cuenta(
        val id: Int,
        val nombre: String,
        val documento: String,
        val passHash: String
    )

    // Cuenta de prueba (docente): 1000000001 / Demo2026Salud
    private val cuentas = listOf(
        Cuenta(1, "Docente Demo", "1000000001", sha256("Demo2026Salud"))
    )

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
     * cierra igual. Dejar al encuestador dentro de la app porque no había señal
     * sería peor que no revocar; el token caducará solo por vigencia.
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

    private fun loginLocal(documento: String, password: String): Result<Encuestador> {
        val cuenta = cuentas.find { it.documento == documento }
        return if (cuenta != null && cuenta.passHash == sha256(password)) {
            Result.Success(Encuestador(cuenta.id, cuenta.nombre, cuenta.documento))
        } else {
            Result.Error(DomainError.InvalidData("Documento o contraseña incorrectos"))
        }
    }

    private fun sha256(text: String): String =
        MessageDigest.getInstance("SHA-256")
            .digest(text.toByteArray())
            .joinToString("") { "%02x".format(it) }
}
