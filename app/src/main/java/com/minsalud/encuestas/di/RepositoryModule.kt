package com.minsalud.encuestas.di

import com.minsalud.encuestas.data.repository.*
import com.minsalud.encuestas.data.util.TimeProviderImpl
import com.minsalud.encuestas.data.util.TransactionRunnerImpl
import com.minsalud.encuestas.domain.repository.*
import com.minsalud.encuestas.domain.util.TimeProvider
import com.minsalud.encuestas.domain.util.TransactionRunner
import dagger.Binds
import dagger.Module
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
abstract class RepositoryModule {

    @Binds
    @Singleton
    abstract fun bindPersonaRepository(impl: PersonaRepositoryImpl): PersonaRepository

    @Binds
    @Singleton
    abstract fun bindEncuestaRepository(impl: EncuestaRepositoryImpl): EncuestaRepository

    @Binds
    @Singleton
    abstract fun bindMunicipioRepository(impl: MunicipioRepositoryImpl): MunicipioRepository

    @Binds
    @Singleton
    abstract fun bindSyncRepository(impl: SyncRepositoryImpl): SyncRepository

    @Binds
    @Singleton
    abstract fun bindReporteRepository(impl: ReporteRepositoryImpl): ReporteRepository

    @Binds
    @Singleton
    abstract fun bindAuthRepository(impl: AuthRepositoryImpl): AuthRepository

    @Binds
    @Singleton
    abstract fun bindTimeProvider(impl: TimeProviderImpl): TimeProvider

    @Binds
    @Singleton
    abstract fun bindTransactionRunner(impl: TransactionRunnerImpl): TransactionRunner
}
