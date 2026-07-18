package com.minsalud.encuestas.presentation.navigation

sealed class Screen(val route: String) {
    object Login : Screen("login")
    object ListaPersonas : Screen("lista_personas")
    object EstadoSincronizacion : Screen("estado_sincronizacion")

    object FormularioEncuesta : Screen("formulario_encuesta") {
        // Ruta con argumentos opcionales para el modo edición.
        const val routeWithArgs = "formulario_encuesta?tipo={tipo}&numero={numero}"
        fun edit(tipo: String, numero: String) = "formulario_encuesta?tipo=$tipo&numero=$numero"
    }
}
