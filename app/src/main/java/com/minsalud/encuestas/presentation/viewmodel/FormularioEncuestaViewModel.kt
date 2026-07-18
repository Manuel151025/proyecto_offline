package com.minsalud.encuestas.presentation.viewmodel

import android.content.Context
import android.util.Patterns
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.data.local.prefs.SessionManager
import com.minsalud.encuestas.domain.model.*
import com.minsalud.encuestas.domain.usecase.GuardarRegistroCompletoUseCase
import com.minsalud.encuestas.domain.usecase.ObtenerMunicipiosUseCase
import com.minsalud.encuestas.domain.usecase.ObtenerPersonaUseCase
import com.minsalud.encuestas.domain.usecase.SeedMunicipiosUseCase
import com.minsalud.encuestas.worker.SyncWorkerScheduler
import dagger.hilt.android.lifecycle.HiltViewModel
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import java.util.UUID
import javax.inject.Inject

data class FormularioUiState(
    val isLoading: Boolean = false,
    val isSuccess: Boolean = false,
    val isEdit: Boolean = false,
    val errorMessage: String? = null,
    val municipios: List<Municipio> = emptyList(),

    // Campos del formulario
    val tipoDocumento: TipoDocumento = TipoDocumento.CC,
    val numeroDocumento: String = "",
    val nombres: String = "",
    val apellidos: String = "",
    val fechaNacimiento: Long? = null,
    val telefono: String = "",
    val email: String = "",
    val direccion: String = "",
    val vereda: String = "",
    val eps: String = "",
    val ocupacion: String = "",
    val estrato: String = "",
    val departamento: String? = null,
    val municipioCodigo: String? = null,

    // Errores por campo (validación)
    val docError: String? = null,
    val nombresError: String? = null,
    val apellidosError: String? = null,
    val emailError: String? = null,
    val telefonoError: String? = null,
    val estratoError: String? = null
)

