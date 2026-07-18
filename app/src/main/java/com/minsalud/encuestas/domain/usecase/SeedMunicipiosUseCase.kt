package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.domain.repository.MunicipioRepository

/**
 * Siembra los municipios base en la BD local si aún no hay ninguno (offline-first).
 * Idempotente: no hace nada si la tabla ya tiene datos.
 */
class SeedMunicipiosUseCase(
    private val municipioRepository: MunicipioRepository
) {
    suspend operator fun invoke() {
        municipioRepository.seedMunicipiosIfEmpty()
    }
}
