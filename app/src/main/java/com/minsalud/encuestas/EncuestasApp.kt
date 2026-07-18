package com.minsalud.encuestas

import android.app.Application
import androidx.hilt.work.HiltWorkerFactory
import androidx.work.Configuration
import com.minsalud.encuestas.worker.SyncWorkerScheduler
import dagger.hilt.android.HiltAndroidApp
import javax.inject.Inject

@HiltAndroidApp
class EncuestasApp : Application(), Configuration.Provider {

    @Inject
    lateinit var workerFactory: HiltWorkerFactory

    override val workManagerConfiguration: Configuration
        get() = Configuration.Builder()
            .setWorkerFactory(workerFactory)
            .build()

    override fun onCreate() {
        super.onCreate()
        // Sincronización periódica de respaldo + un intento inmediato (se ejecuta
        // apenas haya red gracias a la constraint CONNECTED) para vaciar pendientes.
        SyncWorkerScheduler.schedulePeriodicSync(this)
        SyncWorkerScheduler.triggerImmediateSync(this)
    }
}
