<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/personas/validacion.php';

/**
 * Pruebas unitarias de las reglas de api/personas/validacion.php.
 *
 * Las de integración (SincronizacionTest) comprueban que un lote con un dato
 * malo se rechaza por fila; estas recorren cada regla con casos límite, sin
 * pasar por HTTP. Son las mismas reglas que pwa/js/validacion.js y
 * Validaciones.kt (ver pwa/tests/paridad.test.mjs).
 */
final class ValidacionTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function documentosValidos(): array
    {
        return [
            'CC de 6' => ['CC', '123456'],
            'CC de 10' => ['CC', '1061702334'],
            'TI de 10' => ['TI', '1061702334'],
            'TI de 11' => ['TI', '10617023345'],
            'RC de 10' => ['RC', '1234567890'],
            'CE de 7' => ['CE', '1234567'],
            'PP con letras' => ['PP', 'AB123456'],
            'NIT de 9' => ['NIT', '900123456'],
            'PE de 15' => ['PE', '123456789012345'],
        ];
    }

    #[DataProvider('documentosValidos')]
    public function testDocumentosValidos(string $tipo, string $numero): void
    {
        $this->assertSame(strtoupper($numero), documentoValidado(['tipo_documento' => $tipo, 'numero_documento' => $numero]));
    }

    /** @return array<string, array{string, string}> */
    public static function documentosInvalidos(): array
    {
        return [
            'CC corta' => ['CC', '12345'],
            'CC larga' => ['CC', '12345678901'],
            'CC con letras' => ['CC', 'sdscf1ds5ds1c'],
            'CC con puntos' => ['CC', '1.061.702'],
            'TI corta' => ['TI', '123456'],
            'NIT con guion' => ['NIT', '900123456-1'],
            'PP con guion' => ['PP', 'AB-12345'],
            'PP corto' => ['PP', 'AB12'],
            'tipo desconocido' => ['XX', '123456'],
            'vacío' => ['CC', ''],
        ];
    }

    #[DataProvider('documentosInvalidos')]
    public function testDocumentosInvalidos(string $tipo, string $numero): void
    {
        $this->expectException(DatoInvalido::class);
        documentoValidado(['tipo_documento' => $tipo, 'numero_documento' => $numero]);
    }

    public function testElPasaporteSeGuardaEnMayusculas(): void
    {
        $this->assertSame('AB123456', documentoValidado(['tipo_documento' => 'PP', 'numero_documento' => 'ab123456']));
    }

    public function testNombresValidosYEspaciosNormalizados(): void
    {
        $this->assertSame('María Fernanda', nombreValidado(['n' => '  María   Fernanda '], 'n'));
        $this->assertSame("D'Angelo Pérez-Gómez", nombreValidado(['n' => "D'Angelo Pérez-Gómez"], 'n'));
        $this->assertSame('Ñuñez', nombreValidado(['n' => 'Ñuñez'], 'n'));
    }

    /** @return array<string, array{string}> */
    public static function nombresInvalidos(): array
    {
        return [
            'con números' => ['584Jairo'],
            'con signos' => ['Velasquez.,s'],
            'una letra' => ['A'],
            'vacío' => ['   '],
            'empieza con guion' => ['-Ana'],
            'muy largo' => [str_repeat('a', 61)],
            'con etiqueta' => ['<b>Ana</b>'],
        ];
    }

    #[DataProvider('nombresInvalidos')]
    public function testNombresInvalidos(string $nombre): void
    {
        $this->expectException(DatoInvalido::class);
        nombreValidado(['n' => $nombre], 'n');
    }

    public function testTelefono(): void
    {
        $this->assertNull(telefonoValidado([]));
        $this->assertNull(telefonoValidado(['telefono' => '']));
        $this->assertSame('3001234567', telefonoValidado(['telefono' => '3001234567']));
        $this->assertSame('6012345678', telefonoValidado(['telefono' => '6012345678']));
        foreach (['saddc', '6012345', '1234567890', '30012345678', '+573001234567'] as $malo) {
            try {
                telefonoValidado(['telefono' => $malo]);
                $this->fail("Debió rechazar el teléfono '$malo'");
            } catch (DatoInvalido) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTextosLibres(): void
    {
        $this->assertSame('Calle 5 # 10-20, apto 3', textoLibreValidado(['direccion' => 'Calle 5 # 10-20, apto 3'], 'direccion'));
        $this->assertSame('Vereda El Carmen', textoLibreValidado(['vereda' => 'Vereda  El Carmen'], 'vereda'));
        $this->assertSame('Nueva EPS', textoLibreValidado(['eps' => 'Nueva EPS'], 'eps'));
        $this->assertSame('Agricultor', textoLibreValidado(['ocupacion' => 'Agricultor'], 'ocupacion'));
        $this->assertNull(textoLibreValidado(['eps' => '  '], 'eps'));

        $malos = [
            ['direccion', 'Cl 5'],          // muy corta
            ['vereda', 'ab'],
            ['eps', '12345'],               // sin letras
            ['ocupacion', 'Agricultor 2'],  // la ocupación no lleva dígitos
            ['eps', 'EPS <script>'],
            ['direccion', str_repeat('a', 151)],
        ];
        foreach ($malos as [$campo, $valor]) {
            try {
                textoLibreValidado([$campo => $valor], $campo);
                $this->fail("Debió rechazar $campo = '$valor'");
            } catch (DatoInvalido) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEstratoCorreoYFecha(): void
    {
        $this->assertSame(3, estratoValidado(['estrato' => '3']));
        $this->assertNull(estratoValidado(['estrato' => '']));
        $this->assertSame('ana@correo.co', emailValidado(['email' => 'ana@correo.co']));
        $this->assertSame(fechaDePrueba1990(), fechaNacimientoValidada(['fecha_nacimiento' => fechaDePrueba1990()]));

        foreach ([
            fn () => estratoValidado(['estrato' => 7]),
            fn () => estratoValidado(['estrato' => '2.5']),
            fn () => emailValidado(['email' => 'esto-no-es-correo']),
            fn () => fechaNacimientoValidada(['fecha_nacimiento' => (time() + 3 * 86400) * 1000]),
            fn () => fechaNacimientoValidada(['fecha_nacimiento' => FECHA_NACIMIENTO_MINIMA - 1]),
        ] as $i => $caso) {
            try {
                $caso();
                $this->fail("El caso $i debió rechazarse");
            } catch (DatoInvalido) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

/** 12 de mayo de 1990, medianoche UTC, en milisegundos. */
function fechaDePrueba1990(): int
{
    return gmmktime(0, 0, 0, 5, 12, 1990) * 1000;
}
