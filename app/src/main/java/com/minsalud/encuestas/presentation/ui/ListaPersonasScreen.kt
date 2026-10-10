package com.minsalud.encuestas.presentation.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.ExitToApp
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.minsalud.encuestas.domain.model.Persona
import com.minsalud.encuestas.presentation.theme.BrandAccent
import com.minsalud.encuestas.presentation.theme.BrandPrimary
import com.minsalud.encuestas.presentation.theme.BrandPrimaryDark
import com.minsalud.encuestas.presentation.theme.BrandPrimaryTint
import com.minsalud.encuestas.presentation.theme.StatusSuccess
import com.minsalud.encuestas.presentation.theme.StatusSuccessBg
import com.minsalud.encuestas.presentation.theme.StatusWarning
import com.minsalud.encuestas.presentation.theme.StatusWarningBg
import com.minsalud.encuestas.presentation.viewmodel.ListaPersonasViewModel
import com.minsalud.encuestas.presentation.viewmodel.PersonaUi
import java.util.Calendar

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ListaPersonasScreen(
    viewModel: ListaPersonasViewModel,
    onNavigateToFormulario: () -> Unit,
    onNavigateToSync: () -> Unit,
    onLogout: () -> Unit,
    onReautenticar: () -> Unit,
    onPersonaClick: (Persona) -> Unit,
    isDark: Boolean,
    onToggleTheme: () -> Unit
) {
    val uiState by viewModel.uiState.collectAsState()
    val requiereReautenticacion by viewModel.requiereReautenticacion.collectAsState()

    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text("ColOffline", fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
                        Text(
                            uiState.nombreEncuestador.ifBlank { "Ministerio de Salud" },
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.background
                ),
                actions = {
                    IconButton(onClick = onToggleTheme) {
                        Text(if (isDark) "☀️" else "🌙", fontSize = 18.sp)
                    }
                    IconButton(onClick = onNavigateToSync) {
                        Icon(Icons.Default.Refresh, contentDescription = "Envío de datos")
                    }
                    IconButton(onClick = onLogout) {
                        Icon(Icons.Default.ExitToApp, contentDescription = "Cerrar sesión")
                    }
                }
            )
        },
        // La acción principal en terracota: el único uso del acento junto con
        // el día de hoy en los gráficos, para que siempre se encuentre.
        floatingActionButton = {
            ExtendedFloatingActionButton(
                onClick = onNavigateToFormulario,
                icon = { Icon(Icons.Default.Add, contentDescription = null) },
                text = { Text("Registrar persona") },
                containerColor = BrandAccent,
                contentColor = Color.White,
                shape = RoundedCornerShape(20.dp)
            )
        }
    ) { padding ->
      Column(modifier = Modifier.padding(padding).fillMaxSize()) {
        if (requiereReautenticacion) AvisoSesionVencida(onIniciarSesion = onReautenticar)
        Box(
            modifier = Modifier
                .weight(1f)
                .fillMaxWidth()
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
                else -> {
                    LazyColumn(
                        modifier = Modifier.fillMaxSize(),
                        contentPadding = PaddingValues(16.dp, 4.dp, 16.dp, 104.dp),
                        verticalArrangement = Arrangement.spacedBy(10.dp)
                    ) {
                        item {
                            val nombre = uiState.nombreEncuestador.substringBefore(' ')
                            Text(
                                if (nombre.isNotBlank()) "Hola, $nombre" else "Hola",
                                style = MaterialTheme.typography.headlineMedium
                            )
                        }
                        item { ResumenHoy(uiState.personas) }
                        item {
                            Row(
                                modifier = Modifier.fillMaxWidth().padding(top = 8.dp),
                                horizontalArrangement = Arrangement.SpaceBetween
                            ) {
                                Text("Personas", style = MaterialTheme.typography.titleMedium)
                                Text(
                                    "${uiState.personas.size}",
                                    style = MaterialTheme.typography.bodyMedium,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant
                                )
                            }
                        }
                        if (uiState.personas.isEmpty()) {
                            item { EmptyState(modifier = Modifier.fillMaxWidth()) }
                        }
                        items(uiState.personas) { item ->
                            PersonaCard(item, onClick = { onPersonaClick(item.persona) })
                        }
                    }
                }
            }
        }
      }
    }
}

