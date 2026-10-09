package com.minsalud.encuestas.presentation.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.minsalud.encuestas.presentation.theme.StatusWarning
import com.minsalud.encuestas.presentation.theme.StatusWarningBg

/**
 * Aviso fijo de sesión vencida o revocada.
 *
 * No cierra la sesión: el encuestador puede seguir registrando sin conexión.
 * Pero mientras no vuelva a entrar con red, la cola no sube, y antes eso
 * ocurría sin que nada lo dijera.
 */
@Composable
fun AvisoSesionVencida(onIniciarSesion: () -> Unit) {
    Surface(color = StatusWarningBg, modifier = Modifier.fillMaxWidth()) {
        Row(
            modifier = Modifier.padding(horizontal = 16.dp, vertical = 10.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text(
                "Tu sesión venció. Conéctate e inicia sesión para enviar lo pendiente. No se borra nada.",
                color = StatusWarning,
                style = MaterialTheme.typography.bodySmall,
                modifier = Modifier.weight(1f)
            )
            OutlinedButton(onClick = onIniciarSesion) { Text("Iniciar sesión") }
        }
    }
}
