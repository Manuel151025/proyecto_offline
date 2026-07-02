package com.minsalud.encuestas.presentation.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.minsalud.encuestas.presentation.viewmodel.FormularioEncuestaViewModel
import com.minsalud.encuestas.domain.model.TipoDocumento

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun FormularioEncuestaScreen(
    viewModel: FormularioEncuestaViewModel,
    onNavigateBack: () -> Unit
) {
    val uiState by viewModel.uiState.collectAsState()

    // Manejo de Ã©xito
    if (uiState.isSuccess) {
        AlertDialog(
            onDismissRequest = { 
                viewModel.resetState()
                onNavigateBack() 
            },
            title = { Text("Ã‰xito") },
            text = { Text("Encuesta y persona guardadas correctamente en la base de datos local (Offline).") },
            confirmButton = {
                TextButton(onClick = { 
                    viewModel.resetState()
                    onNavigateBack() 
                }) {
                    Text("Aceptar")
                }
            }
        )
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Nueva Encuesta Offline") },
                navigationIcon = {
                    IconButton(onClick = onNavigateBack) {
                        Icon(Icons.Default.ArrowBack, contentDescription = "AtrÃ¡s")
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
            verticalArrangement = Arrangement.spacedBy(16.dp)
        ) {
            if (uiState.errorMessage != null) {
                Text(text = uiState.errorMessage!!, color = MaterialTheme.colorScheme.error)
            }

            Text("Tipo Documento: ", style = MaterialTheme.typography.bodyMedium)

            OutlinedTextField(
                value = uiState.numeroDocumento,
                onValueChange = { viewModel.onNumeroDocumentoChanged(it) },
                label = { Text("NÃºmero de Documento") },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true
            )

            OutlinedTextField(
                value = uiState.nombres,
                onValueChange = { viewModel.onNombresChanged(it) },
                label = { Text("Nombres") },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true
            )

            OutlinedTextField(
                value = uiState.apellidos,
                onValueChange = { viewModel.onApellidosChanged(it) },
                label = { Text("Apellidos") },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true
            )

            Button(
                onClick = { viewModel.onGuardarClicked() },
                modifier = Modifier.fillMaxWidth(),
                enabled = !uiState.isLoading
            ) {
                if (uiState.isLoading) {
                    CircularProgressIndicator(modifier = Modifier.size(24.dp), color = MaterialTheme.colorScheme.onPrimary)
                } else {
                    Text("Guardar Registro Transaccional")
                }
            }
        }
    }
}
