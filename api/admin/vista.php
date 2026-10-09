<?php
/**
 * Utilidades de presentación del panel de administración.
 *
 * Solo dan formato: no consultan la base ni leen la sesión. index.php queda
 * como controlador más plantilla, y estas piezas se pueden leer por separado.
 */

const DIAS_SEMANA_CORTOS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
const MESES_CORTOS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

/** Escapa un valor para insertarlo en HTML. */
function h(mixed $v): string
{
    return htmlspecialchars(is_scalar($v) ? (string)$v : '', ENT_QUOTES, 'UTF-8');
}

/** Número con separador de miles colombiano: 1.284. */
function numero(int $n): string
{
    return number_format($n, 0, ',', '.');
}

/** "1 encuesta", "1.284 encuestas". */
function cantidad(int $n, string $singular, string $plural): string
{
    return numero($n) . ' ' . ($n === 1 ? $singular : $plural);
}

/**
 * Caja de aviso con su icono.
 *
 * El contenido llega YA escapado, porque a veces lleva marcado propio (un
 * <strong>, el botón de deshacer). Quien la llame con texto de usuario debe
 * pasarlo antes por h().
 */
function cajaAviso(string $tipo, string $contenidoHtml): string
{
    $tipo = in_array($tipo, ['ok', 'error', 'info', 'advertencia'], true) ? $tipo : 'info';
    // Los errores interrumpen al lector de pantalla; el resto se anuncia sin cortar.
    $rol = $tipo === 'error' ? 'alert' : 'status';
    return '<div class="aviso aviso-' . $tipo . '" role="' . $rol . '">' . icono($tipo)
         . '<div class="aviso-texto">' . $contenidoHtml . '</div></div>';
}

/** Fecha y hora legibles a partir de milisegundos, en la zona del panel. */
function fecha(mixed $ms): string
{
    if (!is_numeric($ms) || (int)$ms <= 0) {
        return '—';
    }
    // intdiv y no "/": en PHP la división de enteros devuelve float cuando no
    // es exacta, y pasar ese float a date() emite un aviso de pérdida de
    // precisión. En la exportación, ese aviso acababa escrito DENTRO del CSV.
    return date('d/m/Y H:i', intdiv((int)$ms, 1000));
}

/** "hace 5 min", "hace 3 h", "hace 2 días"; pasada una semana, la fecha. */
function haceCuanto(mixed $ms): string
{
    if (!is_numeric($ms) || (int)$ms <= 0) {
        return 'nunca';
    }
    $segundos = time() - intdiv((int)$ms, 1000);

    // Un reloj de servidor desfasado puede dar segundos negativos.
    if ($segundos < 60) {
        return 'hace un momento';
    }
    if ($segundos < 3600) {
        return 'hace ' . intdiv($segundos, 60) . ' min';
    }
    if ($segundos < 86400) {
        return 'hace ' . intdiv($segundos, 3600) . ' h';
    }
    $dias = intdiv($segundos, 86400);
    if ($dias < 7) {
        return $dias === 1 ? 'hace 1 día' : "hace $dias días";
    }
    return date('d/m/Y', intdiv((int)$ms, 1000));
}

/**
 * Partes de la etiqueta del eje X a partir de 'YYYY-MM-DD'.
 *
 * @return array{dia: string, semana: string, larga: string}
 */
function etiquetaDia(string $dia): array
{
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $dia);
    // Ante una fecha que no reconoce se muestra el valor crudo antes que
    // romper la página entera.
    if ($fecha === false) {
        return ['dia' => $dia, 'semana' => '', 'larga' => $dia];
    }
    $semana = DIAS_SEMANA_CORTOS[(int)$fecha->format('w')];
    return [
        'dia'    => $fecha->format('j'),
        'semana' => $semana,
        'larga'  => $semana . ' ' . $fecha->format('j') . ' ' . MESES_CORTOS[(int)$fecha->format('n') - 1],
    ];
}

/**
 * Tope del eje Y: el primer número redondo que cubre el máximo.
 *
 * Siempre par, para que la marca intermedia (la mitad) sea un entero y no
 * aparezca "2,5 encuestas" en el eje.
 */
function topeEje(int $maximo): int
{
    for ($escala = 1; $escala < intdiv(PHP_INT_MAX, 100); $escala *= 10) {
        foreach ([2, 4, 6, 8, 10] as $multiplo) {
            if ($multiplo * $escala >= $maximo) {
                return $multiplo * $escala;
            }
        }
    }
    return $maximo;
}

/** Iniciales para el avatar: primera y última palabra del nombre. */
function iniciales(string $nombre): string
{
    $partes = preg_split('/\s+/u', trim($nombre), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($partes === []) {
        return '?';
    }
    $ultima = count($partes) > 1 ? mb_substr($partes[count($partes) - 1], 0, 1) : '';
    return mb_strtoupper(mb_substr($partes[0], 0, 1) . $ultima);
}

/**
 * Neutraliza una celda del CSV que Excel ejecutaría como fórmula.
 *
 * Nombres y teléfonos llegan de los celulares, y cualquiera con un token puede
 * enviar "=HIPERVINCULO(...)" como apellido: al abrir el archivo, Excel lo
 * evalúa. Un apóstrofo delante lo deja como texto. De paso arregla los
 * teléfonos con "+57", que Excel intentaba calcular.
 */
function celdaCsv(mixed $valor): string
{
    $texto = is_scalar($valor) ? (string)$valor : '';
    return ($texto !== '' && str_contains("=+-@\t\r", $texto[0])) ? "'" . $texto : $texto;
}

/**
 * URL del panel con solo los parámetros que tienen valor.
 *
 * @param array<string, string|int|null> $parametros
 */
function urlPanel(array $parametros = []): string
{
    $limpios = array_filter($parametros, fn ($v) => $v !== null && $v !== '');
    return 'index.php' . ($limpios === [] ? '' : '?' . http_build_query($limpios));
}

/** Trazos de los iconos (24×24). En línea para no depender de nada externo. */
const TRAZOS_ICONOS = [
    'resumen'     => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
    'personas'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'cuentas'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/>',
    'salir'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
    'buscar'      => '<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    'descargar'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
    'borrar'      => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
    'restaurar'   => '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>',
    'mas'         => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
    'editar'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
    'ok'          => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    'error'       => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    'info'        => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
    'advertencia' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    'vacio'       => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    'anterior'    => '<polyline points="15 18 9 12 15 6"/>',
    'siguiente'   => '<polyline points="9 18 15 12 9 6"/>',
    'cerrar'      => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
    'volver'      => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
];

/** SVG de un icono decorativo: lo acompaña siempre un texto que lo nombra. */
function icono(string $nombre, int $tamano = 18): string
{
    return '<svg width="' . $tamano . '" height="' . $tamano . '" viewBox="0 0 24 24" fill="none" '
         . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" '
         . 'aria-hidden="true" focusable="false">' . (TRAZOS_ICONOS[$nombre] ?? '') . '</svg>';
}
