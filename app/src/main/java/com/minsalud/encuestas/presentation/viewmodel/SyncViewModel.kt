package com.minsalud.encuestas.presentation.viewmodel

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.EstadoCola
import com.minsalud.encuestas.domain.repository.SyncRepository
import com.minsalud.encuestas.domain.usecase.GenerarReporteUseCase
import com.minsalud.encuestas.domain.usecase.SincronizarPendientesUseCase
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import javax.inject.Inject

data class SyncUiState(
    val isSyncing: Boolean = false,
    val isGeneratingReport: Boolean = false,
    val reportUrl: String? = null,
    val message: String? = null
)

@HiltViewModel
class SyncViewModel @Inject constructor(
    private val sincronizarPendientesUseCase: SincronizarPendientesUseCase,
    private val generarReporteUseCase: GenerarReporteUseCase,
    syncRepository: SyncRepository
) : ViewModel() {

    /** Pendientes, con error y rechazados (con su motivo), siempre al día. */
    val estadoCola: StateFlow<EstadoCola> = syncRepository.estadoCola()
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), EstadoCola())

    private val _uiState = MutableStateFlow(SyncUiState())
    val uiState: StateFlow<SyncUiState> = _uiState.asStateFlow()

    // Este es solo para sincronización manual provocada por el usuario (el Worker lo hace en background de todos modos)
    fun onSincronizarManual() {
        viewModelScope.launch {
            _uiState.value = _uiState.value.copy(isSyncing = true, message = null)
            val result = sincronizarPendientesUseCase()
            when (result) {
                is Result.Success -> {
                    // Lo que pasó de verdad, no "encolada o completada".
                    _uiState.value = _uiState.value.copy(isSyncing = false, message = result.data.mensaje)
                }
                is Result.Error -> {
                    _uiState.value = _uiState.value.copy(
                        isSyncing = false,
                        message = result.error.message ?: "Error al sincronizar"
                    )
                }
            }
        }
    }

    fun onGenerarReporte(fechaDesde: Long, fechaHasta: Long) {
        viewModelScope.launch {
            _uiState.value = _uiState.value.copy(isGeneratingReport = true, message = null, reportUrl = null)
            val result = generarReporteUseCase(fechaDesde, fechaHasta)
            when (result) {
                is Result.Success -> {
                    _uiState.value = _uiState.value.copy(
                        isGeneratingReport = false,
                        reportUrl = result.data.rutaArchivo, // Objeto de dominio Reporte
                        message = "Reporte generado correctamente"
                    )
                }
                is Result.Error -> {
                    _uiState.value = _uiState.value.copy(
                        isGeneratingReport = false,
                        message = result.error.message ?: "Error al generar reporte"
                    )
                }
            }
        }
    }

    fun clearMessage() {
        _uiState.value = _uiState.value.copy(message = null)
    }
}
