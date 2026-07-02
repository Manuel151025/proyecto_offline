package com.minsalud.encuestas.di

import com.minsalud.encuestas.domain.repository.*
import com.minsalud.encuestas.domain.usecase.*
import com.minsalud.encuestas.domain.util.TimeProvider
import com.minsalud.encuestas.domain.util.TransactionRunner
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent

@Module
@InstallIn(SingletonComponent::class)
object UseCaseModule {

    @Provides
    fun provideGuardarPersonaUseCase(
        personaRepository: PersonaRepository,
        timeProvider: TimeProvider
    ): GuardarPersonaUseCase = GuardarPersonaUseCase(personaRepository, timeProvider)

    @Provides
    fun provideObtenerPersonaUseCase(
        personaRepository: PersonaRepository
    ): ObtenerPersonaUseCase = ObtenerPersonaUseCase(personaRepository)

    @Provides
    fun provideObtenerPersonasUseCase(
        personaRepository: PersonaRepository
    ): ObtenerPersonasUseCase = ObtenerPersonasUseCase(personaRepository)

    @Provides
    fun provideEliminarPersonaUseCase(
        personaRepository: PersonaRepository,
        timeProvider: TimeProvider
    ): EliminarPersonaUseCase = EliminarPersonaUseCase(personaRepository, timeProvider)

    @Provides
    fun provideRegistrarEncuestaUseCase(
        encuestaRepository: EncuestaRepository
    ): RegistrarEncuestaUseCase = RegistrarEncuestaUseCase(encuestaRepository)

    @Provides
    fun provideSincronizarPendientesUseCase(
        syncRepository: SyncRepository
    ): SincronizarPendientesUseCase = SincronizarPendientesUseCase(syncRepository)

    @Provides
    fun provideObtenerMunicipiosUseCase(
        municipioRepository: MunicipioRepository
    ): ObtenerMunicipiosUseCase = ObtenerMunicipiosUseCase(municipioRepository)

    @Provides
    fun provideSincronizarMunicipiosUseCase(
        municipioRepository: MunicipioRepository
    ): SincronizarMunicipiosUseCase = SincronizarMunicipiosUseCase(municipioRepository)

    @Provides
    fun provideGenerarReporteUseCase(
        reporteRepository: ReporteRepository
    ): GenerarReporteUseCase = GenerarReporteUseCase(reporteRepository)

    @Provides
    fun provideGuardarRegistroCompletoUseCase(
        personaRepository: PersonaRepository,
        encuestaRepository: EncuestaRepository,
        syncRepository: SyncRepository,
        timeProvider: TimeProvider,
        transactionRunner: TransactionRunner
    ): GuardarRegistroCompletoUseCase = GuardarRegistroCompletoUseCase(
        personaRepository, encuestaRepository, syncRepository, timeProvider, transactionRunner
    )
}
