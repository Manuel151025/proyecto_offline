package com.minsalud.encuestas.presentation.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

/**
 * Paleta institucional ColOffline.
 *
 * Los mismos valores que usa la PWA en pwa/css/base.css: las dos plataformas
 * son un solo producto y deben verse como tal. Si cambia la marca, se tocan
 * este archivo y los tokens de la PWA, y nada más.
 *
 * Los nombres describen el ROL, no el color. La versión anterior los llamaba
 * `BrandGreen`, y cuando la marca pasó a azul quedaron mintiendo en cada
 * pantalla que los importaba.
 */

// Marca
val BrandPrimary = Color(0xFF12467E)
val BrandPrimaryDark = Color(0xFF0C325C)
val BrandPrimaryTint = Color(0xFFEEF3F9)

// Estados. Coinciden con --success / --warning / --error de la PWA, y son los
// que pintan las insignias de "Sincronizado" y "Pendiente".
val StatusSuccess = Color(0xFF1B7A4B)
val StatusSuccessBg = Color(0xFFE8F5EE)
val StatusWarning = Color(0xFFA15C00)
val StatusWarningBg = Color(0xFFFDF3E4)

// Neutros
private val Fondo = Color(0xFFF2F5F9)
private val Superficie = Color(0xFFFFFFFF)
private val SuperficieAlt = Color(0xFFF7F9FC)
private val TextoPrincipal = Color(0xFF16202C)
private val TextoSecundario = Color(0xFF5B6878)
private val Borde = Color(0xFFC3CDDA)
private val Divisor = Color(0xFFDCE3EC)

private val LightColors = lightColorScheme(
    primary = BrandPrimary,
    onPrimary = Color.White,
    primaryContainer = BrandPrimaryTint,
    onPrimaryContainer = BrandPrimaryDark,
    secondary = StatusSuccess,
    onSecondary = Color.White,
    secondaryContainer = StatusSuccessBg,
    onSecondaryContainer = Color(0xFF0E4A2D),
    background = Fondo,
    onBackground = TextoPrincipal,
    surface = Superficie,
    onSurface = TextoPrincipal,
    surfaceVariant = SuperficieAlt,
    onSurfaceVariant = TextoSecundario,
    outline = Borde,
    outlineVariant = Divisor,
    error = Color(0xFFB3261E),
    onError = Color.White,
    errorContainer = Color(0xFFFCEEEE),
    onErrorContainer = Color(0xFF7A1A15)
)

// En oscuro, el azul institucional no contrasta lo suficiente sobre fondo
// oscuro: primary pasa a una versión clara del mismo tono y el texto que va
// encima se oscurece. Es la inversión que recomienda Material 3.
private val DarkColors = darkColorScheme(
    primary = Color(0xFF9EC0E8),
    onPrimary = Color(0xFF0A2749),
    primaryContainer = Color(0xFF1A3A5C),
    onPrimaryContainer = Color(0xFFD5E4F5),
    secondary = Color(0xFF7FD3A5),
    onSecondary = Color(0xFF00391F),
    secondaryContainer = Color(0xFF14512F),
    onSecondaryContainer = Color(0xFFCDEFDB),
    background = Color(0xFF121417),
    onBackground = Color(0xFFE6E8EB),
    surface = Color(0xFF1B1E22),
    onSurface = Color(0xFFE6E8EB),
    surfaceVariant = Color(0xFF272B30),
    onSurfaceVariant = Color(0xFFB4BCC6),
    outline = Color(0xFF5B6878),
    outlineVariant = Color(0xFF3A4048),
    error = Color(0xFFF2B8B5),
    onError = Color(0xFF601410),
    errorContainer = Color(0xFF8C1D18),
    onErrorContainer = Color(0xFFF9DEDC)
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
