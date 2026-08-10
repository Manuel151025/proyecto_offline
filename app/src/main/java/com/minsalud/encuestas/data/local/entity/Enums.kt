package com.minsalud.encuestas.data.local.entity

/** Debe reflejar TipoDocumento del dominio y la lista del servidor. */
enum class TipoDocumentoEntity { CC, TI, RC, CE, PP, NIT, PE }
enum class AccionEncuestaEntity { CREACION, ACTUALIZACION }
/**
 * RECHAZADO es TERMINAL: el servidor dijo que ese registro no es válido y
 * reenviarlo daría siempre el mismo resultado. Sin este estado, un registro
 * irreparable se reintenta en cada sincronización para siempre.
 */
enum class EstadoSyncEntity { PENDING, SENT, ERROR, RECHAZADO }
