package com.minsalud.encuestas.domain.sync

/** Qué hacer con una persona que llega del servidor. */
enum class DecisionMezcla { GUARDAR, CONSERVAR }

/**
 * Regla de mezcla al descargar del servidor.
 *
 * Está en el dominio y sin dependencias de Room ni de red porque es la pieza
 * que decide si el trabajo de campo se conserva o se pisa. Un error aquí no
 * produce un mensaje de error: borra en silencio una encuesta recién hecha, y
 * nadie se entera hasta que falta el dato.
 *
 * Es la misma regla que aplica la PWA en `decidirMezcla`, para que ambos
 * clientes resuelvan igual y no se queden discutiendo.
 *
 * @param existeEnLocal false si el dispositivo no conoce esa persona.
 * @param tienePendienteDeEnvio true si la copia local aún no subió.
 * @param updatedAtLocal marca del dispositivo en la copia local.
 * @param updatedAtRemoto marca del dispositivo que escribió la copia del servidor.
 */
fun decidirMezcla(
    existeEnLocal: Boolean,
    tienePendienteDeEnvio: Boolean,
    updatedAtLocal: Long,
    updatedAtRemoto: Long
): DecisionMezcla = when {
    !existeEnLocal -> DecisionMezcla.GUARDAR

    // Cambios locales sin enviar: todavía no llegaron al servidor, así que lo
    // que vuelve es por fuerza anterior. Sobrescribirlos borraría una encuesta
    // recién hecha, aunque el updated_at remoto parezca mayor por un reloj mal
    // puesto en otro dispositivo.
    tienePendienteDeEnvio -> DecisionMezcla.CONSERVAR

    // Last-Write-Wins, el mismo criterio que aplica el servidor al recibir.
    updatedAtRemoto > updatedAtLocal -> DecisionMezcla.GUARDAR

    else -> DecisionMezcla.CONSERVAR
}
