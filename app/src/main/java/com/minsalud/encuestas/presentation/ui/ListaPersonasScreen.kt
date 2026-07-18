package com.minsalud.encuestas.presentation.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.ExitToApp
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.minsalud.encuestas.domain.model.Persona
import com.minsalud.encuestas.presentation.theme.BrandGreen
import com.minsalud.encuestas.presentation.theme.BrandGreenDark
import com.minsalud.encuestas.presentation.theme.BrandGreenLight
import com.minsalud.encuestas.presentation.theme.WarnAmber
import com.minsalud.encuestas.presentation.theme.WarnAmberBg
import com.minsalud.encuestas.presentation.viewmodel.ListaPersonasViewModel
import com.minsalud.encuestas.presentation.viewmodel.PersonaUi

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ListaPersonasScreen(
    viewModel: ListaPersonasViewModel,
    onNavigateToFormulario: () -> Unit,
    onNavigateToSync: () -> Unit,
    onLogout: () -> Unit,
    onPersonaClick: (Persona) -> Unit,
    isDark: Boolean,
    onToggleTheme: () -> Unit
) {
    val uiState by viewModel.uiState.collectAsState()

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text(
                            if (uiState.nombreEncuestador.isNotBlank())
                                "Hola, ${uiState.nombreEncuestador}"
                            else "Personas Encuestadas",
                            fontWeight = FontWeight.Bold
                        )
                        Text(
                            "${uiState.personas.size} registro(s)",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.surface
                ),
                actions = {
                    IconButton(onClick = onToggleTheme) {
                        Text(if (isDark) "☀️" else "🌙", fontSize = 18.sp)
                    }
                    IconButton(onClick = onNavigateToSync) {
                        Icon(Icons.Default.Refresh, contentDescription = "Sincronización")
                    }
                    IconButton(onClick = onLogout) {
                        Icon(Icons.Default.ExitToApp, contentDescription = "Cerrar sesión")
                    }
                }
            )
        },
        floatingActionButton = {
            ExtendedFloatingActionButton(
                onClick = onNavigateToFormulario,
                icon = { Icon(Icons.Default.Add, contentDescription = null) },
                text = { Text("Nueva encuesta") },
                containerColor = MaterialTheme.colorScheme.primary,
                contentColor = MaterialTheme.colorScheme.onPrimary
            )
        }
    ) { padding ->
        Box(
            modifier = Modifier
                .padding(padding)
                .fillMaxSize()
                .background(MaterialTheme.colorScheme.background)
        ) {
            when {
                uiState.isLoading -> {
                    CircularProgressIndicator(modifier = Modifier.align(Alignment.Center))
                }
                uiState.errorMessage != null -> {
                    Text(
                        text = uiState.errorMessage!!,
                        color = MaterialTheme.colorScheme.error,
                        modifier = Modifier.align(Alignment.Center)
                    )
                }
                uiState.personas.isEmpty() -> {
                    EmptyState(modifier = Modifier.align(Alignment.Center))
                }
                else -> {
                    LazyColumn(
                        modifier = Modifier.fillMaxSize(),
                        contentPadding = PaddingValues(16.dp, 12.dp, 16.dp, 96.dp),
                        verticalArrangement = Arrangement.spacedBy(10.dp)
                    ) {
                        items(uiState.personas) { item ->
                            PersonaCard(item, onClick = { onPersonaClick(item.persona) })
                        }
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun PersonaCard(item: PersonaUi, onClick: () -> Unit) {
    val p = item.persona
    val iniciales = ((p.nombres.firstOrNull()?.toString() ?: "") +
            (p.apellidos.firstOrNull()?.toString() ?: "")).uppercase()

    Card(
        onClick = onClick,
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(16.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp)
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(14.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Box(
                modifier = Modifier
                    .size(46.dp)
                    .background(BrandGreenLight, CircleShape),
                contentAlignment = Alignment.Center
            ) {
                Text(iniciales.ifBlank { "?" }, color = BrandGreenDark, fontWeight = FontWeight.Bold)
            }
            Spacer(Modifier.width(12.dp))
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    "${p.nombres} ${p.apellidos}".trim(),
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.SemiBold
                )
                Spacer(Modifier.height(2.dp))
                Text(
                    "${p.tipoDocumento.name}: ${p.numeroDocumento}",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
            SyncBadge(pendiente = item.pendiente)
        }
    }
}

@Composable
private fun SyncBadge(pendiente: Boolean) {
    val bg = if (pendiente) WarnAmberBg else BrandGreenLight
    val fg = if (pendiente) WarnAmber else BrandGreen
    val label = if (pendiente) "Pendiente" else "Sincronizado"
    Box(
        modifier = Modifier
            .background(bg, RoundedCornerShape(99.dp))
            .padding(horizontal = 10.dp, vertical = 5.dp)
    ) {
        Text(label, color = fg, fontSize = 11.sp, fontWeight = FontWeight.Bold)
    }
}

@Composable
private fun EmptyState(modifier: Modifier = Modifier) {
    Column(
        modifier = modifier.padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Box(
            modifier = Modifier
                .size(72.dp)
                .background(BrandGreenLight, CircleShape),
            contentAlignment = Alignment.Center
        ) {
            Icon(Icons.Default.Add, contentDescription = null, tint = BrandGreen, modifier = Modifier.size(36.dp))
        }
        Spacer(Modifier.height(16.dp))
        Text("Sin personas registradas", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(4.dp))
        Text(
            "Toca \"Nueva encuesta\" para registrar la primera persona.",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
    }
}
