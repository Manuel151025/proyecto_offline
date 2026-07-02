package com.minsalud.encuestas.data.repository

import com.minsalud.encuestas.domain.model.Reporte
import com.minsalud.encuestas.domain.repository.ReporteRepository
import javax.inject.Inject

class ReporteRepositoryImpl @Inject constructor() : ReporteRepository {
    override suspend fun generarReporte(fechaDesde: Long, fechaHasta: Long): Reporte {
        // ImplementaciÃ³n fÃ­sica simulada
        return Reporte(
            rutaArchivo = "/storage/emulated/0/Download/reporte.csv",
            registrosIncluidos = 0,
            fechaGeneracion = System.currentTimeMillis()
        )
    }
}
