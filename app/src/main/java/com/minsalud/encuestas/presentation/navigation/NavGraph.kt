package com.minsalud.encuestas.presentation.navigation

import androidx.compose.runtime.Composable
import androidx.compose.ui.platform.LocalContext
import com.minsalud.encuestas.worker.SyncWorkerScheduler
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.navigation.NavHostController
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.navArgument
import com.minsalud.encuestas.presentation.ui.EstadoSincronizacionScreen
import com.minsalud.encuestas.presentation.ui.FormularioEncuestaScreen
import com.minsalud.encuestas.presentation.ui.ListaPersonasScreen
import com.minsalud.encuestas.presentation.ui.LoginScreen

@Composable
fun AppNavGraph(
    navController: NavHostController,
    startDestination: String,
    onLogout: () -> Unit,
    isDark: Boolean,
    onToggleTheme: () -> Unit
) {
    NavHost(
        navController = navController,
        startDestination = startDestination
    ) {
        composable(Screen.Login.route) {
            val context = LocalContext.current
            LoginScreen(
                viewModel = hiltViewModel(),
                onLoginSuccess = {
                    // Con un token nuevo, lo que quedó pendiente sube ya.
                    SyncWorkerScheduler.triggerImmediateSync(context)
                    navController.navigate(Screen.ListaPersonas.route) {
                        popUpTo(0) { inclusive = true }
                    }
                }
            )
        }

        composable(Screen.ListaPersonas.route) {
            ListaPersonasScreen(
                viewModel = hiltViewModel(),
                onNavigateToFormulario = { navController.navigate(Screen.FormularioEncuesta.route) },
                onNavigateToSync = { navController.navigate(Screen.EstadoSincronizacion.route) },
                onLogout = {
                    onLogout()
                    navController.navigate(Screen.Login.route) {
                        popUpTo(0) { inclusive = true }
                    }
                },
                // Volver a entrar SIN cerrar la sesión: la cola y los datos se conservan.
                onReautenticar = { navController.navigate(Screen.Login.route) },
                onPersonaClick = { persona ->
                    navController.navigate(
                        Screen.FormularioEncuesta.edit(persona.tipoDocumento.name, persona.numeroDocumento)
                    )
                },
                isDark = isDark,
                onToggleTheme = onToggleTheme
            )
        }

        composable(
            route = Screen.FormularioEncuesta.routeWithArgs,
            arguments = listOf(
                navArgument("tipo") { type = NavType.StringType; nullable = true; defaultValue = null },
                navArgument("numero") { type = NavType.StringType; nullable = true; defaultValue = null }
            )
        ) {
            FormularioEncuestaScreen(
                viewModel = hiltViewModel(),
                onNavigateBack = { navController.popBackStack() }
            )
        }

        composable(Screen.EstadoSincronizacion.route) {
            EstadoSincronizacionScreen(
                viewModel = hiltViewModel(),
                onNavigateBack = { navController.popBackStack() }
            )
        }
    }
}
