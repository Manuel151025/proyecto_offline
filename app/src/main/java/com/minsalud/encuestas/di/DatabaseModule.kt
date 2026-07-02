package com.minsalud.encuestas.di

import android.content.Context
import androidx.room.Room
import com.minsalud.encuestas.data.local.AppDatabase
import com.minsalud.encuestas.data.local.dao.ColaSincronizacionDao
import com.minsalud.encuestas.data.local.dao.EncuestaDao
import com.minsalud.encuestas.data.local.dao.MunicipioDao
import com.minsalud.encuestas.data.local.dao.PersonaDao
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.android.qualifiers.ApplicationContext
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
object DatabaseModule {

    @Provides
    @Singleton
    fun provideAppDatabase(@ApplicationContext context: Context): AppDatabase {
        return Room.databaseBuilder(
            context,
            AppDatabase::class.java,
            "encuestas_db"
        ).build() // Sin fallbackToDestructiveMigration
    }

    @Provides
    fun providePersonaDao(db: AppDatabase): PersonaDao = db.personaDao()

    @Provides
    fun provideEncuestaDao(db: AppDatabase): EncuestaDao = db.encuestaDao()

    @Provides
    fun provideColaSincronizacionDao(db: AppDatabase): ColaSincronizacionDao = db.colaSincronizacionDao()

    @Provides
    fun provideMunicipioDao(db: AppDatabase): MunicipioDao = db.municipioDao()
}
