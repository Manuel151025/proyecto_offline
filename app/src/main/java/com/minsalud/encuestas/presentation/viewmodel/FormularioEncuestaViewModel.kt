package com.minsalud.encuestas.presentation.viewmodel

import android.content.Context
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.data.local.prefs.DispositivoManager
import com.minsalud.encuestas.data.local.prefs.SessionManager
import com.minsalud.encuestas.domain.model.*
import com.minsalud.encuestas.domain.usecase.GuardarRegistroCompletoUseCase
import com.minsalud.encuestas.domain.validation.Validaciones
import com.minsalud.encuestas.domain.usecase.ObtenerMunicipiosUseCase
import com.minsalud.encuestas.domain.usecase.ObtenerPersonaUseCase
import com.minsalud.encuestas.domain.usecase.SeedMunicipiosUseCase
import com.minsalud.encuestas.domain.usecase.ObtenerEpsUseCase
import com.minsalud.encuestas.domain.catalogo.BuscadorCatalogo
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
    val catalogoEps: List<Eps> = emptyList(),

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
    /** Lo que se ve en el campo de municipio: lo escrito, o «Popayán, Cauca» al elegir. */
    val municipioTexto: String = "",
    val municipioCodigo: String? = null,

    // Errores por campo (validación)
    val docError: String? = null,
    val nombresError: String? = null,
    val apellidosError: String? = null,
    val emailError: String? = null,
    val telefonoError: String? = null,
    val estratoError: String? = null,
    val fechaError: String? = null,
    val direccionError: String? = null,
    val veredaError: String? = null,
    val epsError: String? = null,
    val ocupacionError: String? = null,
    val municipioError: String? = null
)

