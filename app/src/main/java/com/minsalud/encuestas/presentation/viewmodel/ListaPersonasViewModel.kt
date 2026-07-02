package com.minsalud.encuestas.presentation.viewmodel

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.Persona
import com.minsalud.encuestas.domain.usecase.ObtenerPersonasUseCase
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import javax.inject.Inject

data class ListaPersonasUiState(
    val isLoading: Boolean = true,
    val personas: List<Persona> = emptyList(),
    val errorMessage: String? = null
)

@HiltViewModel
class ListaPersonasViewModel @Inject constructor(
    private val obtenerPersonasUseCase: ObtenerPersonasUseCase
) : ViewModel() {

    private val _uiState = MutableStateFlow(ListaPersonasUiState())
    val uiState: StateFlow<ListaPersonasUiState> = _uiState.asStateFlow()

    init {
        cargarPersonas()
    }

    private fun cargarPersonas() {
        viewModelScope.launch {
            _uiState.value = _uiState.value.copy(isLoading = true)
            obtenerPersonasUseCase().collect { result ->
                when (result) {
                    is Result.Success -> {
                        _uiState.value = _uiState.value.copy(
                            isLoading = false,
                            personas = result.data,
                            errorMessage = null
                        )
                    }
                    is Result.Error -> {
                        _uiState.value = _uiState.value.copy(
                            isLoading = false,
                            errorMessage = result.error.message ?: "Error al cargar personas"
                        )
                    }
                }
            }
        }
    }
}
