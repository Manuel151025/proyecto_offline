package com.minsalud.encuestas.presentation.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.Email
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.minsalud.encuestas.presentation.theme.BrandPrimary
import com.minsalud.encuestas.presentation.theme.StatusSuccess
import com.minsalud.encuestas.presentation.theme.StatusSuccessBg
import com.minsalud.encuestas.presentation.viewmodel.PasoRecuperacion
import com.minsalud.encuestas.presentation.viewmodel.RecuperarViewModel

/** «¿Olvidaste tu contraseña?»: documento → código del correo → listo. */
@Composable
fun RecuperarScreen(
    viewModel: RecuperarViewModel,
    onVolver: () -> Unit,
    onListo: (documento: String) -> Unit
) {
    val ui by viewModel.uiState.collectAsState()

    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(BrandPrimary)
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(start = 8.dp, top = 24.dp, end = 16.dp, bottom = 20.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            IconButton(onClick = onVolver) {
                Icon(Icons.Default.ArrowBack, contentDescription = "Volver a iniciar sesión", tint = Color.White)
            }
            Column {
                Text("Recuperar contraseña", color = Color.White, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold)
                Text("ColOffline · Ministerio de Salud", color = Color.White.copy(alpha = 0.85f), fontSize = 13.sp)
            }
        }

        Surface(
            modifier = Modifier.fillMaxSize(),
            shape = RoundedCornerShape(topStart = 28.dp, topEnd = 28.dp),
            color = MaterialTheme.colorScheme.surface
        ) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .verticalScroll(rememberScrollState())
                    .padding(24.dp),
                verticalArrangement = Arrangement.spacedBy(16.dp)
            ) {
                Pasos(ui.paso)

                when (ui.paso) {
                    PasoRecuperacion.DOCUMENTO -> {
                        Text(
                            "Escribe tu número de documento. Te enviaremos un código de 6 dígitos al correo registrado en tu cuenta.",
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                        OutlinedTextField(
                            value = ui.documento,
                            onValueChange = viewModel::onDocumento,
                            label = { Text("Número de documento") },
                            singleLine = true,
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                            modifier = Modifier.fillMaxWidth()
                        )
                        Error(ui.error)
                        BotonPrincipal("Enviarme un código", ui.cargando, viewModel::pedirCodigo)
                        Text(
                            "¿Tu cuenta no tiene correo? Pídele a tu administrador que te asigne una contraseña nueva desde el panel.",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }

                    PasoRecuperacion.CODIGO -> {
                        ui.mensaje?.let {
                            Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = RoundedCornerShape(14.dp)) {
                                Row(Modifier.padding(12.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                    Icon(Icons.Default.Email, contentDescription = null, tint = MaterialTheme.colorScheme.onPrimaryContainer)
                                    Text(it, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onPrimaryContainer)
                                }
                            }
                        }
                        OutlinedTextField(
                            value = ui.codigo,
                            onValueChange = viewModel::onCodigo,
                            label = { Text("Código del correo") },
                            placeholder = { Text("000000") },
                            singleLine = true,
                            textStyle = TextStyle(fontSize = 24.sp, fontWeight = FontWeight.ExtraBold, letterSpacing = 8.sp, textAlign = TextAlign.Center),
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                            modifier = Modifier.fillMaxWidth()
                        )
                        val transformacion = if (ui.verClave) VisualTransformation.None else PasswordVisualTransformation()
                        OutlinedTextField(
                            value = ui.nueva,
                            onValueChange = viewModel::onNueva,
                            label = { Text("Contraseña nueva") },
                            supportingText = { Text("Mínimo ${RecuperarViewModel.MIN_CLAVE} caracteres") },
                            singleLine = true,
                            visualTransformation = transformacion,
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                            trailingIcon = {
                                TextButton(onClick = viewModel::onVerClave) { Text(if (ui.verClave) "Ocultar" else "Ver") }
                            },
                            modifier = Modifier.fillMaxWidth()
                        )
                        OutlinedTextField(
                            value = ui.confirmar,
                            onValueChange = viewModel::onConfirmar,
                            label = { Text("Repite la contraseña nueva") },
                            singleLine = true,
                            visualTransformation = transformacion,
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                            modifier = Modifier.fillMaxWidth()
                        )
                        Error(ui.error)
                        BotonPrincipal("Cambiar contraseña", ui.cargando, viewModel::cambiarContrasena)
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                            TextButton(onClick = viewModel::pedirCodigo, enabled = !ui.cargando) { Text("Pedir otro código") }
                            TextButton(onClick = viewModel::onOtroDocumento) { Text("Otro documento") }
                        }
                    }

                    PasoRecuperacion.LISTO -> {
                        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
                            Box(
                                Modifier.size(64.dp).background(StatusSuccessBg, CircleShape),
                                contentAlignment = Alignment.Center
                            ) {
                                Icon(Icons.Default.Check, contentDescription = null, tint = StatusSuccess, modifier = Modifier.size(34.dp))
                            }
                            Spacer(Modifier.height(12.dp))
                            Text("Contraseña cambiada", style = MaterialTheme.typography.titleMedium)
                            Spacer(Modifier.height(4.dp))
                            Text(
                                "Ya puedes entrar con tu contraseña nueva. Por seguridad se cerró la sesión en tus otros celulares.",
                                style = MaterialTheme.typography.bodyMedium,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                textAlign = TextAlign.Center
                            )
                        }
                        BotonPrincipal("Ir a iniciar sesión", false) { onListo(ui.documento) }
                    }
                }
            }
        }
    }
}

@Composable
private fun Pasos(actual: PasoRecuperacion) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        listOf("Documento", "Código", "Listo").forEachIndexed { i, nombre ->
            val estado = i.compareTo(actual.ordinal)
            val color = when {
                estado < 0 -> StatusSuccess
                estado == 0 -> MaterialTheme.colorScheme.primary
                else -> MaterialTheme.colorScheme.outlineVariant
            }
            Column(Modifier.weight(1f)) {
                Box(Modifier.fillMaxWidth().height(3.dp).background(color, RoundedCornerShape(2.dp)))
                Spacer(Modifier.height(6.dp))
                Text(
                    if (estado < 0) "✓ $nombre" else "${i + 1} $nombre",
                    style = MaterialTheme.typography.labelMedium,
                    color = if (estado > 0) MaterialTheme.colorScheme.onSurfaceVariant else color
                )
            }
        }
    }
}

@Composable
private fun Error(mensaje: String?) {
    if (mensaje != null) {
        Text(mensaje, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium)
    }
}

@Composable
private fun BotonPrincipal(texto: String, cargando: Boolean, onClick: () -> Unit) {
    Button(
        onClick = onClick,
        enabled = !cargando,
        shape = RoundedCornerShape(16.dp),
        modifier = Modifier.fillMaxWidth().height(54.dp)
    ) {
        if (cargando) {
            CircularProgressIndicator(Modifier.size(22.dp), color = MaterialTheme.colorScheme.onPrimary, strokeWidth = 2.dp)
        } else {
            Text(texto, fontWeight = FontWeight.Bold)
        }
    }
}
