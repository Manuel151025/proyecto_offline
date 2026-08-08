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

/**
 * Índice de listado. Las dos consultas del PersonaDao filtran por
 * `deleted_at IS NULL` y ordenan por `updated_at DESC`; sin índice, SQLite
 * recorre la tabla completa y ordena en memoria cada vez que emite el Flow.
 *
 * El nombre y las columnas deben coincidir exactamente con los declarados en
 * PersonaEntity, o Room aborta al validar el esquema en el arranque.
 */
val MIGRATION_2_3 = object : Migration(2, 3) {
    override fun migrate(db: SupportSQLiteDatabase) {
        db.execSQL(
            "CREATE INDEX IF NOT EXISTS idx_personas_listado " +
                "ON personas (deleted_at, updated_at)"
        )
    }
}

/**
 * Marca de agua de la descarga incremental.
 *
 * Se añade como nullable y sin valor por defecto a propósito: los registros
 * que ya existen en el dispositivo se crearon en local y todavía no tienen
 * sello del servidor. Null significa exactamente eso, y ponerles un cero los
 * haría parecer sincronizados desde el principio del tiempo.
 */
val MIGRATION_3_4 = object : Migration(3, 4) {
    override fun migrate(db: SupportSQLiteDatabase) {
        db.execSQL("ALTER TABLE personas ADD COLUMN server_updated_at INTEGER")
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
            .addMigrations(MIGRATION_1_2, MIGRATION_2_3, MIGRATION_3_4) // Sin pérdida de datos
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