/** Tarjeta azul de inicio: lo de hoy, lo que falta por enviar y el total. */
@Composable
private fun ResumenHoy(personas: List<PersonaUi>) {
    val inicioHoy = remember {
        Calendar.getInstance().apply {
            set(Calendar.HOUR_OF_DAY, 0); set(Calendar.MINUTE, 0)
            set(Calendar.SECOND, 0); set(Calendar.MILLISECOND, 0)
        }.timeInMillis
    }
    val hoy = personas.count { it.persona.updatedAt >= inicioHoy }
    val pendientes = personas.count { it.pendiente }

    Surface(
        color = BrandPrimary,
        contentColor = Color.White,
        shape = MaterialTheme.shapes.large,
        modifier = Modifier.fillMaxWidth()
    ) {
        Column(Modifier.padding(20.dp)) {
            Row(verticalAlignment = Alignment.Bottom) {
                Text("$hoy", fontSize = 46.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 46.sp)
                Spacer(Modifier.width(10.dp))
                Text(
                    if (hoy == 1) "persona registrada hoy" else "personas registradas hoy",
                    fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.padding(bottom = 6.dp)
                )
            }
            Spacer(Modifier.height(14.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                DatoHero("$pendientes", "sin enviar", resaltado = pendientes > 0, modifier = Modifier.weight(1f))
                DatoHero("${personas.size}", "en total", resaltado = false, modifier = Modifier.weight(1f))
            }
            Spacer(Modifier.height(12.dp))
            Text(
                if (pendientes > 0) "Todo queda guardado en el teléfono. Se enviará solo cuando haya señal."
                else "Todo lo registrado ya llegó al servidor.",
                style = MaterialTheme.typography.bodyMedium
            )
        }
    }
}

@Composable
private fun DatoHero(valor: String, etiqueta: String, resaltado: Boolean, modifier: Modifier = Modifier) {
    Column(
        modifier
            .background(Color.White.copy(alpha = 0.12f), RoundedCornerShape(14.dp))
            .padding(horizontal = 12.dp, vertical = 10.dp)
    ) {
        Text(
            valor,
            fontSize = 22.sp,
            fontWeight = FontWeight.ExtraBold,
            color = if (resaltado) Color(0xFFF6C77A) else Color.White
        )
        Text(etiqueta, style = MaterialTheme.typography.bodySmall, color = Color.White)
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
        shape = MaterialTheme.shapes.medium,
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant)
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
                    .background(BrandPrimaryTint, CircleShape),
                contentAlignment = Alignment.Center
            ) {
                Text(iniciales.ifBlank { "?" }, color = BrandPrimaryDark, fontWeight = FontWeight.ExtraBold)
            }
            Spacer(Modifier.width(12.dp))
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    "${p.nombres} ${p.apellidos}".trim(),
                    style = MaterialTheme.typography.titleMedium
                )
                Spacer(Modifier.height(2.dp))
                Text(
                    "${p.tipoDocumento.name} ${p.numeroDocumento}",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
            SyncBadge(pendiente = item.pendiente)
        }
    }
}

/** Estado con icono o punto + palabra: el color nunca es la única señal. */
@Composable
private fun SyncBadge(pendiente: Boolean) {
    val bg = if (pendiente) StatusWarningBg else StatusSuccessBg
    val fg = if (pendiente) StatusWarning else StatusSuccess
    Row(
        modifier = Modifier
            .background(bg, RoundedCornerShape(99.dp))
            .padding(horizontal = 10.dp, vertical = 5.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        if (pendiente) {
            Box(Modifier.size(7.dp).background(fg, CircleShape))
        } else {
            Icon(Icons.Default.Check, contentDescription = null, tint = fg, modifier = Modifier.size(13.dp))
        }
        Spacer(Modifier.width(5.dp))
        Text(if (pendiente) "Pendiente" else "Enviada", color = fg, fontSize = 12.sp, fontWeight = FontWeight.Bold)
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
                .background(BrandPrimaryTint, CircleShape),
            contentAlignment = Alignment.Center
        ) {
            Icon(Icons.Default.Add, contentDescription = null, tint = BrandPrimary, modifier = Modifier.size(36.dp))
        }
        Spacer(Modifier.height(16.dp))
        Text("Todavía no hay personas", style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(4.dp))
        Text(
            "Toca \"Registrar persona\" para agregar la primera.",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
    }
}
