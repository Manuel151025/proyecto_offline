<?php

/** F5.1 · Cada encuestador descarga solo sus municipios (si se le asignan). */
final class AlcanceTest extends IntegracionTestCase
{
    private function asignar(int $cuenta, string ...$municipios): void
    {
        Entorno::pdo()->exec('CREATE TABLE IF NOT EXISTS encuestador_municipios (
            id_encuestador INT NOT NULL, municipio_codigo VARCHAR(10) NOT NULL,
            PRIMARY KEY (id_encuestador, municipio_codigo))');
        Entorno::pdo()->exec('DELETE FROM encuestador_municipios');
        $insertar = Entorno::pdo()->prepare('INSERT INTO encuestador_municipios VALUES (?, ?)');
        foreach ($municipios as $m) {
            $insertar->execute([$cuenta, $m]);
        }
    }

    public function testSinMunicipiosAsignadosSeDescargaTodo(): void
    {
        $this->asignar(3);
        $this->assertCount(30, $this->cambios($this->token(3), 'desde=0&limite=500')['personas']);
    }

    public function testConMunicipiosSoloLlegaSuZonaYLoQueElEncuesto(): void
    {
        $this->asignar(3, '11001');
        $esperadas = (int)$this->valor(
            "SELECT COUNT(*) FROM personas p WHERE p.municipio_codigo = '11001'
                OR EXISTS (SELECT 1 FROM encuestas e WHERE e.numero_documento = p.numero_documento AND e.id_encuestador = 3)"
        );

        $personas = $this->cambios($this->token(3), 'desde=0&limite=500')['personas'];

        $this->assertCount($esperadas, $personas);
        $this->assertLessThan(30, count($personas), 'debe excluir personas de otras zonas');
        foreach ($personas as $p) {
            $encuestoEl = (int)$this->valor(
                'SELECT COUNT(*) FROM encuestas WHERE numero_documento = ? AND id_encuestador = 3', $p['numero_documento']
            );
            $this->assertTrue($p['municipio_codigo'] === '11001' || $encuestoEl > 0);
        }
    }

    public function testUnAdministradorSiempreVeTodo(): void
    {
        $this->asignar(2, '11001');
        $this->assertCount(30, $this->cambios($this->token(2), 'desde=0&limite=500')['personas']);
    }

    public function testElPanelGuardaLosMunicipiosDeUnaCuenta(): void
    {
        $c = new Cliente();
        $c->entrarPanel('2000000002', self::CLAVE);
        $campos = ['action' => 'save', 'id' => '3', 'nombre' => 'Luis Fernando Gómez', 'numero_documento' => '3000000003',
            'password' => '', 'rol' => 'encuestador', 'activo' => 'on'];
        $consulta = http_build_query($campos + ['csrf' => $c->csrf('index.php?seccion=cuentas&editar=3')])
            . '&municipios%5B%5D=19001&municipios%5B%5D=52835';
        $r = $c->pedir('POST', 'api/admin/index.php?seccion=cuentas', $consulta, ['Content-Type: application/x-www-form-urlencoded']);

        $this->assertSame(303, $r['codigo']);
        $guardados = Entorno::pdo()->query('SELECT municipio_codigo FROM encuestador_municipios WHERE id_encuestador = 3 ORDER BY 1')
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['19001', '52835'], $guardados);
        $this->assertStringContainsString('2 municipios', $c->get('api/admin/index.php?seccion=cuentas')['cuerpo']);
    }
}
