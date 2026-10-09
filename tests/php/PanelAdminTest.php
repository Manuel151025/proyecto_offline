<?php

/** Panel de administración: acceso, sesión, personas, CSV y cuentas. */
final class PanelAdminTest extends IntegracionTestCase
{
    private function admin(): Cliente
    {
        $c = new Cliente();
        $c->entrarPanel('2000000002', self::CLAVE);
        return $c;
    }

    private function dentro(Cliente $c): bool
    {
        return str_contains($c->get('api/admin/index.php')['cuerpo'], 'value="logout"');
    }

    // --- Acceso -------------------------------------------------------------

    public function testDocumentoDemasiadoLargoDaAvisoYNo500(): void
    {
        $r = (new Cliente())->enviarPanel(['action' => 'login', 'numero_documento' => str_repeat('1', 25), 'password' => 'x']);
        $this->assertSame(200, $r['codigo']);
        $this->assertStringContainsString('hasta 20 caracteres', $r['cuerpo']);
    }

    public function testClaveIncorrectaDaMensajeGenericoYConservaElDocumento(): void
    {
        $r = (new Cliente())->enviarPanel(['action' => 'login', 'numero_documento' => '2000000002', 'password' => 'mala']);
        $this->assertStringContainsString('Documento o contraseña incorrectos', $r['cuerpo']);
        $this->assertStringContainsString('value="2000000002"', $r['cuerpo']);
    }

    public function testUnEncuestadorNoEntraAlPanel(): void
    {
        $r = (new Cliente())->enviarPanel(['action' => 'login', 'numero_documento' => '1000000001', 'password' => self::CLAVE]);
        $this->assertSame(200, $r['codigo']);
        $this->assertStringContainsString('Documento o contraseña incorrectos', $r['cuerpo']);
    }

    public function testLoginCorrectoRedirigeConPostRedirectGet(): void
    {
        $c = new Cliente();
        $r = $c->enviarPanel(['action' => 'login', 'numero_documento' => '2000000002', 'password' => self::CLAVE],
            'index.php', 'index.php?seccion=personas');
        $this->assertSame(303, $r['codigo']);
        $this->assertStringContainsString('seccion=personas', $r['ubicacion']);
        $this->assertStringContainsString('Ana María Rojas', $c->get('api/admin/index.php')['cuerpo']);
    }

    public function testCincoFallosBloqueanInclusoLaClaveCorrecta(): void
    {
        $c = new Cliente();
        for ($i = 0; $i < 5; $i++) {
            $c->enviarPanel(['action' => 'login', 'numero_documento' => '2000000002', 'password' => 'mala']);
        }
        $r = $c->enviarPanel(['action' => 'login', 'numero_documento' => '2000000002', 'password' => self::CLAVE]);
        $this->assertStringContainsString('Demasiados intentos', $r['cuerpo']);
    }

    // --- Revalidación de la sesión ------------------------------------------

    public function testUnAdminDesactivadoSaleDelPanel(): void
    {
        $c = $this->admin();
        Entorno::pdo()->exec('UPDATE encuestadores SET activo = 0 WHERE id = 2');
        $html = $c->get('api/admin/index.php')['cuerpo'];
        $this->assertStringContainsString('Tu acceso cambió', $html);
        $this->assertFalse($this->dentro($c));
    }

