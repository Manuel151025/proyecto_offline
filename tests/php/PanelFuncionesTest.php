<?php

/** Funciones del panel añadidas en la fase F4 del plan de trabajo. */
final class PanelFuncionesTest extends IntegracionTestCase
{
    private function admin(): Cliente
    {
        $c = new Cliente();
        $c->entrarPanel('2000000002', self::CLAVE);
        return $c;
    }

    private const FICHA = 'index.php?seccion=persona&tipo=CC&numero=10200300';

    // --- Ficha de persona ----------------------------------------------------

    public function testLaFichaMuestraTodosLosDatosYElHistorial(): void
    {
        $html = $this->admin()->get('api/admin/' . self::FICHA)['cuerpo'];
        $this->assertStringContainsString('Juan Pérez Martínez', $html);
        $this->assertStringContainsString('+573000', $html);          // teléfono, antes solo en el CSV
        $this->assertStringContainsString('15/03/1980', $html);        // nacimiento sin correrse un día
        $this->assertStringContainsString('Historial de encuestas', $html);
        $this->assertStringContainsString('Docente Demo', $html);      // quién la encuestó
    }

    public function testUnaPersonaInexistenteVuelveALaListaConAviso(): void
    {
        $c = $this->admin();
        $r = $c->get('api/admin/index.php?seccion=persona&tipo=CC&numero=999');
        $this->assertSame(303, $r['codigo']);
        $this->assertStringContainsString('Esa persona no existe', $c->get('api/admin/index.php?seccion=personas')['cuerpo']);
    }

    // --- Edición -------------------------------------------------------------

    /** @return array<string, string> */
    private function camposEdicion(array $cambios = []): array
    {
        return array_merge([
            'action' => 'editar_persona', 'tipo_documento' => 'CC', 'numero_documento' => '10200300',
            'nombres' => 'Juan Carlos', 'apellidos' => 'Pérez Martínez', 'fecha_nacimiento' => '1980-03-15',
            'telefono' => '3001234567', 'email' => 'juan@correo.co', 'direccion' => 'Calle 1', 'vereda' => 'El Carmen',
            'eps' => 'Nueva EPS', 'ocupacion' => 'Agricultor', 'estrato' => '2', 'municipio_codigo' => '19001',
        ], $cambios);
    }

    public function testEditarUnaPersonaLaSellaParaLosCelularesYQuedaAuditado(): void
    {
        $antes = (int)$this->valor("SELECT server_updated_at FROM personas WHERE numero_documento = '10200300'");
        $c = $this->admin();

        $r = $c->enviarPanel($this->camposEdicion(), self::FICHA, self::FICHA);

        $this->assertSame(303, $r['codigo']);
        $fila = Entorno::pdo()->query("SELECT * FROM personas WHERE numero_documento = '10200300'")->fetch();
        $this->assertSame('Juan Carlos', $fila['nombres']);
        $this->assertSame('19001', $fila['municipio_codigo']);
        $this->assertSame(gmmktime(0, 0, 0, 3, 15, 1980) * 1000, (int)$fila['fecha_nacimiento']);
        $this->assertGreaterThan($antes, (int)$fila['server_updated_at']);
        $this->assertSame('admin:Ana María Rojas', $fila['device_id']);

        $html = $c->get('api/admin/' . self::FICHA)['cuerpo'];
        $this->assertStringContainsString('Editó una persona', $html);
        $this->assertStringContainsString('«Juan» → «Juan Carlos»', $html);
    }

    public function testLaEdicionAplicaLasMismasReglasQueLaSincronizacion(): void
    {
        $r = $this->admin()->enviarPanel($this->camposEdicion(['estrato' => '9']), self::FICHA, self::FICHA);

        $this->assertSame(200, $r['codigo']);
        $this->assertStringContainsString('El estrato debe estar entre 1 y 6', $r['cuerpo']);
        $this->assertStringContainsString('value="Juan Carlos"', $r['cuerpo'], 'lo escrito se conserva');
        $this->assertSame('Juan', $this->valor("SELECT nombres FROM personas WHERE numero_documento = '10200300'"));
    }

    public function testLaEdicionLlegaALosCelularesPorLaDescarga(): void
    {
        $token = $this->token();
        $marca = (int)$this->valor('SELECT MAX(server_updated_at) FROM personas');
        $this->admin()->enviarPanel($this->camposEdicion(), self::FICHA, self::FICHA);

        $datos = $this->cambios($token, "desde=$marca");

        $this->assertSame(['10200300'], array_column($datos['personas'], 'numero_documento'));
        $this->assertSame('Juan Carlos', $datos['personas'][0]['nombres']);
    }

    // --- Filtros y CSV -------------------------------------------------------

