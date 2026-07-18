package com.minsalud.encuestas.presentation.viewmodel

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.data.local.prefs.SessionManager
import com.minsalud.encuestas.domain.model.Persona
import com.minsalud.encuestas.domain.usecase.ObtenerPendientesUseCase
import com.minsalud.encuestas.domain.usecase.ObtenerPersonasUseCase
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.launch
import javax.inject.Inject

data class PersonaUi(
    val persona: Persona,
    val pendiente: Boolean
)

data class ListaPersonasUiState(
    val isLoading: Boolean = true,
    val personas: List<PersonaUi> = emptyList(),
    val errorMessage: String? = null,
    val nombreEncuestador: String = ""
)

@HiltViewModel
class ListaPersonasViewModel @Inject constructor(
    private val obtenerPersonasUseCase: ObtenerPersonasUseCase,
    private val obtenerPendientesUseCase: ObtenerPendientesUseCase,
    private val sessionManager: SessionManager
) : ViewModel() {

    private val _uiState = MutableStateFlow(
        ListaPersonasUiState(nombreEncuestador = sessionManager.nombre())
    )
    val uiState: StateFlow<ListaPersonasUiState> = _uiState.asStateFlow()

    init {
        cargarPersonas()
    }

    private fun cargarPersonas() {
        viewModelScope.launch {
            combine(
                obtenerPersonasUseCase(),
                obtenerPendientesUseCase()
            ) { result, pendientes ->
                when (result) {
                    is Result.Success -> {
                        val pendSet = pendientes.toSet()
                        val items = result.data.map { p ->
                            PersonaUi(
                                persona = p,
                                pendiente = pendSet.contains("${p.tipoDocumento.name}|${p.numeroDocumento}")
                            )
                        }
                        _uiState.value = _uiState.value.copy(
                            isLoading = false,
                            personas = items,
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
            }.collect { }
        }
    }
}
