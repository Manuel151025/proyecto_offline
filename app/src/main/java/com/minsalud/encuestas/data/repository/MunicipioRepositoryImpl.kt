package com.minsalud.encuestas.data.repository

import android.content.Context
import com.minsalud.encuestas.data.local.dao.MunicipioDao
import com.minsalud.encuestas.data.local.entity.MunicipioEntity
import com.minsalud.encuestas.data.mapper.toDomain
import com.minsalud.encuestas.data.remote.api.ApiService
import com.minsalud.encuestas.domain.model.Eps
import com.minsalud.encuestas.domain.model.Municipio
import com.minsalud.encuestas.domain.repository.MunicipioRepository
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.withContext
import org.json.JSONObject
import javax.inject.Inject

/**
 * Los catálogos vienen dentro del APK (assets/catalogos/), generados por
 * scripts/catalogos.mjs desde database/catalogos/: los mismos 1.122 municipios
 * y las mismas EPS que la PWA y el servidor. Cada archivo trae su versión; si
 * cambia, la tabla local se reemplaza completa.
 */
class MunicipioRepositoryImpl @Inject constructor(
    private val municipioDao: MunicipioDao,
    private val apiService: ApiService,
    @ApplicationContext private val context: Context
) : MunicipioRepository {

    private class Catalogo(val version: String, val municipios: List<Municipio>)

    private val catalogo: Catalogo by lazy {
        val raiz = JSONObject(leerAsset("catalogos/municipios.json"))
        val filas = raiz.getJSONArray("municipios")
        val municipios = (0 until filas.length()).map { i ->
            val a = filas.getJSONArray(i)
            Municipio(
                codigo = a.getString(0),
                nombre = a.getString(1),
                departamento = a.getString(2),
                principal = a.getInt(3) == 1
            )
        }
        Catalogo(raiz.getString("version"), municipios)
    }

    private val principales: Set<String> by lazy {
        catalogo.municipios.filter { it.principal }.map { it.codigo }.toSet()
    }

    private val eps: List<Eps> by lazy {
        val filas = JSONObject(leerAsset("catalogos/eps.json")).getJSONArray("eps")
        (0 until filas.length()).map { i ->
            val o = filas.getJSONObject(i)
            Eps(o.getString("nombre"), o.getString("detalle"), o.getString("tipo"))
        }
    }

    private fun leerAsset(ruta: String): String =
        context.assets.open(ruta).bufferedReader(Charsets.UTF_8).use { it.readText() }

    override fun getAllMunicipios(): Flow<List<Municipio>> =
        municipioDao.getAllMunicipios().map { lista ->
            lista.map { it.toDomain().copy(principal = it.codigo in principales) }
        }

    override suspend fun syncMunicipios() {
        try {
            val response = apiService.getMunicipios()
            if (response.isSuccessful) {
                response.body()?.let { dtos -> municipioDao.insertAll(dtos.map { it.toEntity() }) }
            }
        } catch (e: Exception) {
            // Sin red se sigue con el catálogo local.
        }
    }

    override suspend fun asegurarCatalogo() = withContext(Dispatchers.IO) {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (prefs.getString(CLAVE_VERSION, null) == catalogo.version && municipioDao.count() > 0) {
            return@withContext
        }
        // Reemplazo completo: también se van nombres viejos con tildes dañadas
        // y códigos que ya no existen.
        municipioDao.reemplazarTodo(catalogo.municipios.map {
            MunicipioEntity(codigo = it.codigo, nombre = it.nombre, departamento = it.departamento)
        })
        prefs.edit().putString(CLAVE_VERSION, catalogo.version).apply()
    }

    override suspend fun getEps(): List<Eps> = withContext(Dispatchers.IO) { eps }

    private companion object {
        const val PREFS = "catalogos"
        const val CLAVE_VERSION = "version_municipios"
    }
}
