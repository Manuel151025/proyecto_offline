<?php
/** Ayudantes compartidos por recuperar.php y restablecer.php. */

/** Mismo formato de documento que exige login.php. */
function documentoDeCuentaValido(string $documento): bool
{
    return $documento !== '' && mb_strlen($documento) <= 20 && preg_match('/^[A-Za-z0-9\-]+$/', $documento) === 1;
}

/**
 * Clave del contador de intentos para la recuperación.
 *
 * Va en la misma tabla que los intentos de inicio de sesión (columna de 20
 * caracteres). Lleva «#», que un documento no puede tener, así que nunca
 * coincide con el contador del login de nadie.
 */
function claveLimiteRecuperacion(string $paso, string $documento): string
{
    return substr($paso, 0, 1) . '#' . substr(sha1($documento), 0, 17);
}
