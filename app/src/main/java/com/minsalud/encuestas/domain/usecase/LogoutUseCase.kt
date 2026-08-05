package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.domain.repository.AuthRepository

/**
 * Cierra la sesión: revoca el token en el servidor y limpia la local.
 *
 * Antes la pantalla llamaba directamente a SessionManager.clear(), de modo que
 * el token seguía siendo válido en el servidor durante sus 30 días de vigencia.
 */
class LogoutUseCase(
    private val authRepository: AuthRepository
) {
    suspend operator fun invoke() = authRepository.logout()
}
