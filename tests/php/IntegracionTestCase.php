<?php

use PHPUnit\Framework\TestCase;

/** Base de las pruebas: datos conocidos antes de cada prueba. */
abstract class IntegracionTestCase extends TestCase
{
    public const CLAVE = 'ClaveSegura2026';

    protected function setUp(): void
    {
        $this->sembrar();
    }

    /** Cuentas, personas y encuestas de ejemplo. Con $arranque no queda ningún admin activo. */
    protected function sembrar(bool $arranque = false): void
    {
        $pdo = Entorno::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['encuestas', 'personas', 'sesiones', 'intentos_login', 'encuestadores'] as $tabla) {
            $pdo->exec("TRUNCATE $tabla");
        }
        foreach (['sync_rechazos', 'dispositivos', 'auditoria_admin'] as $tabla) {
            $pdo->exec("DROP TABLE IF EXISTS $tabla");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $hash = password_hash(self::CLAVE, PASSWORD_BCRYPT, ['cost' => 4]);
        $cuentas = [
            [1, 'Docente Demo', '1000000001', 'encuestador', 1],
            [2, 'Ana María Rojas', '2000000002', $arranque ? 'encuestador' : 'admin', 1],
            [3, 'Luis Fernando Gómez', '3000000003', 'encuestador', 1],
            [4, 'Carolina Pérez', '4000000004', 'encuestador', 0],
            [5, 'Jorge Iván Castro', '5000000005', 'admin', $arranque ? 0 : 1],
        ];
        $insertar = $pdo->prepare('INSERT INTO encuestadores (id, nombre, numero_documento, password_hash, rol, activo) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($cuentas as [$id, $nombre, $doc, $rol, $activo]) {
            $insertar->execute([$id, $nombre, $doc, $hash, $rol, $activo]);
        }

        $nombres = ['Juan', 'María', 'Pedro', 'Luz Dary', 'Andrés', 'Sandra'];
        $apellidos = ['Pérez Martínez', 'Gómez López', 'Rodríguez Díaz', 'Torres Hernández'];
        $ahora = (int)(microtime(true) * 1000);
        $persona = $pdo->prepare('INSERT INTO personas (tipo_documento, numero_documento, nombres, apellidos, fecha_nacimiento,
            telefono, estrato, municipio_codigo, updated_at, server_updated_at, device_id, deleted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $encuesta = $pdo->prepare('INSERT INTO encuestas (id, tipo_documento, numero_documento, id_encuestador, fecha_encuesta,
            device_id, accion, server_sync_time) VALUES (?,?,?,?,?,?,?,?)');
        for ($i = 0; $i < 30; $i++) {
            $doc = (string)(10200300 + $i * 7);
            $t = $ahora - $i * 3600 * 1000;
            $ape = $i === 4 ? '=HIPERVINCULO("http://x")' : $apellidos[$i % 4];
            $persona->execute(['CC', $doc, $nombres[$i % 6], $ape, gmmktime(0, 0, 0, 3, 15, 1980 + $i) * 1000,
                '+57300' . $i, 1 + $i % 6, $i % 5 ? '05001' : '11001', $t, $t, 'pwa-' . ($i % 3), $i >= 28 ? $t : null]);
            $encuesta->execute(["enc-$i", 'CC', $doc, [1, 3][$i % 2], $t, 'pwa-' . ($i % 3), 'REGISTRO', $t]);
        }
    }

    /** Emite un token de API para la cuenta y lo devuelve en claro. */
    protected function token(int $idCuenta = 1): string
    {
        $token = bin2hex(random_bytes(16));
        Entorno::pdo()->prepare('INSERT INTO sesiones (token_hash, id_encuestador, creado_en, expira_en) VALUES (?, ?, ?, ?)')
            ->execute([hash('sha256', $token), $idCuenta, time(), time() + 86400]);
        return $token;
    }

    protected function valor(string $sql, mixed ...$params): mixed
    {
        $stmt = Entorno::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * Lote de sincronización con $n personas válidas.
     *
     * @param array<string, mixed> $cambios campos que sustituyen a los de cada persona
     * @return array{personas: list<array<string, mixed>>, encuestas: list<array<string, mixed>>}
     */
    protected function lote(int $n, string $prefijo, array $cambios = []): array
    {
        $ahora = (int)(microtime(true) * 1000);
        $lote = ['personas' => [], 'encuestas' => []];
        for ($i = 0; $i < $n; $i++) {
            $doc = $prefijo . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
            $lote['personas'][] = array_merge([
                'tipo_documento' => 'CC', 'numero_documento' => $doc, 'nombres' => 'Ana', 'apellidos' => 'Prueba',
                'municipio_codigo' => '05001', 'estrato' => 3, 'updated_at' => $ahora, 'device_id' => 'prueba',
            ], $cambios);
            $lote['encuestas'][] = [
                'id' => "e-$doc-" . bin2hex(random_bytes(3)), 'tipo_documento' => 'CC', 'numero_documento' => $doc,
                'fecha_encuesta' => $ahora, 'device_id' => 'prueba', 'accion' => 'REGISTRO',
            ];
        }
        return $lote;
    }

    /**
     * @param array<string, mixed> $lote
     * @param list<string> $cabeceras
     * @return array{codigo: int, json: array<string, mixed>}
     */
    protected function sincronizar(array $lote, string $token, array $cabeceras = []): array
    {
        $r = (new Cliente())->pedir('POST', 'api/personas/sync.php', (string)json_encode($lote),
            array_merge(["Authorization: Bearer $token"], $cabeceras));
        return ['codigo' => $r['codigo'], 'json' => (array)json_decode($r['cuerpo'], true)];
    }

    /** @return array<string, mixed> */
    protected function cambios(string $token, string $consulta): array
    {
        $r = (new Cliente())->pedir('GET', "api/personas/cambios.php?$consulta", null, ["Authorization: Bearer $token"]);
        $this->assertSame(200, $r['codigo'], $r['cuerpo']);
        return (array)json_decode($r['cuerpo'], true);
    }
}
