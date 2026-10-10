package com.minsalud.encuestas.domain.repository

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.Encuestador

interface AuthRepository {
    suspend fun login(numeroDocumento: String, password: String): Result<Encuestador>

    /** Revoca el token en el servidor y borra la sesión local. */
    suspend fun logout()

    /**
     * «¿Olvidaste tu contraseña?», paso 1: el servidor envía un código al
     * correo de la cuenta. Devuelve el mensaje para mostrar (es el mismo
     * exista o no la cuenta).
     */
    suspend fun pedirCodigoRecuperacion(numeroDocumento: String): Result<String>

    /** Paso 2: con el código, fija la contraseña nueva. Devuelve el mensaje del servidor. */
    suspend fun restablecerContrasena(numeroDocumento: String, codigo: String, nueva: String): Result<String>
}