    public function testCambiarLaClaveDesdeFueraCierraLaSesion(): void
    {
        $c = $this->admin();
        Entorno::pdo()->prepare('UPDATE encuestadores SET password_hash = ? WHERE id = 2')
            ->execute([password_hash('OtraClave2026x', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->assertFalse($this->dentro($c));
    }

    public function testCerrarSesionRedirige(): void
    {
        $c = $this->admin();
        $this->assertSame(303, $c->enviarPanel(['action' => 'logout'])['codigo']);
        $this->assertFalse($this->dentro($c));
    }

    public function testSinSesionNoSePuedeBorrar(): void
    {
        $c = new Cliente();
        $c->enviarPanel(['action' => 'borrar_persona', 'tipo_documento' => 'CC', 'numero_documento' => '10200307']);
        $this->assertNull($this->valor("SELECT deleted_at FROM personas WHERE numero_documento = '10200307'"));
    }

    // --- Resumen ------------------------------------------------------------

    public function testElResumenMuestraCatorceDiasYCuentaSoloEncuestadores(): void
    {
        $html = $this->admin()->get('api/admin/index.php?seccion=resumen')['cuerpo'];
        $this->assertSame(14, substr_count($html, 'class="grafico-col'));
        $this->assertMatchesRegularExpression('/Encuestadores activos<\/p>\s*<p class="cifra-valor">2</', $html);
    }

    // --- Personas -----------------------------------------------------------

    public function testBuscaPorNombreCompletoYEscapaComodines(): void
    {
        $c = $this->admin();
        $this->assertMatchesRegularExpression('/\d+ resultados? para/', $c->get('api/admin/index.php?seccion=personas&q=' . urlencode('Juan Pérez'))['cuerpo']);
        $this->assertStringContainsString('Sin resultados', $c->get('api/admin/index.php?seccion=personas&q=_')['cuerpo']);
    }

    public function testUnaPaginaFueraDeRangoLlevaALaUltima(): void
    {
        $html = $this->admin()->get('api/admin/index.php?seccion=personas&p=999')['cuerpo'];
        $this->assertStringContainsString('Mostrando 26–28 de 28', $html);
    }

    public function testBorrarVuelveALaBusquedaYPermiteDeshacer(): void
    {
        $c = $this->admin();
        $r = $c->enviarPanel(['action' => 'borrar_persona', 'tipo_documento' => 'CC', 'numero_documento' => '10200300',
            'nombre' => 'Juan Prueba', 'volver_q' => 'Juan', 'volver_p' => '1'], 'index.php?seccion=personas');
        $this->assertSame(303, $r['codigo']);
        $this->assertStringContainsString('q=Juan', $r['ubicacion']);
        $this->assertNotNull($this->valor("SELECT deleted_at FROM personas WHERE numero_documento = '10200300'"));

        $html = $c->get('api/admin/index.php?seccion=personas&q=Juan')['cuerpo'];
        $this->assertStringContainsString('Se borró a Juan Prueba', $html);
        $this->assertStringContainsString('>Deshacer<', $html);
        $this->assertStringNotContainsString('Se borró a', $c->get('api/admin/index.php?seccion=personas&q=Juan')['cuerpo']);

        $c->enviarPanel(['action' => 'restaurar_persona', 'tipo_documento' => 'CC', 'numero_documento' => '10200300']);
        $this->assertNull($this->valor("SELECT deleted_at FROM personas WHERE numero_documento = '10200300'"));
    }

    public function testBorrarDesdeElPanelSellaParaQueLleguenLosCelulares(): void
    {
        $antes = (int)$this->valor("SELECT server_updated_at FROM personas WHERE numero_documento = '10200300'");
        $this->admin()->enviarPanel(['action' => 'borrar_persona', 'tipo_documento' => 'CC', 'numero_documento' => '10200300']);
        $this->assertGreaterThan($antes, (int)$this->valor("SELECT server_updated_at FROM personas WHERE numero_documento = '10200300'"));
    }

    // --- CSV ----------------------------------------------------------------

    public function testElCsvNeutralizaFormulasYNoCorreLaFechaDeNacimiento(): void
    {
        $csv = $this->admin()->get('api/admin/index.php?exportar=personas')['cuerpo'];
        $this->assertStringContainsString("\"'=HIPERVINCULO", $csv);
        $this->assertStringContainsString(";'+573004;", $csv);
        $this->assertMatchesRegularExpression('/10200328;[^\n]*;1984-03-15;/', $csv);
        $this->assertDoesNotMatchRegularExpression('/deprecated|warning|notice/i', $csv);
    }

    // --- Cuentas ------------------------------------------------------------

    public function testUnDocumentoConPuntosSeRechazaYElFormularioConservaLoEscrito(): void
    {
        $r = $this->admin()->enviarPanel(['action' => 'save', 'id' => '', 'nombre' => 'Nueva Persona',
            'numero_documento' => '1.020.300', 'password' => 'ClaveLarga2026', 'rol' => 'encuestador', 'activo' => 'on'],
            'index.php?seccion=cuentas', 'index.php?seccion=cuentas');
        $this->assertStringContainsString('sin puntos ni espacios', $r['cuerpo']);
        $this->assertStringContainsString('value="Nueva Persona"', $r['cuerpo']);
    }

    public function testCambiarLaClaveRevocaLosTokensDelCelular(): void
    {
        $this->token(3);
        $this->admin()->enviarPanel(['action' => 'save', 'id' => '3', 'nombre' => 'Luis Fernando Gómez',
            'numero_documento' => '3000000003', 'password' => 'ClaveNueva2026', 'rol' => 'encuestador', 'activo' => 'on'],
            'index.php?seccion=cuentas');
        $this->assertSame(0, (int)$this->valor('SELECT COUNT(*) FROM sesiones WHERE id_encuestador = 3'));
    }

    public function testNoSePuedeQuitarElRolAlUltimoAdminActivo(): void
    {
        $c = $this->admin();
        $c->enviarPanel(['action' => 'save', 'id' => '5', 'nombre' => 'Jorge Iván Castro',
            'numero_documento' => '5000000005', 'password' => '', 'rol' => 'admin'], 'index.php?seccion=cuentas');
        $r = $c->enviarPanel(['action' => 'save', 'id' => '2', 'nombre' => 'Ana María Rojas',
            'numero_documento' => '2000000002', 'password' => '', 'rol' => 'encuestador', 'activo' => 'on'],
            'index.php?seccion=cuentas', 'index.php?seccion=cuentas');
        $this->assertStringContainsString('No puedes quitar el rol', $r['cuerpo']);
    }

    // --- Modo arranque --------------------------------------------------------

    public function testEnArranqueSeEditaUnEncuestadorYElPrimerAdminCierraTodasLasSesiones(): void
    {
        $this->sembrar(arranque: true);
        $a = new Cliente();
        $b = new Cliente();
        $a->enviarPanel(['action' => 'login', 'password' => Entorno::ADMIN_PASSWORD]);
        $b->enviarPanel(['action' => 'login', 'password' => Entorno::ADMIN_PASSWORD]);

        $r = $a->enviarPanel(['action' => 'save', 'id' => '1', 'nombre' => 'Docente Demo Editado',
            'numero_documento' => '1000000001', 'password' => '', 'rol' => 'encuestador', 'activo' => 'on'],
            'index.php?seccion=cuentas');
        $this->assertSame(303, $r['codigo'], 'Editar un encuestador en arranque no debe bloquearse');

        $a->enviarPanel(['action' => 'save', 'id' => '', 'nombre' => 'Primera Admin', 'numero_documento' => '9000000009',
            'password' => 'PrimeraAdmin2026', 'rol' => 'admin', 'activo' => 'on'], 'index.php?seccion=cuentas');

        $this->assertStringContainsString('Cuenta de administrador creada', $a->get('api/admin/index.php')['cuerpo']);
        $this->assertFalse($this->dentro($a));
        $this->assertStringContainsString('Ya existe una cuenta de administrador', $b->get('api/admin/index.php')['cuerpo']);
    }
}
