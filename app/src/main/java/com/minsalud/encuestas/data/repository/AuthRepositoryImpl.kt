package com.minsalud.encuestas.data.repository

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.model.Encuestador
import com.minsalud.encuestas.domain.repository.AuthRepository
import java.security.MessageDigest
import javax.inject.Inject

/**
 * Autenticación local (offline-first). Valida contra cuentas sembradas en el
 * dispositivo, sin depender del servidor. Las contraseñas se comparan por hash
 * SHA-256 (no se guarda la contraseña en claro).
 */
class AuthRepositoryImpl @Inject constructor() : AuthRepository {

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
        val cuenta = cuentas.find { it.documento == doc }
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
