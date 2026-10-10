<?php
/**
 * Servidor SMTP falso para las pruebas: acepta cualquier correo y lo guarda,
 * una línea JSON por mensaje, en el archivo indicado. No envía nada a nadie.
 *
 *   php tests/php/SmtpFalso.php <puerto> <archivo>
 *
 * Habla lo justo del protocolo para el cliente de api/correo.php: EHLO, AUTH
 * LOGIN, MAIL FROM, RCPT TO, DATA y QUIT, sin cifrado (SMTP_SEGURIDAD=ninguna).
 */
[, $puerto, $archivo] = $argv + [null, '8783', sys_get_temp_dir() . '/colo_correos.jsonl'];

$servidor = stream_socket_server("tcp://127.0.0.1:$puerto", $errno, $error);
if ($servidor === false) {
    fwrite(STDERR, "No se pudo abrir el puerto $puerto: $error\n");
    exit(1);
}

while (true) {
    $c = @stream_socket_accept($servidor, -1);
    if ($c === false) {
        continue;
    }
    $escribir = fn (string $l) => fwrite($c, $l . "\r\n");
    $escribir('220 smtp-falso listo');
    $para = '';
    $datos = null;
    while (($linea = fgets($c)) !== false) {
        if ($datos !== null) {
            if (rtrim($linea, "\r\n") === '.') {
                file_put_contents($archivo, json_encode(['para' => $para, 'datos' => $datos]) . "\n", FILE_APPEND | LOCK_EX);
                $datos = null;
                $escribir('250 recibido');
            } else {
                $datos .= $linea;
            }
            continue;
        }
        $comando = strtoupper(substr(trim($linea), 0, 4));
        if ($comando === 'EHLO' || $comando === 'HELO') {
            $escribir('250-smtp-falso');
            $escribir('250 AUTH LOGIN');
        } elseif ($comando === 'AUTH') {
            $escribir('334 VXNlcm5hbWU6');
            fgets($c);
            $escribir('334 UGFzc3dvcmQ6');
            fgets($c);
            $escribir('235 autenticado');
        } elseif ($comando === 'MAIL') {
            $escribir('250 ok');
        } elseif ($comando === 'RCPT') {
            preg_match('/<([^>]+)>/', $linea, $m);
            $para = $m[1] ?? '';
            $escribir('250 ok');
        } elseif ($comando === 'DATA') {
            $datos = '';
            $escribir('354 adelante');
        } elseif ($comando === 'QUIT') {
            $escribir('221 adiós');
            break;
        } else {
            $escribir('250 ok');
        }
    }
    fclose($c);
}
