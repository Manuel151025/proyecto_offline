<?php
/**
 * Envío de correo por SMTP, sin dependencias.
 *
 * La imagen de producción no instala paquetes de Composer (vendor/ queda
 * fuera del contexto de Docker), así que no hay PHPMailer. Este cliente
 * cubre lo que hace falta: Gmail u otro proveedor con usuario y contraseña,
 * por SSL directo (puerto 465) o STARTTLS (587).
 *
 * Configuración por variables de entorno (ver docs/DESPLIEGUE.md):
 *   SMTP_HOST        smtp.gmail.com
 *   SMTP_PUERTO      465
 *   SMTP_USUARIO     la cuenta de Gmail que envía
 *   SMTP_CLAVE       su contraseña de aplicación (16 letras)
 *   SMTP_REMITENTE   dirección que aparece como remitente (por defecto, SMTP_USUARIO)
 *   SMTP_NOMBRE      nombre que aparece como remitente (por defecto «ColOffline»)
 *   SMTP_SEGURIDAD   ssl | starttls | ninguna (por defecto: ssl en 465, starttls en otro puerto)
 *
 * «ninguna» solo existe para las pruebas, que hablan con un servidor SMTP falso.
 */

/** ¿Hay un servidor de correo configurado? */
function correoConfigurado(): bool
{
    return (getenv('SMTP_HOST') ?: '') !== '' && (getenv('SMTP_USUARIO') ?: '') !== '';
}

/**
 * Envía un correo con versión en texto y en HTML.
 *
 * Devuelve false si algo falla (el motivo va al log del servidor): quien llama
 * decide qué decirle al usuario, sin exponer detalles del servidor de correo.
 */
