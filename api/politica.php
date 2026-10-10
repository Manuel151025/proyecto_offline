<?php
/**
 * Reglas de contraseña compartidas por el panel (crear y editar cuentas) y por
 * la recuperación de contraseña (api/auth/restablecer.php).
 */

/** Longitud mínima al crear o cambiar la contraseña de una cuenta. */
const MIN_LONGITUD_PASSWORD = 10;

/** Minutos que dura un código de recuperación. */
const MINUTOS_CODIGO_RECUPERACION = 15;

/** Intentos con un mismo código antes de anularlo. */
const INTENTOS_CODIGO_RECUPERACION = 5;
