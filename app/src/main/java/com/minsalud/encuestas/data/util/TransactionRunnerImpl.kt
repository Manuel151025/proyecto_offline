package com.minsalud.encuestas.data.util

import androidx.room.withTransaction
import com.minsalud.encuestas.data.local.AppDatabase
import com.minsalud.encuestas.domain.util.TransactionRunner
import javax.inject.Inject

class TransactionRunnerImpl @Inject constructor(
    private val db: AppDatabase
) : TransactionRunner {
    override suspend fun <T> invoke(block: suspend () -> T): T {
        return db.withTransaction { block() }
    }
}
