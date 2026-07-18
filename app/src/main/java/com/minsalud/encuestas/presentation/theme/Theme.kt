package com.minsalud.encuestas.presentation.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

// Colores de marca ColOffline (verde institucional del Ministerio de Salud)
val BrandGreen = Color(0xFF0E7A41)
val BrandGreenDark = Color(0xFF0B5E32)
val BrandGreenLight = Color(0xFFE6F4EC)
val AccentBlue = Color(0xFF1565C0)
val WarnAmber = Color(0xFFB26A00)
val WarnAmberBg = Color(0xFFFFF3E0)

private val LightColors = lightColorScheme(
    primary = BrandGreen,
    onPrimary = Color.White,
    primaryContainer = BrandGreenLight,
    onPrimaryContainer = BrandGreenDark,
    secondary = AccentBlue,
    onSecondary = Color.White,
    background = Color(0xFFF5F7FA),
    onBackground = Color(0xFF1A1A2E),
    surface = Color.White,
    onSurface = Color(0xFF1A1A2E),
    surfaceVariant = Color(0xFFEDF1F5),
    onSurfaceVariant = Color(0xFF5A6470),
    error = Color(0xFFC62828)
)

private val DarkColors = darkColorScheme(
    primary = Color(0xFF57C98C),
    onPrimary = Color(0xFF00391C),
    primaryContainer = Color(0xFF0B5E32),
    onPrimaryContainer = Color(0xFFCDEFDB),
    secondary = Color(0xFF7FB0F0),
    background = Color(0xFF121417),
    onBackground = Color(0xFFE6E8EB),
    surface = Color(0xFF1B1E22),
    onSurface = Color(0xFFE6E8EB),
    surfaceVariant = Color(0xFF272B30),
    onSurfaceVariant = Color(0xFFB4BcC6)
)

@Composable
fun ColOfflineTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit
) {
    MaterialTheme(
        colorScheme = if (darkTheme) DarkColors else LightColors,
        content = content
    )
}
