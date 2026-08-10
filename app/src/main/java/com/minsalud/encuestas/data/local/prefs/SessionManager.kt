package com.minsalud.encuestas.data.local.prefs

import android.content.Context
import dagger.hilt.android.qualifiers.ApplicationContext
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Persiste la sesión del encuestador en SharedPreferences para que quede
 * "guardada" y no haya que iniciar sesión en cada apertura de la app.
 */
@Singleton
class SessionManager @Inject constructor(
    @ApplicationContext context: Context
) {
    private val prefs = context.getSharedPreferences("coloffline_session", Context.MODE_PRIVATE)

    fun isLoggedIn(): Boolean = prefs.getBoolean(KEY_LOGGED, false)
    fun encuestadorId(): Int = prefs.getInt(KEY_ID, 1)
    fun nombre(): String = prefs.getString(KEY_NOMBRE, "") ?: ""
    fun documento(): String = prefs.getString(KEY_DOC, "") ?: ""

    fun save(id: Int, nombre: String, documento: String) {
        prefs.edit()
            .putBoolean(KEY_LOGGED, true)
            .putInt(KEY_ID, id)
            .putString(KEY_NOMBRE, nombre)
            .putString(KEY_DOC, documento)
            .apply()
    }

    /**
     * Token de API para autenticar la sincronización. Se emite en el login en
     * línea y sobrevive a los inicios de sesión sin conexión, porque el trabajo
     * de campo ocurre offline y la cola se envía cuando vuelve la red.
     * Devuelve null si no hay token o si ya venció.
     */
    fun token(): String? {
        val token = prefs.getString(KEY_TOKEN, null) ?: return null
        val expiraEn = prefs.getLong(KEY_TOKEN_EXP, 0L)
        if (expiraEn > 0L && System.currentTimeMillis() / 1000 > expiraEn) return null
        return token
    }

    fun saveToken(token: String, expiraEn: Long) {
        prefs.edit()
            .putString(KEY_TOKEN, token)
            .putLong(KEY_TOKEN_EXP, expiraEn)
            .apply()
    }

    /**
     * Marca de agua de la descarga incremental: el mayor `server_updated_at`
     * ya recibido. Se pide al servidor lo cambiado DESPUÉS de este valor.
     *
     * Se borra al cerrar sesión junto con el resto (ver `clear`), de modo que
     * el siguiente inicio descarga todo otra vez. Es deliberado: es barato y
     * la mezcla es idempotente, mientras que conservar una marca de otra
     * sesión podría ocultar registros que nunca llegarían.
     */
    fun marcaDescarga(): Long = prefs.getLong(KEY_MARCA_DESCARGA, 0L)

    fun setMarcaDescarga(marca: Long) {
        prefs.edit().putLong(KEY_MARCA_DESCARGA, marca).apply()
    }

    fun clear() {
        // No borramos la preferencia de tema al cerrar sesión.
        val theme = themeMode()
        prefs.edit().clear().putInt(KEY_THEME, theme).apply()
    }

    // Tema: 0 = seguir sistema, 1 = claro, 2 = oscuro
    fun themeMode(): Int = prefs.getInt(KEY_THEME, 0)
    fun setThemeMode(mode: Int) {
        prefs.edit().putInt(KEY_THEME, mode).apply()
    }

    private companion object {
        const val KEY_LOGGED = "logged_in"
        const val KEY_ID = "encuestador_id"
        const val KEY_NOMBRE = "nombre"
        const val KEY_DOC = "documento"
        const val KEY_THEME = "theme_mode"
        const val KEY_TOKEN = "api_token"
        const val KEY_TOKEN_EXP = "api_token_expira_en"
        const val KEY_MARCA_DESCARGA = "marca_descarga"
    }
}
