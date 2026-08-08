package com.minsalud.encuestas.data.local.entity

/** Debe reflejar TipoDocumento del dominio y la lista del servidor. */
enum class TipoDocumentoEntity { CC, TI, RC, CE, PP, NIT, PE }
enum class AccionEncuestaEntity { CREACION, ACTUALIZACION }
enum class EstadoSyncEntity { PENDING, SENT, ERROR }
