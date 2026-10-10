<?php

/**
 * «¿Olvidaste tu contraseña?»: código por correo y contraseña nueva.
 *
 * Los correos van a tests/php/SmtpFalso.php, que los guarda en un archivo:
 * así la prueba lee el código como lo leería la persona.
 */
final class RecuperacionTest extends IntegracionTestCase
{
    private const DOC = '1000000001';
    private const CORREO = 'docente@correo.test';
    private const NUEVA = 'ClaveNueva2026';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = Entorno::pdo();
        $pdo->exec('DROP TABLE IF EXISTS recuperaciones');
        $columna = $pdo->query("SHOW COLUMNS FROM encuestadores LIKE 'email'");
        if ($columna !== false && $columna->fetch() === false) {
            $pdo->exec('ALTER TABLE encuestadores ADD COLUMN email VARCHAR(100) NULL');
        }
        $pdo->exec("UPDATE encuestadores SET email = NULL");
        $pdo->prepare('UPDATE encuestadores SET email = ? WHERE numero_documento = ?')->execute([self::CORREO, self::DOC]);
        Entorno::borrarCorreos();
    }

    /** @return array{codigo: int, json: mixed} */
    private function api(string $ruta, array $cuerpo): array
    {
        $r = (new Cliente())->pedir('POST', $ruta, (string)json_encode($cuerpo));
        return ['codigo' => $r['codigo'], 'json' => json_decode($r['cuerpo'], true)];
    }

    private function pedirCodigo(string $doc = self::DOC): array
    {
        return $this->api('api/auth/recuperar.php', ['numero_documento' => $doc]);
    }

    private function codigoRecibido(): string
    {
        $correos = Entorno::correos();
        $this->assertNotEmpty($correos, 'debió llegar un correo');
        $ultimo = end($correos);
        $this->assertSame(self::CORREO, $ultimo['para']);
        $this->assertSame(1, preg_match('/\b(\d{6})\b/', $ultimo['texto'], $m), 'el correo trae un código de 6 dígitos');
        return $m[1];
    }

    private function restablecer(string $codigo, string $clave = self::NUEVA): array
    {
        return $this->api('api/auth/restablecer.php', ['numero_documento' => self::DOC, 'codigo' => $codigo, 'password' => $clave]);
    }

    public function testElCodigoDelCorreoPermiteCambiarLaContrasenaYCierraLasSesiones(): void
    {
        $tokenViejo = $this->token(1);

        $r = $this->pedirCodigo();
        $this->assertSame(200, $r['codigo']);
        $codigo = $this->codigoRecibido();
        $this->assertStringContainsString('Docente Demo', Entorno::correos()[0]['texto']);

        $r = $this->restablecer($codigo);
        $this->assertSame(200, $r['codigo'], json_encode($r['json']));

        // La contraseña vieja ya no entra; la nueva sí.
        $vieja = $this->api('api/auth/login.php', ['numero_documento' => self::DOC, 'password' => self::CLAVE]);
        $this->assertSame(401, $vieja['codigo']);
        $nueva = $this->api('api/auth/login.php', ['numero_documento' => self::DOC, 'password' => self::NUEVA]);
        $this->assertSame(200, $nueva['codigo']);

        // El token que tenía el celular quedó revocado.
        $this->assertSame(401, $this->sincronizar(['personas' => [], 'encuestas' => []], $tokenViejo)['codigo']);

        // Y se avisa del cambio por correo.
        $ultimo = Entorno::correos()[count(Entorno::correos()) - 1];
        $this->assertStringContainsString('cambió', $ultimo['asunto']);
    }

    public function testLaRespuestaEsIgualExistaONoLaCuentaYTengaONoCorreo(): void
    {
        $conCorreo = $this->pedirCodigo();
        $sinCuenta = $this->pedirCodigo('9999999999');
        $sinCorreo = $this->pedirCodigo('3000000003');

        $this->assertSame(200, $sinCuenta['codigo']);
        $this->assertSame($conCorreo['json'], $sinCuenta['json']);
        $this->assertSame($conCorreo['json'], $sinCorreo['json']);
        $this->assertCount(1, Entorno::correos(), 'solo la cuenta con correo recibe algo');
    }

    public function testUnCodigoSirveUnaSolaVez(): void
    {
        $this->pedirCodigo();
        $codigo = $this->codigoRecibido();
        $this->assertSame(200, $this->restablecer($codigo)['codigo']);
        $this->assertSame(400, $this->restablecer($codigo, 'OtraClave20261')['codigo']);
    }

    public function testPedirOtroCodigoAnulaElAnterior(): void
    {
        $this->pedirCodigo();
        $primero = $this->codigoRecibido();
        $this->pedirCodigo();
        $segundo = $this->codigoRecibido();
        if ($primero !== $segundo) {
            $this->assertSame(400, $this->restablecer($primero)['codigo']);
        }
        $this->assertSame(200, $this->restablecer($segundo)['codigo']);
    }

    public function testCincoCodigosEquivocadosAnulanElCodigo(): void
    {
        $this->pedirCodigo();
        $codigo = $this->codigoRecibido();
        $malo = $codigo === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(400, $this->restablecer($malo)['codigo']);
        }
        // Ni el correcto sirve ya: hay que pedir otro (y además el documento quedó bloqueado).
        $this->assertNotSame(200, $this->restablecer($codigo)['codigo']);
    }

    public function testUnCodigoVencidoNoSirve(): void
    {
        $this->pedirCodigo();
        $codigo = $this->codigoRecibido();
        Entorno::pdo()->exec('UPDATE recuperaciones SET expira_en = ' . (time() - 1));
        $r = $this->restablecer($codigo);
        $this->assertSame(400, $r['codigo']);
        $this->assertStringContainsString('venció', (string)$r['json']['message']);
    }

    public function testLaContrasenaNuevaCumpleLaPolitica(): void
    {
        $this->pedirCodigo();
        $r = $this->restablecer($this->codigoRecibido(), 'corta');
        $this->assertSame(400, $r['codigo']);
        $this->assertStringContainsString('10 caracteres', (string)$r['json']['message']);
    }

    public function testNoSePuedenPedirCodigosSinLimite(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->pedirCodigo()['codigo']);
        }
        $this->assertSame(429, $this->pedirCodigo()['codigo']);
    }

    public function testUnDocumentoConFormatoInvalidoSeRechaza(): void
    {
        $this->assertSame(400, $this->pedirCodigo('#admin')['codigo']);
        $this->assertSame(400, $this->api('api/auth/restablecer.php', [
            'numero_documento' => self::DOC, 'codigo' => '12ab', 'password' => self::NUEVA,
        ])['codigo']);
    }

    public function testElPanelEnviaUnCorreoDePruebaAlAdministrador(): void
    {
        Entorno::pdo()->exec("UPDATE encuestadores SET email = 'ana.rojas@correo.test' WHERE id = 2");
        $c = new Cliente();
        $c->entrarPanel('2000000002', self::CLAVE);
        $this->assertStringContainsString('activada', $c->get('api/admin/index.php?seccion=cuentas')['cuerpo']);

        $c->enviarPanel(['action' => 'probar_correo'], 'index.php?seccion=cuentas');

        $correos = Entorno::correos();
        $this->assertCount(1, $correos);
        $this->assertSame('ana.rojas@correo.test', $correos[0]['para']);
        $this->assertSame('Prueba de correo de ColOffline', $correos[0]['asunto']);
    }

    public function testElPanelGuardaElCorreoDeUnaCuenta(): void
    {
        $c = new Cliente();
        $c->entrarPanel('2000000002', self::CLAVE);
        $c->enviarPanel([
            'action' => 'save', 'id' => '3', 'nombre' => 'Luis Fernando Gómez', 'numero_documento' => '3000000003',
            'email' => 'Luis.Gomez@Correo.CO', 'rol' => 'encuestador', 'activo' => '1',
        ], 'index.php?seccion=cuentas&editar=3');
        $this->assertSame('luis.gomez@correo.co', $this->valor('SELECT email FROM encuestadores WHERE id = 3'));

        $r = $c->enviarPanel([
            'action' => 'save', 'id' => '3', 'nombre' => 'Luis Fernando Gómez', 'numero_documento' => '3000000003',
            'email' => 'esto-no-es-correo', 'rol' => 'encuestador', 'activo' => '1',
        ], 'index.php?seccion=cuentas&editar=3');
        $this->assertStringContainsString('El correo no es válido', $r['cuerpo']);
        $this->assertSame('luis.gomez@correo.co', $this->valor('SELECT email FROM encuestadores WHERE id = 3'));
    }
}
