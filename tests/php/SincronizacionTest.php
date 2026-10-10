<?php

/**
 * Sincronización: los fallos que se demostraron en el análisis del 8 de
 * octubre de 2026, convertidos en pruebas para que no vuelvan.
 */
final class SincronizacionTest extends IntegracionTestCase
{
    public function testSinTokenOConTokenFalsoResponde401(): void
    {
        $sinToken = (new Cliente())->pedir('POST', 'api/personas/sync.php', '{}');
        $falso = (new Cliente())->pedir('POST', 'api/personas/sync.php', '{}', ['Authorization: Bearer x']);
        $this->assertSame(401, $sinToken['codigo']);
        $this->assertSame(401, $falso['codigo']);
    }

    public function testLoteValidoSeGuardaConElSelloDelServidor(): void
    {
        $r = $this->sincronizar($this->lote(3, '111000'), $this->token());
        $this->assertSame(200, $r['codigo']);
        $this->assertCount(3, $r['json']['processed_encuestas']);
        $this->assertSame(3, (int)$this->valor("SELECT COUNT(*) FROM personas WHERE numero_documento LIKE '111000%'"));
    }

    /** El fallo crítico: una página que corta un grupo con el mismo sello. */
    public function testElCursorCompuestoNoPierdePersonasDeUnMismoLote(): void
    {
        $token = $this->token();
        Entorno::pdo()->exec('UPDATE personas SET server_updated_at = NULL'); // solo interesa el lote nuevo
        $this->sincronizar($this->lote(5, '222000'), $token);
        $this->assertSame(1, (int)$this->valor("SELECT COUNT(DISTINCT server_updated_at) FROM personas WHERE numero_documento LIKE '222000%'"));

        $recibidas = [];
        $consulta = 'desde=0&limite=2';
        for ($pagina = 0; $pagina < 10; $pagina++) {
            $datos = $this->cambios($token, $consulta);
            foreach ($datos['personas'] as $p) {
                $recibidas[] = $p['numero_documento'];
            }
            if (!$datos['hay_mas']) {
                break;
            }
            $c = $datos['cursor'];
            $consulta = http_build_query(['desde' => $c['sello'], 'tipo' => $c['tipo'], 'numero' => $c['numero'], 'limite' => 2]);
        }

        $this->assertCount(5, array_unique($recibidas), 'Con el cursor compuesto deben llegar las 5 personas');
    }

    public function testLaMarcaNumericaSigueFuncionandoParaClientesViejos(): void
    {
        $datos = $this->cambios($this->token(), 'desde=0&limite=500');
        $this->assertCount(30, $datos['personas']);
        $this->assertIsInt($datos['marca']);
    }

    public function testUnLoteDeMasDe500SeRechazaCon413(): void
    {
        $this->assertSame(413, $this->sincronizar($this->lote(501, '333'), $this->token())['codigo']);
    }

