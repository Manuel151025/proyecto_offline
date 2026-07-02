package com.minsalud.encuestas.presentation.viewmodel

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.*
import com.minsalud.encuestas.domain.usecase.GuardarRegistroCompletoUseCase
import com.minsalud.encuestas.domain.usecase.ObtenerMunicipiosUseCase
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import java.util.UUID
import javax.inject.Inject

data class FormularioUiState(
    val isLoading: Boolean = false,
    val isSuccess: Boolean = false,
    val errorMessage: String? = null,
    val municipios: List<Municipio> = emptyList(),
    
    // Campos del formulario
    val tipoDocumento: TipoDocumento = TipoDocumento.CC,
    val numeroDocumento: String = "",
    val nombres: String = "",
    val apellidos: String = "",
    val idEncuestador: Int = 1 // Usuario por defecto local
)

@HiltViewModel
class FormularioEncuestaViewModel @Inject constructor(
    private val guardarRegistroCompletoUseCase: GuardarRegistroCompletoUseCase,
    private val obtenerMunicipiosUseCase: ObtenerMunicipiosUseCase
) : ViewModel() {

    private val _uiState = MutableStateFlow(FormularioUiState())
    val uiState: StateFlow<FormularioUiState> = _uiState.asStateFlow()

    init {
        cargarMunicipios()
    }

    private fun cargarMunicipios() {
        viewModelScope.launch {
            obtenerMunicipiosUseCase().collect { result ->
                if (result is Result.Success) {
                    _uiState.value = _uiState.value.copy(municipios = result.data)
                }
            }
        }
    }

    fun onTipoDocumentoChanged(tipo: TipoDocumento) {
        _uiState.value = _uiState.value.copy(tipoDocumento = tipo)
    }

    fun onNumeroDocumentoChanged(numero: String) {
        _uiState.value = _uiState.value.copy(numeroDocumento = numero)
    }

    fun onNombresChanged(nombres: String) {
        _uiState.value = _uiState.value.copy(nombres = nombres)
    }

    fun onApellidosChanged(apellidos: String) {
        _uiState.value = _uiState.value.copy(apellidos = apellidos)
    }

    fun onGuardarClicked() {
        viewModelScope.launch {
            _uiState.value = _uiState.value.copy(isLoading = true, errorMessage = null)
            val state = _uiState.value
            
            // Modelo puro mapeado del estado actual del formulario
            val persona = Persona(
                tipoDocumento = state.tipoDocumento,
                numeroDocumento = state.numeroDocumento,
                nombres = state.nombres,
                apellidos = state.apellidos,
                fechaNacimiento = null,
                telefono = null,
                email = null,
                direccion = null,
                eps = null,
                ocupacion = null,
                estrato = null,
                municipioCodigo = null,
                updatedAt = 0L, // El UseCase transaccional definirÃ¡ el tiempo correcto
                deviceId = "DEVICE_ID_LOCAL",
                deletedAt = null
            )

            val encuesta = Encuesta(
                id = UUID.randomUUID().toString(),
                tipoDocumento = state.tipoDocumento,
                numeroDocumento = state.numeroDocumento,
                idEncuestador = state.idEncuestador,
                fechaEncuesta = 0L, // Se sobreescribe en el UseCase transaccional
                fechaSincronizacion = null,
                deviceId = "DEVICE_ID_LOCAL",
                accion = AccionEncuesta.CREACION
            )

            // Orquestar Ãºnica operaciÃ³n de negocio
            val result = guardarRegistroCompletoUseCase(persona, encuesta)

            when (result) {
                is Result.Success -> {
                    _uiState.value = _uiState.value.copy(
                        isLoading = false,
                        isSuccess = true
                    )
                }
                is Result.Error -> {
                    _uiState.value = _uiState.value.copy(
                        isLoading = false,
                        errorMessage = result.error.message ?: "Error al guardar en el repositorio"
                    )
                }
            }
        }
    }

    fun resetState() {
        _uiState.value = _uiState.value.copy(
            isSuccess = false,
            errorMessage = null,
            numeroDocumento = "",
            nombres = "",
            apellidos = ""
        )
    }
}
