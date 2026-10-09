package com.minsalud.encuestas.data.local.prefs

import android.content.Context
import dagger.hilt.android.qualifiers.ApplicationContext
import java.util.UUID
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Identificador estable de ESTE teléfono.
 *
 * Antes el formulario enviaba "DEVICE_ID_LOCAL" fijo: para el servidor, todos
 * los Android eran el mismo dispositivo y la trazabilidad de cada encuesta
 * quedaba rota.
 *
 * Es un UUID aleatorio, no ANDROID_ID ni el IMEI: identifica la instalación sin
 * exponer datos del hardware. Vive en su propio archivo para sobrevivir al
 * cierre de sesión, y está excluido de las copias de seguridad para que
 * restaurar en otro teléfono no clone el identificador.
 */
@Singleton
class DispositivoManager @Inject constructor(
    @ApplicationContext context: Context
) {
    private val prefs = context.getSharedPreferences(ARCHIVO, Context.MODE_PRIVATE)

    @Synchronized
    fun deviceId(): String {
        prefs.getString(CLAVE, null)?.let { return it }
        val nuevo = "android_" + UUID.randomUUID()
        prefs.edit().putString(CLAVE, nuevo).commit()
        return nuevo
    }

    companion object {
        const val ARCHIVO = "coloffline_dispositivo"
        private const val CLAVE = "device_id"
    }
}
