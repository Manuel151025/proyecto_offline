package com.minsalud.encuestas.domain.model

sealed class DomainError(message: String) : Exception(message) {
    class InvalidData(message: String = "Datos inválidos") : DomainError(message)
    class NotFound(message: String = "Recurso no encontrado") : DomainError(message)
    class NetworkError(message: String = "Error de red") : DomainError(message)
    class UnknownError(message: String = "Error desconocido", val originalError: Exception? = null) : DomainError(message)
}
