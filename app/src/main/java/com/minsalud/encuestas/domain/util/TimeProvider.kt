package com.minsalud.encuestas.domain.util

interface TimeProvider {
    fun getCurrentTimeMillis(): Long
}
