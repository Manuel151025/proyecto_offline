package com.minsalud.encuestas.di

import android.content.Context
import androidx.room.Room
import androidx.room.migration.Migration
import androidx.sqlite.db.SupportSQLiteDatabase
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

/**
 * Migración v1 -> v2: agrega la columna 'vereda' a personas, para dar paridad
 * con el backend y la PWA. Nullable, así que no requiere valor por defecto.
 */
val MIGRATION_1_2 = object : Migration(1, 2) {
    override fun migrate(db: SupportSQLiteDatabase) {
        db.execSQL("ALTER TABLE personas ADD COLUMN vereda TEXT")
    }
}

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
        )
            .addMigrations(MIGRATION_1_2) // Migración versionada, sin pérdida de datos
            .build()
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