    public function testFiltrarPorMunicipioYEncuestador(): void
    {
        $c = $this->admin();
        $html = $c->get('api/admin/index.php?seccion=personas&municipio=11001')['cuerpo'];
        // 30 personas sembradas: 1 de cada 5 en Bogotá (11001), menos las 2 borradas (28 y 29 no lo son).
        $this->assertStringContainsString('6 resultados con los filtros aplicados', $html);

        $html = $c->get('api/admin/index.php?seccion=personas&encuestador=3')['cuerpo'];
        $this->assertMatchesRegularExpression('/1[0-9] resultados con los filtros aplicados/', $html);
    }

    public function testElCsvExportaSoloLoFiltrado(): void
    {
        $csv = $this->admin()->get('api/admin/index.php?exportar=personas&municipio=11001')['cuerpo'];
        $lineas = array_filter(explode("\n", trim($csv)));
        $this->assertCount(7, $lineas, 'cabecera + 6 personas de Bogotá');
    }

    // --- Cuentas -------------------------------------------------------------

    public function testCerrarLasSesionesDeUnCelular(): void
    {
        $token = $this->token(3);
        $r = $this->admin()->enviarPanel(['action' => 'cerrar_sesiones', 'id' => '3'], 'index.php?seccion=cuentas&editar=3');

        $this->assertSame(303, $r['codigo']);
        $this->assertSame(0, (int)$this->valor('SELECT COUNT(*) FROM sesiones WHERE id_encuestador = 3'));
        $sinToken = (new Cliente())->pedir('GET', 'api/personas/cambios.php', null, ["Authorization: Bearer $token"]);
        $this->assertSame(401, $sinToken['codigo'], 'el celular deja de tener acceso');
    }

    public function testDesbloquearUnaCuentaBloqueada(): void
    {
        $intento = Entorno::pdo()->prepare('INSERT INTO intentos_login (documento, creado_en) VALUES (?, ?)');
        for ($i = 0; $i < 5; $i++) {
            $intento->execute(['3000000003', time()]);
        }
        $c = $this->admin();
        $this->assertStringContainsString('Bloqueada por intentos fallidos', $c->get('api/admin/index.php?seccion=cuentas&editar=3')['cuerpo']);

        $c->enviarPanel(['action' => 'desbloquear_cuenta', 'id' => '3'], 'index.php?seccion=cuentas&editar=3');

        $this->assertSame(0, (int)$this->valor("SELECT COUNT(*) FROM intentos_login WHERE documento = '3000000003'"));
    }

    // --- Monitor y auditoría -------------------------------------------------

    public function testElMonitorMuestraCelularesYRechazos(): void
    {
        $this->sincronizar($this->lote(1, '666000', ['estrato' => 9]), $this->token(3), ['X-Device-Id: celular-luis', 'X-Plataforma: android']);
        $html = $this->admin()->get('api/admin/index.php?seccion=sincronizacion')['cuerpo'];

        $this->assertStringContainsString('celular-luis', $html);
        $this->assertStringContainsString('El estrato debe estar entre 1 y 6', $html);
        $this->assertStringContainsString('Luis Fernando Gómez', $html);
    }

    public function testElResumenAvisaDeLosRechazosRecientes(): void
    {
        $this->sincronizar($this->lote(1, '666000', ['estrato' => 9]), $this->token());
        $html = $this->admin()->get('api/admin/index.php?seccion=resumen')['cuerpo'];
        $this->assertStringContainsString('1 registro rechazado', $html);
    }

    public function testLaAuditoriaRegistraEntradaBorradoYCambiosDeCuenta(): void
    {
        $c = $this->admin();
        $c->enviarPanel(['action' => 'borrar_persona', 'tipo_documento' => 'CC', 'numero_documento' => '10200307', 'nombre' => 'María Gómez']);
        $c->enviarPanel(['action' => 'save', 'id' => '3', 'nombre' => 'Luis Fernando Gómez', 'numero_documento' => '3000000003',
            'password' => 'ClaveNueva2026', 'rol' => 'encuestador', 'activo' => 'on'], 'index.php?seccion=cuentas');

        $acciones = Entorno::pdo()->query('SELECT accion FROM auditoria_admin ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['entrar', 'borrar_persona', 'editar_cuenta'], $acciones);

        $detalle = (string)$this->valor("SELECT detalle FROM auditoria_admin WHERE accion = 'editar_cuenta'");
        $this->assertStringNotContainsString('ClaveNueva2026', $detalle, 'la contraseña nunca se audita');

        $html = $c->get('api/admin/index.php?seccion=auditoria')['cuerpo'];
        $this->assertStringContainsString('Borró una persona', $html);
        $this->assertStringContainsString('cambió la contraseña', $html);
    }

    // --- Seguridad -------------------------------------------------------------

    public function testElPanelEnviaUnaPoliticaDeSeguridadDeContenido(): void
    {
        $c = curl_init(Entorno::url('api/admin/index.php'));
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
        $respuesta = (string)curl_exec($c);
        curl_close($c);
        $this->assertMatchesRegularExpression("/Content-Security-Policy: default-src 'self'; script-src 'self'/i", $respuesta);
    }
}
