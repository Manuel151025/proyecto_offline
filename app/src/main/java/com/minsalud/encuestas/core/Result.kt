package com.minsalud.encuestas.core

import com.minsalud.encuestas.domain.model.DomainError

sealed class Result<out T> {
    data class Success<out T>(val data: T) : Result<T>()
    data class Error(val error: DomainError) : Result<Nothing>()
}
