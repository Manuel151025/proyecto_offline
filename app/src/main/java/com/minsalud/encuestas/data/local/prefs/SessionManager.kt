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
    }
}
