package com.minsalud.encuestas.presentation.navigation

sealed class Screen(val route: String) {
    object Login : Screen("login")

    /** «¿Olvidaste tu contraseña?». Recibe el documento ya escrito en el login. */
    object Recuperar : Screen("recuperar") {
        const val routeWithArgs = "recuperar?doc={doc}"
        fun conDocumento(doc: String) = "recuperar?doc=$doc"
    }
    object ListaPersonas : Screen("lista_personas")
    object EstadoSincronizacion : Screen("estado_sincronizacion")

    object FormularioEncuesta : Screen("formulario_encuesta") {
        // Ruta con argumentos opcionales para el modo edición.
        const val routeWithArgs = "formulario_encuesta?tipo={tipo}&numero={numero}"
        fun edit(tipo: String, numero: String) = "formulario_encuesta?tipo=$tipo&numero=$numero"
    }
}
