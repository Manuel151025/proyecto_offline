package com.minsalud.encuestas.presentation.navigation

import androidx.compose.runtime.Composable
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import com.minsalud.encuestas.presentation.ui.EstadoSincronizacionScreen
import com.minsalud.encuestas.presentation.ui.FormularioEncuestaScreen
import com.minsalud.encuestas.presentation.ui.ListaPersonasScreen

@Composable
fun AppNavGraph(navController: NavHostController) {
    NavHost(
        navController = navController,
        startDestination = Screen.ListaPersonas.route
    ) {
        composable(Screen.ListaPersonas.route) {
            ListaPersonasScreen(
                viewModel = hiltViewModel(),
                onNavigateToFormulario = { navController.navigate(Screen.FormularioEncuesta.route) },
                onNavigateToSync = { navController.navigate(Screen.EstadoSincronizacion.route) }
            )
        }
        
        composable(Screen.FormularioEncuesta.route) {
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
