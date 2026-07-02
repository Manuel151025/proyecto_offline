package com.minsalud.encuestas.domain.repository

import com.minsalud.encuestas.domain.model.Reporte

interface ReporteRepository {
    suspend fun generarReporte(fechaDesde: Long, fechaHasta: Long): Reporte
}
