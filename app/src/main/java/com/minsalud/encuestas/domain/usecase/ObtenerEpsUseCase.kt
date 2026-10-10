package com.minsalud.encuestas.domain.usecase

import com.minsalud.encuestas.domain.model.Eps
import com.minsalud.encuestas.domain.repository.MunicipioRepository

/** EPS del catálogo, para sugerirlas en el formulario. */
class ObtenerEpsUseCase(
    private val municipioRepository: MunicipioRepository
) {
    suspend operator fun invoke(): List<Eps> = municipioRepository.getEps()
}
