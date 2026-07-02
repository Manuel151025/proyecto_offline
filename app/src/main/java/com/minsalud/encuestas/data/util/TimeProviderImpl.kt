package com.minsalud.encuestas.data.util

import com.minsalud.encuestas.domain.util.TimeProvider
import javax.inject.Inject

class TimeProviderImpl @Inject constructor() : TimeProvider {
    override fun getCurrentTimeMillis(): Long = System.currentTimeMillis()
}
