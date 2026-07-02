package com.minsalud.encuestas.presentation.navigation

sealed class Screen(val route: String) {
    object ListaPersonas : Screen("lista_personas")
    object FormularioEncuesta : Screen("formulario_encuesta")
    object EstadoSincronizacion : Screen("estado_sincronizacion")
}
