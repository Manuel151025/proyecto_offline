<?php

/**
 * El catálogo de municipios se completa y corrige solo (api/esquema.php).
 *
 * Producción tenía 162 municipios cargados a mano y la app guardaba esa lista
 * para siempre. Ahora la tabla se pone al día con api/municipios/catalogo.php
 * cuando su versión no coincide con la guardada en `ajustes`.
 */
final class CatalogoTest extends IntegracionTestCase
{
    public function testUnaBaseConElCatalogoViejoSeCompletaYCorrigeSola(): void
    {
        $pdo = Entorno::pdo();
        // Simula producción antes de este cambio: sin versión, municipios de
        // menos y un nombre con la tilde dañada.
        $pdo->exec('CREATE TABLE IF NOT EXISTS ajustes (clave VARCHAR(40) PRIMARY KEY, valor VARCHAR(100) NOT NULL)');
        $pdo->exec('DELETE FROM ajustes');
        $pdo->exec("DELETE FROM municipios WHERE departamento = 'Vaupés' AND codigo <> '97001'");
        $pdo->exec("UPDATE municipios SET nombre = 'PopayÃ¡n' WHERE codigo = '19001'");
        $this->assertLessThan(1122, (int)$this->valor('SELECT COUNT(*) FROM municipios'));

        $r = (new Cliente())->get('api/municipios/index.php');
        $lista = json_decode($r['cuerpo'], true);

        $this->assertSame(200, $r['codigo']);
        $this->assertIsArray($lista);
        $this->assertCount(1122, $lista);
        $porCodigo = array_column($lista, 'nombre', 'codigo');
        $this->assertSame('Popayán', $porCodigo['19001']);
        $this->assertSame('Mitú', $porCodigo['97001']);
        $this->assertSame(33, count(array_unique(array_column($lista, 'departamento'))));
        $this->assertNotEmpty($this->valor("SELECT valor FROM ajustes WHERE clave = 'catalogo_municipios'"));
    }

    public function testUnCodigoQueNoExisteSePasaAlCorrectoYSeRetira(): void
    {
        $pdo = Entorno::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS ajustes (clave VARCHAR(40) PRIMARY KEY, valor VARCHAR(100) NOT NULL)');
        $pdo->exec('DELETE FROM ajustes');
        // Lo que tenía producción: Miraflores (Guaviare) con un código inventado.
        $pdo->exec("INSERT INTO municipios (codigo, nombre, departamento) VALUES ('95040', 'Miraflores', 'Guaviare')");
        $pdo->exec("UPDATE personas SET municipio_codigo = '95040', updated_at = 1, server_updated_at = 1 WHERE numero_documento = '10200300'");

        $r = (new Cliente())->get('api/municipios/index.php');
        $lista = json_decode($r['cuerpo'], true);

        $this->assertIsArray($lista);
        $this->assertCount(1122, $lista);
        $this->assertSame(0, (int)$this->valor("SELECT COUNT(*) FROM municipios WHERE codigo = '95040'"));
        $this->assertSame('95200', $this->valor("SELECT municipio_codigo FROM personas WHERE numero_documento = '10200300'"));
        // Sellada para que los celulares que la tienen reciban el código correcto.
        $this->assertGreaterThan(1, (int)$this->valor("SELECT updated_at FROM personas WHERE numero_documento = '10200300'"));
        $this->assertGreaterThan(1, (int)$this->valor("SELECT server_updated_at FROM personas WHERE numero_documento = '10200300'"));
    }

    public function testUnCelularConElCatalogoNuevoPuedeEnviarCualquierMunicipio(): void
    {
        $pdo = Entorno::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS ajustes (clave VARCHAR(40) PRIMARY KEY, valor VARCHAR(100) NOT NULL)');
        $pdo->exec('DELETE FROM ajustes');
        $pdo->exec("DELETE FROM municipios WHERE codigo = '97161'"); // Carurú, Vaupés

        $r = $this->sincronizar($this->lote(1, '777000', ['municipio_codigo' => '97161']), $this->token());

        $this->assertSame(200, $r['codigo']);
        $this->assertSame([], $r['json']['rechazadas']);
        $this->assertSame('97161', $this->valor("SELECT municipio_codigo FROM personas WHERE numero_documento = '7770000000'"));
    }
}
