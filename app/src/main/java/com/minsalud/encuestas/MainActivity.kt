package com.minsalud.encuestas

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.navigation.compose.rememberNavController
import com.minsalud.encuestas.data.local.prefs.SessionManager
import com.minsalud.encuestas.presentation.navigation.AppNavGraph
import com.minsalud.encuestas.presentation.navigation.Screen
import com.minsalud.encuestas.presentation.theme.ColOfflineTheme
import dagger.hilt.android.AndroidEntryPoint
import javax.inject.Inject

@AndroidEntryPoint
class MainActivity : ComponentActivity() {

    @Inject
    lateinit var sessionManager: SessionManager

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val start = if (sessionManager.isLoggedIn()) Screen.ListaPersonas.route else Screen.Login.route
        setContent {
            // themeMode: 0 = sistema, 1 = claro, 2 = oscuro
            var themeMode by remember { mutableIntStateOf(sessionManager.themeMode()) }
            val isDark = when (themeMode) {
                1 -> false
                2 -> true
                else -> isSystemInDarkTheme()
            }

            ColOfflineTheme(darkTheme = isDark) {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background
                ) {
                    val navController = rememberNavController()
                    AppNavGraph(
                        navController = navController,
                        startDestination = start,
                        onLogout = { sessionManager.clear() },
                        isDark = isDark,
                        onToggleTheme = {
                            themeMode = if (isDark) 1 else 2
                            sessionManager.setThemeMode(themeMode)
                        }
                    )
                }
            }
        }
    }
}
