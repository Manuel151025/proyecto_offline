package com.minsalud.encuestas.presentation.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.minsalud.encuestas.R

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
val BrandPrimaryTint = Color(0xFFE8EFF7)

// Acento terracota: solo la acción de registrar y el día de hoy en gráficos.
val BrandAccent = Color(0xFFB4532A)
val BrandAccentTint = Color(0xFFF8E9E1)

// Estados. Coinciden con --success / --warning / --error de la PWA, y son los
// que pintan las insignias de "Sincronizado" y "Pendiente".
val StatusSuccess = Color(0xFF1E6B44)
val StatusSuccessBg = Color(0xFFE5F3EB)
val StatusWarning = Color(0xFF8A5200)
val StatusWarningBg = Color(0xFFFBF0DC)

// Neutros
private val Fondo = Color(0xFFF6F4EF)
private val Superficie = Color(0xFFFFFFFF)
private val SuperficieAlt = Color(0xFFFBFAF7)
private val TextoPrincipal = Color(0xFF1C2430)
private val TextoSecundario = Color(0xFF5A6370)
private val Borde = Color(0xFFD8D3C9)
private val Divisor = Color(0xFFE4E0D8)

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

/**
 * Figtree, empaquetada en res/font: la misma de la PWA y el panel, y sin
 * descargas en tiempo de ejecución (la app tiene que verse igual sin señal).
 */
val Figtree = FontFamily(
    Font(R.font.figtree_400, FontWeight.Normal),
    Font(R.font.figtree_600, FontWeight.SemiBold),
    Font(R.font.figtree_700, FontWeight.Bold),
    Font(R.font.figtree_800, FontWeight.ExtraBold)
)

private val Base = Typography()

// Cuerpo de 16 sp y títulos en negrita fuerte: se lee de pie y al sol.
private val Tipografia = Typography(
    displaySmall = Base.displaySmall.copy(fontFamily = Figtree, fontWeight = FontWeight.ExtraBold),
    headlineLarge = Base.headlineLarge.copy(fontFamily = Figtree, fontWeight = FontWeight.ExtraBold),
    headlineMedium = Base.headlineMedium.copy(fontFamily = Figtree, fontWeight = FontWeight.ExtraBold),
    headlineSmall = Base.headlineSmall.copy(fontFamily = Figtree, fontWeight = FontWeight.ExtraBold),
    titleLarge = Base.titleLarge.copy(fontFamily = Figtree, fontWeight = FontWeight.ExtraBold),
    titleMedium = Base.titleMedium.copy(fontFamily = Figtree, fontWeight = FontWeight.Bold),
    titleSmall = Base.titleSmall.copy(fontFamily = Figtree, fontWeight = FontWeight.Bold),
    bodyLarge = Base.bodyLarge.copy(fontFamily = Figtree, fontSize = 16.sp),
    bodyMedium = Base.bodyMedium.copy(fontFamily = Figtree, fontSize = 15.sp),
    bodySmall = Base.bodySmall.copy(fontFamily = Figtree, fontSize = 13.sp),
    labelLarge = Base.labelLarge.copy(fontFamily = Figtree, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp),
    labelMedium = Base.labelMedium.copy(fontFamily = Figtree, fontWeight = FontWeight.Bold),
    labelSmall = Base.labelSmall.copy(fontFamily = Figtree, fontWeight = FontWeight.Bold)
)

// Esquinas generosas, como en la PWA (--radius-sm 14, --radius 18, --radius-lg 22).
private val Formas = Shapes(
    extraSmall = RoundedCornerShape(10.dp),
    small = RoundedCornerShape(14.dp),
    medium = RoundedCornerShape(18.dp),
    large = RoundedCornerShape(22.dp),
    extraLarge = RoundedCornerShape(28.dp)
)

@Composable
fun ColOfflineTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit
) {
    MaterialTheme(
        colorScheme = if (darkTheme) DarkColors else LightColors,
        typography = Tipografia,
        shapes = Formas,
        content = content
    )
}