function enviarCorreo(string $para, string $asunto, string $texto, string $html): bool
{
    if (!correoConfigurado() || !filter_var($para, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $host = (string)getenv('SMTP_HOST');
    $puerto = (int)(getenv('SMTP_PUERTO') ?: 465);
    $usuario = (string)getenv('SMTP_USUARIO');
    $clave = (string)getenv('SMTP_CLAVE');
    $remitente = (string)(getenv('SMTP_REMITENTE') ?: $usuario);
    $nombre = (string)(getenv('SMTP_NOMBRE') ?: 'ColOffline');
    $seguridad = (string)(getenv('SMTP_SEGURIDAD') ?: ($puerto === 465 ? 'ssl' : 'starttls'));

    $destino = ($seguridad === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $puerto;
    $conexion = @stream_socket_client($destino, $errno, $error, 15);
    if ($conexion === false) {
        error_log("[correo] no se pudo conectar a $destino: $error");
        return false;
    }
    stream_set_timeout($conexion, 15);

    try {
        smtpEsperar($conexion, 220);
        smtpComando($conexion, 'EHLO ' . (gethostname() ?: 'coloffline'), 250);
        if ($seguridad === 'starttls') {
            smtpComando($conexion, 'STARTTLS', 220);
            if (!stream_socket_enable_crypto($conexion, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('no se pudo activar TLS');
            }
            smtpComando($conexion, 'EHLO ' . (gethostname() ?: 'coloffline'), 250);
        }
        if ($clave !== '') {
            smtpComando($conexion, 'AUTH LOGIN', 334);
            smtpComando($conexion, base64_encode($usuario), 334);
            smtpComando($conexion, base64_encode($clave), 235);
        }
        smtpComando($conexion, "MAIL FROM:<$remitente>", 250);
        smtpComando($conexion, "RCPT TO:<$para>", 250);
        smtpComando($conexion, 'DATA', 354);
        smtpEnviar($conexion, armarMensaje($remitente, $nombre, $para, $asunto, $texto, $html) . "\r\n.");
        smtpEsperar($conexion, 250);
        smtpComando($conexion, 'QUIT', 221);
        return true;
    } catch (RuntimeException $e) {
        error_log('[correo] ' . $e->getMessage());
        return false;
    } finally {
        fclose($conexion);
    }
}

/** Arma el mensaje MIME (texto + HTML) con cabeceras en UTF-8. */
function armarMensaje(string $remitente, string $nombre, string $para, string $asunto, string $texto, string $html): string
{
    $frontera = 'col-' . bin2hex(random_bytes(8));
    $codificar = fn (string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
    $cuerpo = fn (string $s) => rtrim(chunk_split(base64_encode($s), 76, "\r\n"));
    $lineas = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . $codificar($nombre) . " <$remitente>",
        "To: <$para>",
        'Subject: ' . $codificar($asunto),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $remitente)[1] ?? 'coloffline') . '>',
        'MIME-Version: 1.0',
        "Content-Type: multipart/alternative; boundary=\"$frontera\"",
        '',
        "--$frontera",
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        $cuerpo($texto),
        "--$frontera",
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        $cuerpo($html),
        "--$frontera--",
    ];
    return implode("\r\n", $lineas);
}

/** @param resource $conexion */
function smtpEnviar($conexion, string $linea): void
{
    if (fwrite($conexion, $linea . "\r\n") === false) {
        throw new RuntimeException('no se pudo escribir en el servidor SMTP');
    }
}

/** @param resource $conexion */
function smtpEsperar($conexion, int $codigoEsperado): void
{
    $respuesta = '';
    while (($linea = fgets($conexion, 1024)) !== false) {
        $respuesta .= $linea;
        // Las respuestas de varias líneas usan «250-»; la última, «250 ».
        if (strlen($linea) < 4 || $linea[3] === ' ') {
            break;
        }
    }
    if ((int)substr($respuesta, 0, 3) !== $codigoEsperado) {
        // Nunca se registra la respuesta a AUTH: podría reflejar la clave.
        throw new RuntimeException("el servidor SMTP respondió «" . trim(substr($respuesta, 0, 120)) . "» (se esperaba $codigoEsperado)");
    }
}

/** @param resource $conexion */
function smtpComando($conexion, string $comando, int $codigoEsperado): void
{
    smtpEnviar($conexion, $comando);
    smtpEsperar($conexion, $codigoEsperado);
}

/**
 * Plantilla del correo con el código para cambiar la contraseña.
 *
 * @return array{0: string, 1: string} [texto, html]
 */
function plantillaCodigo(string $nombre, string $codigo, int $minutos): array
{
    $nombreSeguro = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
    $texto = "Hola, $nombre.\r\n\r\n"
        . "Tu código para cambiar la contraseña de ColOffline es: $codigo\r\n\r\n"
        . "Escríbelo en la app junto con tu contraseña nueva. Vence en $minutos minutos.\r\n\r\n"
        . "Si no pediste cambiar tu contraseña, ignora este correo: tu contraseña actual sigue funcionando.\r\n\r\n"
        . "ColOffline · Encuestas demográficas";
    $html = <<<HTML
<!doctype html>
<html lang="es"><body style="margin:0;padding:24px;background:#F6F4EF;font-family:Arial,Helvetica,sans-serif;color:#1C2430">
  <table role="presentation" width="100%" style="max-width:480px;margin:0 auto;background:#FFFFFF;border:1px solid #E4E0D8;border-radius:16px">
    <tr><td style="background:#12467E;border-radius:16px 16px 0 0;padding:20px 24px;color:#FFFFFF;font-size:18px;font-weight:bold">ColOffline</td></tr>
    <tr><td style="padding:24px">
      <p style="margin:0 0 12px;font-size:16px">Hola, {$nombreSeguro}.</p>
      <p style="margin:0 0 16px;font-size:15px;line-height:1.5">Tu código para cambiar la contraseña es:</p>
      <p style="margin:0 0 16px;font-size:34px;font-weight:bold;letter-spacing:8px;color:#12467E;text-align:center">{$codigo}</p>
      <p style="margin:0 0 16px;font-size:15px;line-height:1.5">Escríbelo en la app junto con tu contraseña nueva. Vence en <strong>{$minutos} minutos</strong>.</p>
      <p style="margin:0;font-size:13px;line-height:1.5;color:#5A6370">Si no pediste cambiar tu contraseña, ignora este correo: tu contraseña actual sigue funcionando.</p>
    </td></tr>
    <tr><td style="padding:14px 24px;border-top:1px solid #E4E0D8;font-size:12px;color:#5A6370">ColOffline · Encuestas demográficas</td></tr>
  </table>
</body></html>
HTML;
    return [$texto, $html];
}

/**
 * Aviso de que la contraseña cambió: si no fue la persona, se entera.
 *
 * @return array{0: string, 1: string} [texto, html]
 */
function plantillaContrasenaCambiada(string $nombre): array
{
    $nombreSeguro = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
    $texto = "Hola, $nombre.\r\n\r\n"
        . "La contraseña de tu cuenta de ColOffline acaba de cambiar y se cerró la sesión en tus celulares.\r\n\r\n"
        . "Si no fuiste tú, avisa de inmediato a tu administrador.\r\n\r\n"
        . "ColOffline · Encuestas demográficas";
    $html = <<<HTML
<!doctype html>
<html lang="es"><body style="margin:0;padding:24px;background:#F6F4EF;font-family:Arial,Helvetica,sans-serif;color:#1C2430">
  <table role="presentation" width="100%" style="max-width:480px;margin:0 auto;background:#FFFFFF;border:1px solid #E4E0D8;border-radius:16px">
    <tr><td style="background:#12467E;border-radius:16px 16px 0 0;padding:20px 24px;color:#FFFFFF;font-size:18px;font-weight:bold">ColOffline</td></tr>
    <tr><td style="padding:24px;font-size:15px;line-height:1.5">
      <p style="margin:0 0 12px">Hola, {$nombreSeguro}.</p>
      <p style="margin:0 0 12px">La contraseña de tu cuenta acaba de cambiar y se cerró la sesión en tus celulares.</p>
      <p style="margin:0;color:#B3261E"><strong>Si no fuiste tú, avisa de inmediato a tu administrador.</strong></p>
    </td></tr>
  </table>
</body></html>
HTML;
    return [$texto, $html];
}
