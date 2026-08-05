package com.minsalud.encuestas.testutil

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import org.junit.rules.TestWatcher
import org.junit.runner.Description

/**
 * Sustituye Dispatchers.Main por uno de prueba.
 *
 * Los ViewModels lanzan su trabajo en `viewModelScope`, que usa Dispatchers.Main;
 * en una prueba JVM no hay bucle principal de Android y sin esta regla fallan
 * con "Module with the Main dispatcher had failed to initialize".
 *
 * Se usa el dispatcher *unconfined* para que las corrutinas del `init` se
 * ejecuten de inmediato: así el estado ya está listo al leerlo, sin tener que
 * adelantar el reloj en cada prueba.
 */
@OptIn(ExperimentalCoroutinesApi::class)
class MainDispatcherRule(
    private val dispatcher: kotlinx.coroutines.CoroutineDispatcher = UnconfinedTestDispatcher()
) : TestWatcher() {

    override fun starting(description: Description) {
        Dispatchers.setMain(dispatcher)
    }

    override fun finished(description: Description) {
        Dispatchers.resetMain()
    }
}
