package com.minsalud.encuestas.data.local

import androidx.room.Database
import androidx.room.RoomDatabase
import androidx.room.TypeConverters
import com.minsalud.encuestas.data.local.converters.RoomConverters
import com.minsalud.encuestas.data.local.dao.ColaSincronizacionDao
import com.minsalud.encuestas.data.local.dao.EncuestaDao
import com.minsalud.encuestas.data.local.dao.MunicipioDao
import com.minsalud.encuestas.data.local.dao.PersonaDao
import com.minsalud.encuestas.data.local.entity.ColaSincronizacionEntity
import com.minsalud.encuestas.data.local.entity.EncuestaEntity
import com.minsalud.encuestas.data.local.entity.MunicipioEntity
import com.minsalud.encuestas.data.local.entity.PersonaEntity

@Database(
    entities = [
        PersonaEntity::class,
        EncuestaEntity::class,
        ColaSincronizacionEntity::class,
        MunicipioEntity::class
    ],
    version = 1,
    exportSchema = true
)
@TypeConverters(RoomConverters::class)
abstract class AppDatabase : RoomDatabase() {
    abstract fun personaDao(): PersonaDao
    abstract fun encuestaDao(): EncuestaDao
    abstract fun colaSincronizacionDao(): ColaSincronizacionDao
    abstract fun municipioDao(): MunicipioDao
    
    // Configurado sin fallbackToDestructiveMigration() para permitir migraciones versionadas
}
