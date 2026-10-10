<?php

/** Base de datos desechable y servidor embebido compartidos por todas las pruebas. */
final class Entorno
{
    public const ADMIN_PASSWORD = 'ArranqueTemporal2026';

    /** @var resource|null */
    private static $servidor = null;
    /** @var resource|null */
    private static $smtp = null;
    private static ?PDO $pdo = null;

    public static function host(): string { return getenv('PRUEBAS_DB_HOST') ?: '127.0.0.1'; }
    public static function usuario(): string { return getenv('PRUEBAS_DB_USER') ?: 'root'; }
    public static function clave(): string { return getenv('PRUEBAS_DB_PASS') ?: ''; }
    public static function base(): string { return getenv('PRUEBAS_DB_NAME') ?: 'colo_pruebas'; }
    public static function puerto(): int { return (int)(getenv('PRUEBAS_PUERTO') ?: 8781); }
    public static function url(string $ruta): string { return 'http://127.0.0.1:' . self::puerto() . '/' . ltrim($ruta, '/'); }
    public static function puertoSmtp(): int { return (int)(getenv('PRUEBAS_SMTP_PUERTO') ?: self::puerto() + 2); }
    /** Donde el SMTP falso deja los correos «enviados». */
    public static function archivoCorreos(): string { return sys_get_temp_dir() . '/colo_correos_' . self::puertoSmtp() . '.jsonl'; }

    /**
     * Correos que la API envió desde la última limpieza.
     *
     * @return list<array{para: string, asunto: string, texto: string}>
     */
    public static function correos(): array
    {
        if (!is_file(self::archivoCorreos())) {
            return [];
        }
        $salida = [];
        foreach (file(self::archivoCorreos(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
            $m = json_decode($linea, true);
            $datos = (string)($m['datos'] ?? '');
            preg_match('/^Subject: =\?UTF-8\?B\?([^?]+)\?=/m', $datos, $asunto);
            // La primera parte (text/plain) va en base64.
            preg_match('/text\/plain; charset=UTF-8\r?\nContent-Transfer-Encoding: base64\r?\n\r?\n(.*?)\r?\n--/s', $datos, $texto);
            $salida[] = [
                'para' => (string)($m['para'] ?? ''),
                'asunto' => base64_decode($asunto[1] ?? ''),
                'texto' => base64_decode(preg_replace('/\s+/', '', $texto[1] ?? '') ?? ''),
            ];
        }
        return $salida;
    }

    public static function borrarCorreos(): void
    {
        @unlink(self::archivoCorreos());
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO(
                'mysql:host=' . self::host() . ';dbname=' . self::base() . ';charset=utf8mb4',
                self::usuario(),
                self::clave(),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            // El mismo modo que MySQL 8 en producción: los datos que no caben
            // en la columna dan error en vez de recortarse en silencio.
            self::$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        }
        return self::$pdo;
    }

    public static function preparar(): void
    {
        $raiz = new PDO('mysql:host=' . self::host() . ';charset=utf8mb4', self::usuario(), self::clave(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $raiz->exec('DROP DATABASE IF EXISTS `' . self::base() . '`');
        $raiz->exec('CREATE DATABASE `' . self::base() . '` CHARACTER SET utf8mb4');
        $raiz->exec('USE `' . self::base() . '`');

        // El esquema trae CREATE DATABASE/USE de la base real: se quitan.
        $sql = (string)file_get_contents(__DIR__ . '/../../database/schema.sql');
        $sql = (string)preg_replace('/^(CREATE DATABASE|USE)\b.*$/mi', '', $sql);
        $raiz->exec($sql);

        $env = array_merge(getenv(), [
            'DB_HOST' => self::host(),
            'DB_NAME' => self::base(),
            'DB_USER' => self::usuario(),
            'DB_PASS' => self::clave(),
            'ADMIN_PASSWORD' => self::ADMIN_PASSWORD,
            // Correo hacia el SMTP falso de tests/php/SmtpFalso.php.
            'SMTP_HOST' => '127.0.0.1',
            'SMTP_PUERTO' => (string)self::puertoSmtp(),
            'SMTP_SEGURIDAD' => 'ninguna',
            'SMTP_USUARIO' => 'notificaciones@coloffline.test',
            'SMTP_CLAVE' => 'clave-de-prueba',
        ]);
        $raizProyecto = realpath(__DIR__ . '/../..');
        $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        self::borrarCorreos();
        self::$smtp = proc_open(
            [PHP_BINARY, __DIR__ . '/SmtpFalso.php', (string)self::puertoSmtp(), self::archivoCorreos()],
            [0 => ['file', $nulo, 'r'], 1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']],
            $tuberiasSmtp
        ) ?: null;

        self::$servidor = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'error_reporting=-1', '-S', '127.0.0.1:' . self::puerto(), '-t', $raizProyecto],
            [0 => ['file', $nulo, 'r'], 1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']],
            $tuberias,
            $raizProyecto,
            $env
        ) ?: null;

        // Espera a que el servidor acepte conexiones.
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', self::puerto(), $errno, $errstr, 0.2);
            if ($socket) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('El servidor embebido no arrancó en el puerto ' . self::puerto());
    }

    public static function limpiar(): void
    {
        if (is_resource(self::$smtp)) {
            proc_terminate(self::$smtp);
            proc_close(self::$smtp);
        }
        self::borrarCorreos();
        if (is_resource(self::$servidor)) {
            proc_terminate(self::$servidor);
            proc_close(self::$servidor);
        }
        self::$pdo = null;
        try {
            $raiz = new PDO('mysql:host=' . self::host(), self::usuario(), self::clave());
            $raiz->exec('DROP DATABASE IF EXISTS `' . self::base() . '`');
        } catch (PDOException) {
            // Si la base ya no existe, no hay nada que limpiar.
        }
    }
}
