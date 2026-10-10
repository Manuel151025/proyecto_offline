package com.minsalud.encuestas.presentation.viewmodel

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.repository.AuthRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import javax.inject.Inject

/** Los tres pasos de «¿Olvidaste tu contraseña?». */
enum class PasoRecuperacion { DOCUMENTO, CODIGO, LISTO }

data class RecuperarUiState(
    val paso: PasoRecuperacion = PasoRecuperacion.DOCUMENTO,
    val documento: String = "",
    val codigo: String = "",
    val nueva: String = "",
    val confirmar: String = "",
    val verClave: Boolean = false,
    val mensaje: String? = null,
    val error: String? = null,
    val cargando: Boolean = false
)

/**
 * Mismo flujo que pwa/js/screens/recuperar.js: documento → código del correo
 * y contraseña nueva → listo. Necesita señal: el código lo envía y lo
 * comprueba el servidor.
 */
@HiltViewModel
class RecuperarViewModel @Inject constructor(
    private val authRepository: AuthRepository,
    savedStateHandle: SavedStateHandle
) : ViewModel() {

    private val _uiState = MutableStateFlow(
        RecuperarUiState(documento = savedStateHandle.get<String>("doc").orEmpty().filter { it.isDigit() }.take(12))
    )
    val uiState: StateFlow<RecuperarUiState> = _uiState.asStateFlow()

    private fun actualizar(cambio: RecuperarUiState.() -> RecuperarUiState) {
        _uiState.value = _uiState.value.cambio()
    }

    fun onDocumento(v: String) = actualizar { copy(documento = v.filter { it.isDigit() }.take(12), error = null) }
    fun onCodigo(v: String) = actualizar { copy(codigo = v.filter { it.isDigit() }.take(6), error = null) }
    fun onNueva(v: String) = actualizar { copy(nueva = v, error = null) }
    fun onConfirmar(v: String) = actualizar { copy(confirmar = v, error = null) }
    fun onVerClave() = actualizar { copy(verClave = !verClave) }
    fun onOtroDocumento() = actualizar { copy(paso = PasoRecuperacion.DOCUMENTO, codigo = "", error = null) }

    fun pedirCodigo() {
        val doc = _uiState.value.documento
        if (doc.length !in 6..12) {
            actualizar { copy(error = "El documento debe tener entre 6 y 12 dígitos.") }
            return
        }
        viewModelScope.launch {
            actualizar { copy(cargando = true, error = null) }
            when (val r = authRepository.pedirCodigoRecuperacion(doc)) {
                is Result.Success -> actualizar { copy(cargando = false, paso = PasoRecuperacion.CODIGO, mensaje = r.data) }
                is Result.Error -> actualizar { copy(cargando = false, error = r.error.message) }
            }
        }
    }

    fun cambiarContrasena() {
        val s = _uiState.value
        val error = when {
            s.codigo.length != 6 -> "El código tiene 6 dígitos. Cópialo del correo."
            s.nueva.length < MIN_CLAVE -> "La contraseña nueva debe tener al menos $MIN_CLAVE caracteres."
            s.nueva != s.confirmar -> "Las dos contraseñas no coinciden."
            else -> null
        }
        if (error != null) {
            actualizar { copy(error = error) }
            return
        }
        viewModelScope.launch {
            actualizar { copy(cargando = true, error = null) }
            when (val r = authRepository.restablecerContrasena(s.documento, s.codigo, s.nueva)) {
                is Result.Success -> actualizar {
                    copy(cargando = false, paso = PasoRecuperacion.LISTO, nueva = "", confirmar = "", codigo = "")
                }
                is Result.Error -> actualizar { copy(cargando = false, error = r.error.message) }
            }
        }
    }

    companion object {
        /** La misma política que api/politica.php. */
        const val MIN_CLAVE = 10
    }
}
