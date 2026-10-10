package com.minsalud.encuestas.domain.model

/**
 * Municipio del catálogo DIVIPOLA.
 *
 * @property principal capital de departamento o ciudad de las más pobladas;
 *   son las que se ofrecen antes de escribir nada.
 */
data class Municipio(
    val codigo: String,
    val nombre: String,
    val departamento: String,
    val principal: Boolean = false
) {
    /**
     * La capital de cada departamento tiene código XX001, salvo Cundinamarca:
     * 25001 es Agua de Dios y su capital es Bogotá.
     */
    val capital: Boolean get() = codigo.endsWith("001") && codigo != "25001"
}

/** EPS del catálogo. Si la de la persona no está, el formulario permite escribirla. */
data class Eps(
    val nombre: String,
    val detalle: String,
    val tipo: String
)
