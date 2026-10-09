package com.minsalud.encuestas.data.local.prefs

import android.content.Context
import com.google.gson.Gson
import dagger.hilt.android.qualifiers.ApplicationContext
import java.security.MessageDigest
import java.security.SecureRandom
import javax.crypto.SecretKeyFactory
import javax.crypto.spec.PBEKeySpec
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Credencial guardada tras un login EN LÍNEA correcto, para poder entrar
 * después sin conexión. Nunca guarda la contraseña: solo una sal y su hash.
 */
data class CredencialGuardada(
    val documento: String,
    val idEncuestador: Int,
    val nombre: String,
    val sal: String,
    val hash: String
)

/** Dónde se guardan las credenciales para el login sin conexión. */
interface AlmacenCredenciales {
    fun guardar(credencial: CredencialGuardada)
    fun buscar(documento: String): CredencialGuardada?
}

/**
 * Hash de contraseña para el login sin conexión.
 *
 * PBKDF2 con sal y miles de iteraciones, no un SHA-256 directo: quien copie el
 * almacenamiento del teléfono no puede probar millones de contraseñas por
 * segundo. Se usa la variante HmacSHA1 porque HmacSHA256 no existe antes de
 * Android 8 y la app admite Android 7 (minSdk 24); con sal e iteraciones,
 * SHA-1 sigue siendo adecuado dentro de PBKDF2.
 */
object HashCredencial {
    private const val ITERACIONES = 10_000
    private const val BITS = 256

    fun nuevaSal(): String = ByteArray(16).also { SecureRandom().nextBytes(it) }.aHex()

    fun calcular(password: String, salHex: String): String {
        val spec = PBEKeySpec(password.toCharArray(), salHex.deHex(), ITERACIONES, BITS)
        return SecretKeyFactory.getInstance("PBKDF2WithHmacSHA1").generateSecret(spec).encoded.aHex()
    }

    /** Comparación en tiempo constante. */
    fun coincide(password: String, credencial: CredencialGuardada): Boolean =
        MessageDigest.isEqual(
            calcular(password, credencial.sal).toByteArray(),
            credencial.hash.toByteArray()
        )

    private fun ByteArray.aHex() = joinToString("") { "%02x".format(it) }
    private fun String.deHex() = chunked(2).map { it.toInt(16).toByte() }.toByteArray()
}

/**
 * Credenciales en SharedPreferences, en un archivo propio: sobreviven al cierre
 * de sesión (para volver a entrar sin red) y están excluidas de las copias de
 * seguridad (ver res/xml).
 */
@Singleton
class CredencialesLocales @Inject constructor(
    @ApplicationContext context: Context
) : AlmacenCredenciales {
    private val prefs = context.getSharedPreferences(ARCHIVO, Context.MODE_PRIVATE)
    private val gson = Gson()

    override fun guardar(credencial: CredencialGuardada) {
        prefs.edit().putString(credencial.documento, gson.toJson(credencial)).apply()
    }

    override fun buscar(documento: String): CredencialGuardada? =
        prefs.getString(documento, null)?.let {
            runCatching { gson.fromJson(it, CredencialGuardada::class.java) }.getOrNull()
        }

    companion object {
        const val ARCHIVO = "coloffline_credenciales"
    }
}
