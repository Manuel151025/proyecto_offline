package com.minsalud.encuestas.domain.model

data class ColaSincronizacion(
    val idCola: Int,
    val idEncuesta: String,
    val payload: String,
    val estado: EstadoSync,
    val intentos: Int,
    val ultimoError: String?
)
