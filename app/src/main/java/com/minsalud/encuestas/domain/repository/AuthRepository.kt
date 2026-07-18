package com.minsalud.encuestas.domain.repository

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.Encuestador

interface AuthRepository {
    suspend fun login(numeroDocumento: String, password: String): Result<Encuestador>
}