@HiltViewModel
class FormularioEncuestaViewModel @Inject constructor(
    private val guardarRegistroCompletoUseCase: GuardarRegistroCompletoUseCase,
    private val obtenerMunicipiosUseCase: ObtenerMunicipiosUseCase,
    private val obtenerPersonaUseCase: ObtenerPersonaUseCase,
    private val seedMunicipiosUseCase: SeedMunicipiosUseCase,
    private val sessionManager: SessionManager,
    @ApplicationContext private val appContext: Context,
    savedStateHandle: SavedStateHandle
) : ViewModel() {

    private val _uiState = MutableStateFlow(FormularioUiState())
    val uiState: StateFlow<FormularioUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch { seedMunicipiosUseCase() }
        cargarMunicipios()

        val tipoArg = savedStateHandle.get<String>("tipo")
        val numeroArg = savedStateHandle.get<String>("numero")
        if (!tipoArg.isNullOrBlank() && !numeroArg.isNullOrBlank()) {
            cargarPersonaParaEditar(tipoArg, numeroArg)
        }
    }

    private fun cargarMunicipios() {
        viewModelScope.launch {
            obtenerMunicipiosUseCase().collect { result ->
                if (result is Result.Success) {
                    val municipios = result.data
                    val state = _uiState.value
                    // En edición, preselecciona el departamento a partir del municipio.
                    val depto = if (state.departamento == null && state.municipioCodigo != null) {
                        municipios.find { it.codigo == state.municipioCodigo }?.departamento
                    } else state.departamento
                    _uiState.value = state.copy(municipios = municipios, departamento = depto)
                }
            }
        }
    }

    private fun cargarPersonaParaEditar(tipo: String, numero: String) {
        viewModelScope.launch {
            val tipoDoc = runCatching { TipoDocumento.valueOf(tipo) }.getOrDefault(TipoDocumento.CC)
            when (val result = obtenerPersonaUseCase(tipoDoc, numero)) {
                is Result.Success -> {
                    val p = result.data ?: return@launch
                    val depto = _uiState.value.municipios.find { it.codigo == p.municipioCodigo }?.departamento
                    _uiState.value = _uiState.value.copy(
                        isEdit = true,
                        tipoDocumento = p.tipoDocumento,
                        numeroDocumento = p.numeroDocumento,
                        nombres = p.nombres,
                        apellidos = p.apellidos,
                        fechaNacimiento = p.fechaNacimiento,
                        telefono = p.telefono ?: "",
                        email = p.email ?: "",
                        direccion = p.direccion ?: "",
                        vereda = p.vereda ?: "",
                        eps = p.eps ?: "",
                        ocupacion = p.ocupacion ?: "",
                        estrato = p.estrato?.toString() ?: "",
                        municipioCodigo = p.municipioCodigo,
                        departamento = depto
                    )
                }
                is Result.Error -> {
                    _uiState.value = _uiState.value.copy(errorMessage = "No se pudo cargar la persona")
                }
            }
        }
    }

    fun onTipoDocumentoChanged(tipo: TipoDocumento) {
        _uiState.value = _uiState.value.copy(tipoDocumento = tipo)
    }

    fun onNumeroDocumentoChanged(numero: String) {
        _uiState.value = _uiState.value.copy(
            numeroDocumento = numero.filter { it.isDigit() }.take(12), docError = null
        )
    }

    fun onNombresChanged(nombres: String) {
        _uiState.value = _uiState.value.copy(nombres = nombres.take(60), nombresError = null)
    }

    fun onApellidosChanged(apellidos: String) {
        _uiState.value = _uiState.value.copy(apellidos = apellidos.take(60), apellidosError = null)
    }

    fun onFechaNacimientoChanged(fecha: Long?) {
        _uiState.value = _uiState.value.copy(fechaNacimiento = fecha)
    }

    fun onTelefonoChanged(telefono: String) {
        _uiState.value = _uiState.value.copy(
            telefono = telefono.filter { it.isDigit() }.take(10), telefonoError = null
        )
    }

    fun onEmailChanged(email: String) {
        _uiState.value = _uiState.value.copy(email = email.trim().take(100), emailError = null)
    }

    fun onDireccionChanged(direccion: String) {
        _uiState.value = _uiState.value.copy(direccion = direccion.take(150))
    }

    fun onVeredaChanged(vereda: String) {
        _uiState.value = _uiState.value.copy(vereda = vereda.take(100))
    }

    fun onEpsChanged(eps: String) {
        _uiState.value = _uiState.value.copy(eps = eps.take(50))
    }

    fun onOcupacionChanged(ocupacion: String) {
        _uiState.value = _uiState.value.copy(ocupacion = ocupacion.take(60))
    }

    fun onEstratoChanged(estrato: String) {
        _uiState.value = _uiState.value.copy(
            estrato = estrato.filter { it.isDigit() }.take(1), estratoError = null
        )
    }

    fun onDepartamentoChanged(departamento: String?) {
        _uiState.value = _uiState.value.copy(departamento = departamento, municipioCodigo = null)
    }

    fun onMunicipioChanged(codigo: String?) {
        _uiState.value = _uiState.value.copy(municipioCodigo = codigo)
    }

    /** Valida y marca errores por campo. Devuelve true si todo es válido. */
    private fun validar(state: FormularioUiState): FormularioUiState {
        val docError = when {
            state.numeroDocumento.isBlank() -> "El documento es obligatorio"
            state.numeroDocumento.length < 6 -> "Debe tener al menos 6 dígitos"
            else -> null
        }
        val nombresError = when {
            state.nombres.isBlank() -> "Los nombres son obligatorios"
            state.nombres.any { it.isDigit() } -> "No debe contener números"
            else -> null
        }
        val apellidosError = when {
            state.apellidos.isBlank() -> "Los apellidos son obligatorios"
            state.apellidos.any { it.isDigit() } -> "No debe contener números"
            else -> null
        }
        val emailError = if (state.email.isNotBlank() &&
            !Patterns.EMAIL_ADDRESS.matcher(state.email).matches()
        ) "Correo no válido" else null
        val telefonoError = if (state.telefono.isNotBlank() && state.telefono.length < 7)
            "Teléfono no válido" else null
        val estratoError = state.estrato.toIntOrNull().let {
            if (state.estrato.isNotBlank() && (it == null || it !in 1..6)) "Estrato debe ser 1–6" else null
        }
        return state.copy(
            docError = docError,
            nombresError = nombresError,
            apellidosError = apellidosError,
            emailError = emailError,
            telefonoError = telefonoError,
            estratoError = estratoError
        )
    }

    fun onGuardarClicked() {
        val validated = validar(_uiState.value)
        _uiState.value = validated
        val hasError = listOf(
            validated.docError, validated.nombresError, validated.apellidosError,
            validated.emailError, validated.telefonoError, validated.estratoError
        ).any { it != null }
        if (hasError) return

        viewModelScope.launch {
            val state = _uiState.value
            _uiState.value = state.copy(isLoading = true, errorMessage = null)

            val estratoInt = state.estrato.toIntOrNull()?.takeIf { it in 1..6 }

            val persona = Persona(
                tipoDocumento = state.tipoDocumento,
                numeroDocumento = state.numeroDocumento.trim(),
                nombres = state.nombres.trim(),
                apellidos = state.apellidos.trim(),
                fechaNacimiento = state.fechaNacimiento,
                telefono = state.telefono.trim().ifBlank { null },
                email = state.email.trim().ifBlank { null },
                direccion = state.direccion.trim().ifBlank { null },
                vereda = state.vereda.trim().ifBlank { null },
                eps = state.eps.trim().ifBlank { null },
                ocupacion = state.ocupacion.trim().ifBlank { null },
                estrato = estratoInt,
                municipioCodigo = state.municipioCodigo,
                updatedAt = 0L,
                deviceId = "DEVICE_ID_LOCAL",
                deletedAt = null
            )

            val encuesta = Encuesta(
                id = UUID.randomUUID().toString(),
                tipoDocumento = state.tipoDocumento,
                numeroDocumento = state.numeroDocumento.trim(),
                idEncuestador = sessionManager.encuestadorId(),
                fechaEncuesta = 0L,
                fechaSincronizacion = null,
                deviceId = "DEVICE_ID_LOCAL",
                accion = if (state.isEdit) AccionEncuesta.ACTUALIZACION else AccionEncuesta.CREACION
            )

            when (val result = guardarRegistroCompletoUseCase(persona, encuesta)) {
                is Result.Success -> {
                    // Dispara una sincronización que se ejecutará apenas haya red.
                    SyncWorkerScheduler.triggerImmediateSync(appContext)
                    _uiState.value = _uiState.value.copy(isLoading = false, isSuccess = true)
                }
                is Result.Error -> {
                    _uiState.value = _uiState.value.copy(
                        isLoading = false,
                        errorMessage = result.error.message ?: "Error al guardar"
                    )
                }
            }
        }
    }

    fun resetState() {
        _uiState.value = _uiState.value.copy(isSuccess = false, errorMessage = null)
    }
}
