package com.minsalud.encuestas.data.local.converters

import androidx.room.TypeConverter
import com.minsalud.encuestas.data.local.entity.AccionEncuestaEntity
import com.minsalud.encuestas.data.local.entity.EstadoSyncEntity
import com.minsalud.encuestas.data.local.entity.TipoDocumentoEntity

class RoomConverters {
    @TypeConverter
    fun fromTipoDocumento(value: TipoDocumentoEntity): String = value.name

    @TypeConverter
    fun toTipoDocumento(value: String): TipoDocumentoEntity = TipoDocumentoEntity.valueOf(value)

    @TypeConverter
    fun fromAccionEncuesta(value: AccionEncuestaEntity): String = value.name

    @TypeConverter
    fun toAccionEncuesta(value: String): AccionEncuestaEntity = AccionEncuestaEntity.valueOf(value)

    @TypeConverter
    fun fromEstadoSync(value: EstadoSyncEntity): String = value.name

    @TypeConverter
    fun toEstadoSync(value: String): EstadoSyncEntity = EstadoSyncEntity.valueOf(value)
}
