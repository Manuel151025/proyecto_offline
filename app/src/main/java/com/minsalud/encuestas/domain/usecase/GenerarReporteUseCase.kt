package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.core.Result
import com.minsalud.encuestas.domain.model.DomainError
import com.minsalud.encuestas.domain.model.Reporte
import com.minsalud.encuestas.domain.repository.ReporteRepository

class GenerarReporteUseCase(
    private val reporteRepository: ReporteRepository
) {
    suspend operator fun invoke(fechaDesde: Long, fechaHasta: Long): Result<Reporte> {
        return try {
            if (fechaDesde > fechaHasta) {
                return Result.Error(DomainError.InvalidData("La fecha inicial no puede ser mayor a la final"))
            }
            val reporte = reporteRepository.generarReporte(fechaDesde, fechaHasta)
            Result.Success(reporte)
        } catch (e: DomainError) {
            Result.Error(e)
        } catch (e: Exception) {
            Result.Error(DomainError.UnknownError(originalError = e))
        }
    }
}
