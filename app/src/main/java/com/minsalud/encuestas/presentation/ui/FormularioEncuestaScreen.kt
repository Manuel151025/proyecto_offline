package com.minsalud.encuestas.presentation.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.DateRange
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.minsalud.encuestas.domain.model.Municipio
import com.minsalud.encuestas.domain.model.TipoDocumento
import com.minsalud.encuestas.presentation.viewmodel.FormularioEncuestaViewModel
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun FormularioEncuestaScreen(
    viewModel: FormularioEncuestaViewModel,
    onNavigateBack: () -> Unit
) {
    val uiState by viewModel.uiState.collectAsState()
    var showDatePicker by remember { mutableStateOf(false) }

    if (uiState.isSuccess) {
        AlertDialog(
            onDismissRequest = { viewModel.resetState(); onNavigateBack() },
            title = { Text("Éxito") },
            text = {
                Text(
                    if (uiState.isEdit) "Persona actualizada correctamente."
                    else "Persona registrada correctamente (offline)."
                )
            },
            confirmButton = {
                TextButton(onClick = { viewModel.resetState(); onNavigateBack() }) { Text("Aceptar") }
            }
        )
    }

    if (showDatePicker) {
        val dateState = rememberDatePickerState(initialSelectedDateMillis = uiState.fechaNacimiento)
        DatePickerDialog(
            onDismissRequest = { showDatePicker = false },
            confirmButton = {
                TextButton(onClick = {
                    viewModel.onFechaNacimientoChanged(dateState.selectedDateMillis)
                    showDatePicker = false
                }) { Text("Aceptar") }
            },
            dismissButton = { TextButton(onClick = { showDatePicker = false }) { Text("Cancelar") } }
        ) { DatePicker(state = dateState) }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(if (uiState.isEdit) "Editar persona" else "Nueva encuesta", fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    IconButton(onClick = onNavigateBack) {
                        Icon(Icons.Default.ArrowBack, contentDescription = "Atrás")
                    }
                }
            )
        }
    ) { padding ->
        Column(
            modifier = Modifier
                .padding(padding)
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            if (uiState.errorMessage != null) {
                Text(text = uiState.errorMessage!!, color = MaterialTheme.colorScheme.error)
            }

            // --- Documento ---
            SectionTitle("Documento")

            if (uiState.isEdit) {
                OutlinedTextField(
                    value = uiState.tipoDocumento.name,
                    onValueChange = {},
                    readOnly = true,
                    enabled = false,
                    label = { Text("Tipo de documento") },
                    modifier = Modifier.fillMaxWidth()
                )
            } else {
                DropdownField(
                    label = "Tipo de documento *",
                    options = TipoDocumento.entries.toList(),
                    selected = uiState.tipoDocumento,
                    optionLabel = { it.name },
                    onSelected = { viewModel.onTipoDocumentoChanged(it) }
                )
            }

            OutlinedTextField(
                value = uiState.numeroDocumento,
                onValueChange = { viewModel.onNumeroDocumentoChanged(it) },
                label = { Text("Número de documento *") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                readOnly = uiState.isEdit,
                isError = uiState.docError != null,
                supportingText = uiState.docError?.let { { Text(it) } },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true
            )

            // --- Datos personales ---
            SectionTitle("Datos personales")

            OutlinedTextField(
                value = uiState.nombres,
                onValueChange = { viewModel.onNombresChanged(it) },
                label = { Text("Nombres *") },
                isError = uiState.nombresError != null,
                supportingText = uiState.nombresError?.let { { Text(it) } },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true
            )

            OutlinedTextField(
                value = uiState.apellidos,
                onValueChange = { viewModel.onApellidosChanged(it) },
                label = { Text("Apellidos *") },
                isError = uiState.apellidosError != null,
                supportingText = uiState.apellidosError?.let { { Text(it) } },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true
            )

            OutlinedTextField(
                value = uiState.fechaNacimiento?.let { formatFecha(it) } ?: "",
                onValueChange = {},
                readOnly = true,
                label = { Text("Fecha de nacimiento") },
                trailingIcon = {
                    IconButton(onClick = { showDatePicker = true }) {
                        Icon(Icons.Default.DateRange, contentDescription = "Elegir fecha")
                    }
                },
                modifier = Modifier.fillMaxWidth()
            )

            // --- Contacto ---
            SectionTitle("Contacto")

            OutlinedTextField(
                value = uiState.telefono,
                onValueChange = { viewModel.onTelefonoChanged(it) },
                label = { Text("Teléfono") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                isError = uiState.telefonoError != null,
                supportingText = uiState.telefonoError?.let { { Text(it) } },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true
            )

            OutlinedTextField(
                value = uiState.email,
                onValueChange = { viewModel.onEmailChanged(it) },
                label = { Text("Correo electrónico") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                isError = uiState.emailError != null,
                supportingText = uiState.emailError?.let { { Text(it) } },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true
            )

            OutlinedTextField(
                value = uiState.direccion,
                onValueChange = { viewModel.onDireccionChanged(it) },
                label = { Text("Dirección") },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true
            )

            // --- Ubicación ---
            SectionTitle("Ubicación")

            val departamentos = remember(uiState.municipios) {
                uiState.municipios.map { it.departamento }.distinct().sortedBy { it.lowercase() }
            }
            val municipiosFiltrados = remember(uiState.municipios, uiState.departamento) {
                uiState.municipios
                    .filter { uiState.departamento == null || it.departamento == uiState.departamento }
                    .sortedBy { it.nombre.lowercase() }
            }

            DropdownField(
                label = "Departamento",
                options = departamentos,
                selected = uiState.departamento,
                optionLabel = { it },
                onSelected = { viewModel.onDepartamentoChanged(it) }
            )

            DropdownField(
                label = if (uiState.departamento == null) "Municipio (elige un departamento)" else "Municipio",
                options = municipiosFiltrados,
                selected = municipiosFiltrados.find { it.codigo == uiState.municipioCodigo },
                optionLabel = { it.nombre },
                onSelected = { viewModel.onMunicipioChanged(it.codigo) }
            )

            OutlinedTextField(
                value = uiState.vereda,
                onValueChange = { viewModel.onVeredaChanged(it) },
                label = { Text("Vereda (opcional)") },
                placeholder = { Text("Ej: Vereda El Carmen") },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true
            )

            // --- Información socioeconómica ---
            SectionTitle("Información socioeconómica")

            OutlinedTextField(
                value = uiState.eps,
                onValueChange = { viewModel.onEpsChanged(it) },
                label = { Text("EPS") },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true
            )

            OutlinedTextField(
                value = uiState.ocupacion,
                onValueChange = { viewModel.onOcupacionChanged(it) },
                label = { Text("Ocupación") },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true
            )

            OutlinedTextField(
                value = uiState.estrato,
                onValueChange = { viewModel.onEstratoChanged(it) },
                label = { Text("Estrato (1–6)") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                isError = uiState.estratoError != null,
                supportingText = uiState.estratoError?.let { { Text(it) } },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true
            )

            Spacer(modifier = Modifier.height(4.dp))

            Button(
                onClick = { viewModel.onGuardarClicked() },
                modifier = Modifier.fillMaxWidth().height(52.dp),
                shape = androidx.compose.foundation.shape.RoundedCornerShape(12.dp),
                enabled = !uiState.isLoading
            ) {
                if (uiState.isLoading) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(22.dp),
                        color = MaterialTheme.colorScheme.onPrimary,
                        strokeWidth = 2.dp
                    )
                } else {
                    Text(if (uiState.isEdit) "Guardar cambios" else "Guardar registro", fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

@Composable
private fun SectionTitle(text: String) {
    Text(
        text = text,
        style = MaterialTheme.typography.titleSmall,
        color = MaterialTheme.colorScheme.primary,
        modifier = Modifier.padding(top = 4.dp)
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun <T> DropdownField(
    label: String,
    options: List<T>,
    selected: T?,
    optionLabel: (T) -> String,
    onSelected: (T) -> Unit,
    modifier: Modifier = Modifier
) {
    var expanded by remember { mutableStateOf(false) }
    ExposedDropdownMenuBox(
        expanded = expanded,
        onExpandedChange = { expanded = it },
        modifier = modifier
    ) {
        OutlinedTextField(
            value = selected?.let(optionLabel) ?: "",
            onValueChange = {},
            readOnly = true,
            label = { Text(label) },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = expanded) },
            modifier = Modifier
                .menuAnchor()
                .fillMaxWidth()
        )
        ExposedDropdownMenu(
            expanded = expanded,
            onDismissRequest = { expanded = false }
        ) {
            options.forEach { option ->
                DropdownMenuItem(
                    text = { Text(optionLabel(option)) },
                    onClick = {
                        onSelected(option)
                        expanded = false
                    }
                )
            }
        }
    }
}

private fun formatFecha(ms: Long): String =
    SimpleDateFormat("yyyy-MM-dd", Locale.getDefault()).format(Date(ms))
