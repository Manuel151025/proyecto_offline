package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.domain.repository.MunicipioRepository

/**
 * Pone el catálogo local de municipios al día con el que trae la app.
 * Idempotente: si la versión ya coincide, no hace nada.
 */
class SeedMunicipiosUseCase(
    private val municipioRepository: MunicipioRepository
) {
    suspend operator fun invoke() {
        municipioRepository.asegurarCatalogo()
    }
}
