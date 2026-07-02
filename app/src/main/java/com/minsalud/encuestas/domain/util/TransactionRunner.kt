package com.minsalud.encuestas.domain.util

interface TransactionRunner {
    suspend operator fun <T> invoke(block: suspend () -> T): T
}