    public function testUnMunicipioDesconocidoSoloRechazaSuFilaYElRestoSeGuarda(): void
    {
        $valida = $this->lote(1, '444100');
        $mala = $this->lote(1, '444200', ['municipio_codigo' => '99999']);
        $lote = [
            'personas' => array_merge($valida['personas'], $mala['personas']),
            'encuestas' => array_merge($valida['encuestas'], $mala['encuestas']),
        ];

        $r = $this->sincronizar($lote, $this->token());

        $this->assertSame(200, $r['codigo']);
        $this->assertCount(1, $r['json']['rechazadas']);
        $this->assertStringContainsString('Municipio no reconocido', $r['json']['rechazadas'][0]['motivo']);
        $this->assertSame(1, (int)$this->valor("SELECT COUNT(*) FROM personas WHERE numero_documento = '4441000000'"));
        $this->assertSame(0, (int)$this->valor("SELECT COUNT(*) FROM personas WHERE numero_documento = '4442000000'"));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function datosInvalidos(): array
    {
        return [
            'estrato fuera de rango' => [['estrato' => 9], 'estrato'],
            'correo inválido' => [['email' => 'esto-no-es-correo'], 'correo'],
            'nombre con números' => [['nombres' => 'Ana 2'], 'nombres'],
            'nacimiento futuro' => [['fecha_nacimiento' => (time() + 30 * 86400) * 1000], 'nacimiento'],
            'documento corto' => [['numero_documento' => '123'], 'documento'],
            'cédula con letras' => [['numero_documento' => 'abc12345'], 'dígitos'],
            'apellido con signos' => [['apellidos' => 'Velasquez.,s'], 'apellidos'],
            'teléfono con letras' => [['telefono' => 'saddc'], 'teléfono'],
            'teléfono de 7 dígitos' => [['telefono' => '6012345'], 'teléfono'],
            'vereda muy corta' => [['vereda' => 'ab'], 'vereda'],
        ];
    }

    /**
     * @param array<string, mixed> $cambios
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('datosInvalidos')]
    public function testDatosInvalidosSeRechazanPorFila(array $cambios, string $palabra): void
    {
        $lote = $this->lote(1, '555000', $cambios);
        if (isset($cambios['numero_documento'])) {
            $lote['encuestas'][0]['numero_documento'] = $cambios['numero_documento'];
        }

        $r = $this->sincronizar($lote, $this->token());

        $this->assertSame(200, $r['codigo']);
        $this->assertCount(1, $r['json']['rechazadas']);
        $this->assertStringContainsStringIgnoringCase($palabra, $r['json']['rechazadas'][0]['motivo']);
    }

    public function testLosRechazosQuedanGuardadosParaElMonitor(): void
    {
        $this->sincronizar($this->lote(1, '666000', ['estrato' => 9]), $this->token(3), ['X-Device-Id: celular-luis']);

        $fila = Entorno::pdo()->query('SELECT * FROM sync_rechazos')->fetch();
        $this->assertIsArray($fila);
        $this->assertSame('6660000000', $fila['numero_documento']);
        $this->assertSame('celular-luis', $fila['device_id']);
        $this->assertSame(3, (int)$fila['id_encuestador']);
    }

    public function testElCelularQuedaRegistradoConPlataformaYVersion(): void
    {
        $token = $this->token(3);
        $cabeceras = ['X-Device-Id: celular-luis', 'X-Plataforma: android', 'X-App-Version: 1.2.0'];
        $this->sincronizar($this->lote(1, '777000'), $token, $cabeceras);
        (new Cliente())->pedir('GET', 'api/personas/cambios.php?desde=0', null, array_merge(["Authorization: Bearer $token"], $cabeceras));

        $fila = Entorno::pdo()->query("SELECT * FROM dispositivos WHERE device_id = 'celular-luis'")->fetch();
        $this->assertIsArray($fila);
        $this->assertSame('android', $fila['plataforma']);
        $this->assertSame('1.2.0', $fila['version_app']);
        $this->assertNotNull($fila['ultima_subida']);
        $this->assertNotNull($fila['ultima_descarga']);
    }

    public function testUnRelojAdelantadoNoBloqueaLasCorreccionesReales(): void
    {
        $token = $this->token();
        $futuro = (int)(microtime(true) * 1000) + 365 * 86400000;
        $this->sincronizar($this->lote(1, '888000', ['updated_at' => $futuro, 'nombres' => 'Adelantado']), $token);
        usleep(10000);
        // Una corrección hecha más de 5 minutos "después" del recorte.
        $correccion = (int)(microtime(true) * 1000) + 6 * 60 * 1000;
        $this->sincronizar($this->lote(1, '888000', ['updated_at' => $correccion, 'nombres' => 'Corregido']), $token);

        $this->assertSame('Corregido', $this->valor("SELECT nombres FROM personas WHERE numero_documento = '8880000000'"));
    }

    public function testLastWriteWinsIgnoraUnaVersionMasVieja(): void
    {
        $token = $this->token();
        $ahora = (int)(microtime(true) * 1000);
        $this->sincronizar($this->lote(1, '999000', ['updated_at' => $ahora, 'nombres' => 'Nueva']), $token);
        $this->sincronizar($this->lote(1, '999000', ['updated_at' => $ahora - 60000, 'nombres' => 'Vieja']), $token);

        $this->assertSame('Nueva', $this->valor("SELECT nombres FROM personas WHERE numero_documento = '9990000000'"));
    }

    public function testElEndpointDeSaludResponde(): void
    {
        $r = (new Cliente())->get('api/health.php');
        $this->assertSame(200, $r['codigo']);
        $this->assertSame('ok', json_decode($r['cuerpo'], true)['base_de_datos']);
    }
}
