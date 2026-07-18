package com.minsalud.encuestas.data.repository

import android.content.Context
import com.minsalud.encuestas.data.local.dao.PersonaDao
import com.minsalud.encuestas.data.local.entity.PersonaEntity
import com.minsalud.encuestas.domain.model.Reporte
import com.minsalud.encuestas.domain.repository.ReporteRepository
import dagger.hilt.android.qualifiers.ApplicationContext
import java.io.File
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import javax.inject.Inject

/**
 * Genera un archivo CSV real con las personas registradas y lo guarda en el
 * almacenamiento propio de la app (getExternalFilesDir), sin requerir permisos.
 */
class ReporteRepositoryImpl @Inject constructor(
    @ApplicationContext private val context: Context,
    private val personaDao: PersonaDao
) : ReporteRepository {

    override suspend fun generarReporte(fechaDesde: Long, fechaHasta: Long): Reporte {
        val personas = personaDao.getAllPersonasList()
            .filter { it.updatedAt in fechaDesde..fechaHasta || fechaDesde == 0L }

        val dir = File(context.getExternalFilesDir(null), "reportes").apply { mkdirs() }
        val stamp = SimpleDateFormat("yyyyMMdd_HHmmss", Locale.getDefault()).format(Date())
        val file = File(dir, "reporte_$stamp.csv")

        file.bufferedWriter(Charsets.UTF_8).use { w ->
            w.write("﻿") // BOM para que Excel abra los acentos correctamente
            w.write(HEADER.joinToString(","))
            w.newLine()
            personas.forEach { p ->
                w.write(p.toCsvRow())
                w.newLine()
            }
        }

        return Reporte(
            rutaArchivo = file.absolutePath,
            registrosIncluidos = personas.size,
            fechaGeneracion = System.currentTimeMillis()
        )
    }

    private fun PersonaEntity.toCsvRow(): String = listOf(
        tipoDocumento.name,
        numeroDocumento,
        nombres,
        apellidos,
        fechaNacimiento?.let { formatDate(it) } ?: "",
        telefono ?: "",
        email ?: "",
        direccion ?: "",
        vereda ?: "",
        eps ?: "",
        ocupacion ?: "",
        estrato?.toString() ?: "",
        municipioCodigo ?: ""
    ).joinToString(",") { csvEscape(it) }

    private fun csvEscape(value: String): String {
        return if (value.contains(',') || value.contains('"') || value.contains('\n')) {
            "\"" + value.replace("\"", "\"\"") + "\""
        } else value
    }

    private fun formatDate(ms: Long): String =
        SimpleDateFormat("yyyy-MM-dd", Locale.getDefault()).format(Date(ms))

    private companion object {
        val HEADER = listOf(
            "tipo_documento", "numero_documento", "nombres", "apellidos",
            "fecha_nacimiento", "telefono", "email", "direccion", "vereda",
            "eps", "ocupacion", "estrato", "municipio_codigo"
        )
    }
}