@HiltViewModel
class FormularioEncuestaViewModel @Inject constructor(
    private val guardarRegistroCompletoUseCase: GuardarRegistroCompletoUseCase,
    private val obtenerMunicipiosUseCase: ObtenerMunicipiosUseCase,
    private val obtenerPersonaUseCase: ObtenerPersonaUseCase,
    private val seedMunicipiosUseCase: SeedMunicipiosUseCase,
    private val obtenerEpsUseCase: ObtenerEpsUseCase,
    private val sessionManager: SessionManager,
    private val dispositivo: DispositivoManager,
    @ApplicationContext private val appContext: Context,
    savedStateHandle: SavedStateHandle
) : ViewModel() {

    private val _uiState = MutableStateFlow(FormularioUiState())
    val uiState: StateFlow<FormularioUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch { seedMunicipiosUseCase() }
        viewModelScope.launch { _uiState.value = _uiState.value.copy(catalogoEps = obtenerEpsUseCase()) }
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
                    // En edición, el campo muestra el municipio guardado.
                    val texto = if (state.municipioTexto.isEmpty() && state.municipioCodigo != null) {
                        municipios.find { it.codigo == state.municipioCodigo }?.let(BuscadorCatalogo::etiqueta) ?: ""
                    } else state.municipioTexto
                    _uiState.value = state.copy(municipios = municipios, municipioTexto = texto)
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
                    val texto = _uiState.value.municipios.find { it.codigo == p.municipioCodigo }
                        ?.let(BuscadorCatalogo::etiqueta) ?: ""
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
                        municipioTexto = texto
                    )
                }
                is Result.Error -> {
                    _uiState.value = _uiState.value.copy(errorMessage = "No se pudo cargar la persona")
                }
            }
        }
    }

    fun onTipoDocumentoChanged(tipo: TipoDocumento) {
        // El número ya escrito se ajusta al formato del nuevo tipo.
        val s = _uiState.value
        _uiState.value = s.copy(
            tipoDocumento = tipo,
            numeroDocumento = if (s.isEdit) s.numeroDocumento else Validaciones.limpiarDocumento(s.numeroDocumento, tipo),
            docError = null
        )
    }

    fun onNumeroDocumentoChanged(numero: String) {
        _uiState.value = _uiState.value.copy(
            numeroDocumento = Validaciones.limpiarDocumento(numero, _uiState.value.tipoDocumento), docError = null
        )
    }

    fun onNombresChanged(nombres: String) {
        _uiState.value = _uiState.value.copy(nombres = Validaciones.limpiarNombre(nombres), nombresError = null)
    }

    fun onApellidosChanged(apellidos: String) {
        _uiState.value = _uiState.value.copy(apellidos = Validaciones.limpiarNombre(apellidos), apellidosError = null)
    }

    fun onFechaNacimientoChanged(fecha: Long?) {
        _uiState.value = _uiState.value.copy(fechaNacimiento = fecha, fechaError = null)
    }

    fun onTelefonoChanged(telefono: String) {
        _uiState.value = _uiState.value.copy(
            telefono = Validaciones.limpiarTelefono(telefono), telefonoError = null
        )
    }

    fun onEmailChanged(email: String) {
        _uiState.value = _uiState.value.copy(email = email.trim().take(100), emailError = null)
    }

    fun onDireccionChanged(direccion: String) {
        _uiState.value = _uiState.value.copy(
            direccion = Validaciones.limpiarTexto(direccion, Validaciones.TextoLibre.DIRECCION), direccionError = null
        )
    }

    fun onVeredaChanged(vereda: String) {
        _uiState.value = _uiState.value.copy(
            vereda = Validaciones.limpiarTexto(vereda, Validaciones.TextoLibre.VEREDA), veredaError = null
        )
    }

    fun onEpsChanged(eps: String) {
        _uiState.value = _uiState.value.copy(
            eps = Validaciones.limpiarTexto(eps, Validaciones.TextoLibre.EPS), epsError = null
        )
    }

    fun onOcupacionChanged(ocupacion: String) {
        _uiState.value = _uiState.value.copy(
            ocupacion = Validaciones.limpiarTexto(ocupacion, Validaciones.TextoLibre.OCUPACION), ocupacionError = null
        )
    }

    fun onEstratoChanged(estrato: String) {
        _uiState.value = _uiState.value.copy(
            estrato = Validaciones.limpiarEstrato(estrato), estratoError = null
        )
    }

    /** Al escribir se pierde la elección: hay que volver a tocar una sugerencia. */
    fun onMunicipioTextoChanged(texto: String) {
        _uiState.value = _uiState.value.copy(
            municipioTexto = texto.take(80), municipioCodigo = null, municipioError = null
        )
    }

    fun onMunicipioElegido(municipio: Municipio) {
        _uiState.value = _uiState.value.copy(
            municipioTexto = BuscadorCatalogo.etiqueta(municipio),
            municipioCodigo = municipio.codigo,
            municipioError = null
        )
    }

    fun onEpsElegida(eps: Eps) {
        _uiState.value = _uiState.value.copy(eps = eps.nombre, epsError = null)
    }

    /** Valida y marca errores por campo. Devuelve true si todo es válido. */
    private fun validar(state: FormularioUiState): FormularioUiState {
        // Al editar, el documento no se puede cambiar: no se bloquea por él.
        val docError = if (state.isEdit) null
            else Validaciones.errorDocumento(state.numeroDocumento, state.tipoDocumento)
        val nombresError = Validaciones.errorNombre(state.nombres)
        val apellidosError = Validaciones.errorNombre(state.apellidos)
        val emailError = if (state.email.isNotBlank() && !Validaciones.esEmailValido(state.email))
            "Correo no válido" else null
        val telefonoError = if (!Validaciones.esTelefonoValido(state.telefono))
            "Celular de 10 dígitos (empieza por 3) o fijo de 10 (empieza por 60)" else null
        val estratoError = if (!Validaciones.esEstratoValido(state.estrato))
            "Estrato debe ser 1–6" else null
        val fechaError = if (!Validaciones.esFechaNacimientoValida(state.fechaNacimiento))
            "No puede ser futura ni anterior a 1900" else null
        val municipioError = if (state.municipioTexto.isNotBlank() && state.municipioCodigo == null)
            "Elige el municipio de la lista de sugerencias" else null
        return state.copy(
            municipioError = municipioError,
            fechaError = fechaError,
            direccionError = Validaciones.errorTexto(state.direccion, Validaciones.TextoLibre.DIRECCION),
            veredaError = Validaciones.errorTexto(state.vereda, Validaciones.TextoLibre.VEREDA),
            epsError = Validaciones.errorTexto(state.eps, Validaciones.TextoLibre.EPS),
            ocupacionError = Validaciones.errorTexto(state.ocupacion, Validaciones.TextoLibre.OCUPACION),
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
            validated.emailError, validated.telefonoError, validated.estratoError,
            validated.fechaError, validated.direccionError, validated.veredaError,
            validated.epsError, validated.ocupacionError, validated.municipioError
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
                // Antes era "DEVICE_ID_LOCAL" fijo: el servidor veía todos los
                // Android como un único dispositivo.
                deviceId = dispositivo.deviceId(),
                deletedAt = null
            )

            val encuesta = Encuesta(
                id = UUID.randomUUID().toString(),
                tipoDocumento = state.tipoDocumento,
                numeroDocumento = state.numeroDocumento.trim(),
                idEncuestador = sessionManager.encuestadorId(),
                fechaEncuesta = 0L,
                fechaSincronizacion = null,
                deviceId = dispositivo.deviceId(),
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
