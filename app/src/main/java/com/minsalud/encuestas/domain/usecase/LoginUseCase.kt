package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.Encuestador
import com.minsalud.encuestas.domain.repository.AuthRepository

class LoginUseCase(
    private val authRepository: AuthRepository
) {
    suspend operator fun invoke(numeroDocumento: String, password: String): Result<Encuestador> =
        authRepository.login(numeroDocumento, password)
}
