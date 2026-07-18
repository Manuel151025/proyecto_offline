package com.minsalud.encuestas.presentation.viewmodel

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.data.local.prefs.SessionManager
import com.minsalud.encuestas.domain.usecase.LoginUseCase
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import javax.inject.Inject

data class LoginUiState(
    val documento: String = "",
    val password: String = "",
    val showPassword: Boolean = false,
    val isLoading: Boolean = false,
    val error: String? = null,
    val success: Boolean = false
)

@HiltViewModel
class LoginViewModel @Inject constructor(
    private val loginUseCase: LoginUseCase,
    private val sessionManager: SessionManager
) : ViewModel() {

    private val _uiState = MutableStateFlow(LoginUiState())
    val uiState: StateFlow<LoginUiState> = _uiState.asStateFlow()

    fun onDocumentoChanged(value: String) {
        _uiState.value = _uiState.value.copy(documento = value.filter { it.isDigit() }.take(12), error = null)
    }

    fun onPasswordChanged(value: String) {
        _uiState.value = _uiState.value.copy(password = value, error = null)
    }

    fun onTogglePassword() {
        _uiState.value = _uiState.value.copy(showPassword = !_uiState.value.showPassword)
    }

    fun onLoginClicked() {
        val state = _uiState.value
        if (state.documento.length < 6) {
            _uiState.value = state.copy(error = "Ingresa un documento válido (6–12 dígitos)")
            return
        }
        if (state.password.length < 4) {
            _uiState.value = state.copy(error = "La contraseña debe tener al menos 4 caracteres")
            return
        }

        viewModelScope.launch {
            _uiState.value = state.copy(isLoading = true, error = null)
            when (val result = loginUseCase(state.documento, state.password)) {
                is Result.Success -> {
                    val e = result.data
                    sessionManager.save(e.id, e.nombre, e.numeroDocumento)
                    _uiState.value = _uiState.value.copy(isLoading = false, success = true)
                }
                is Result.Error -> {
                    _uiState.value = _uiState.value.copy(
                        isLoading = false,
                        error = result.error.message ?: "Documento o contraseña incorrectos"
                    )
                }
            }
        }
    }
}
