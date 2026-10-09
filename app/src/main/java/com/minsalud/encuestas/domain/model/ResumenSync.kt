package com.minsalud.encuestas.domain.model

/** Resultado de una sincronización: lo que se informa al encuestador. */
data class ResumenSync(
    val enviados: Int = 0,
    val recibidos: Int = 0,
    val rechazados: Int = 0
) {
    val mensaje: String
        get() {
            val partes = buildList {
                if (enviados > 0) add("$enviados enviado(s)")
                if (recibidos > 0) add("$recibidos recibido(s)")
                if (rechazados > 0) add("$rechazados rechazado(s)")
            }
            return if (partes.isEmpty()) "Todo al día" else partes.joinToString(" · ")
        }
}

/** Un registro que el servidor no aceptó, con su motivo. */
data class RechazoPendiente(
    val tipoDocumento: String,
    val numeroDocumento: String,
    val motivo: String
)

/** Cuántos elementos hay en cada estado de la cola, y cuáles fueron rechazados. */
data class EstadoCola(
    val pendientes: Int = 0,
    val conError: Int = 0,
    val rechazados: List<RechazoPendiente> = emptyList()
)
