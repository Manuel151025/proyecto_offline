<?php
/**
 * Exportación de personas a Excel (.xlsx) con diseño.
 *
 * El CSV no admite formato: llegaba a Excel como texto plano en una sola
 * columna de anchos iguales. Aquí se arma un .xlsx de verdad, sin librerías
 * (la imagen de producción no instala Composer): un .xlsx es un ZIP con XML,
 * y la extensión zip de PHP ya viene en el Dockerfile.
 *
 * Hoja «Personas»: título con la marca, línea con fecha y filtros, encabezado
 * en azul institucional, filas alternadas, fechas reales de Excel (se pueden
 * ordenar y filtrar), edad calculada, encabezado fijo al desplazarse, filtros
 * en cada columna e impresión horizontal ajustada al ancho.
 * Hoja «Resumen»: totales por departamento, EPS y estrato.
 *
 * Todas las celdas de texto van como cadenas en línea (inlineStr): Excel
 * nunca las interpreta como fórmulas, así que «=HIPERVINCULO(...)» en un
 * apellido no se ejecuta.
 */

/** Columnas de la hoja de personas: [encabezado, ancho, tipo]. */
const COLUMNAS_EXCEL = [
    ['Tipo', 9, 'texto'],
    ['Documento', 15, 'texto'],
    ['Nombres', 20, 'texto'],
    ['Apellidos', 22, 'texto'],
    ['Nacimiento', 15, 'fecha'],
    ['Edad', 9, 'numero'],
    ['Teléfono', 14, 'texto'],
    ['Correo', 26, 'texto'],
    ['Dirección', 26, 'texto'],
    ['Municipio', 20, 'texto'],
    ['Departamento', 18, 'texto'],
    ['Vereda', 18, 'texto'],
    ['EPS', 18, 'texto'],
    ['Ocupación', 18, 'texto'],
    ['Estrato', 11, 'numero'],
    ['Actualizada', 19, 'fechahora'],
    ['Origen', 22, 'texto'],
];

/** Índices de estilo (cellXfs de styles.xml). */
const XF_TITULO = 1;
const XF_SUBTITULO = 2;
const XF_ENCABEZADO = 3;
const XF_TEXTO = 4;       // +1 = fila alterna
const XF_FECHA = 6;       // +1
const XF_FECHAHORA = 8;   // +1
const XF_NUMERO = 10;     // +1
const XF_SECCION = 12;
const XF_TOTAL = 13;
const XF_ETIQUETA = 14;

/**
 * Genera el .xlsx y devuelve la ruta de un archivo temporal (quien llama lo
 * envía y lo borra).
 *
 * @param list<array<string, mixed>> $filas resultado de personasParaExportar()
 * @param string $descripcionFiltros texto para la línea bajo el título
 */
function generarExcelPersonas(array $filas, string $descripcionFiltros, DateTimeZone $zona): string
{
    $ahora = new DateTimeImmutable('now', $zona);
    $hoy = $ahora->setTime(0, 0);
    $columnas = count(COLUMNAS_EXCEL);
    $ultimaCol = columnaExcel($columnas - 1);

    // ---- Hoja 1: personas ----
    $filasXml = [];
    $filasXml[] = filaXml(1, [celdaTexto('A1', 'ColOffline · Personas registradas', XF_TITULO)], 30);
    $linea = 'Generado el ' . $ahora->format('d/m/Y \a \l\a\s H:i') . ' · ' . cantidad(count($filas), 'persona', 'personas')
           . ($descripcionFiltros !== '' ? ' · ' . $descripcionFiltros : '');
    $filasXml[] = filaXml(2, [celdaTexto('A2', $linea, XF_SUBTITULO)], 20);
    $encabezados = [];
    foreach (COLUMNAS_EXCEL as $i => [$titulo]) {
        $encabezados[] = celdaTexto(columnaExcel($i) . '4', $titulo, XF_ENCABEZADO);
    }
    $filasXml[] = filaXml(4, $encabezados, 30);

    $resumen = ['departamento' => [], 'eps' => [], 'estrato' => []];
    foreach ($filas as $n => $p) {
        $r = 5 + $n;
        $alterna = $n % 2;
        $nacimiento = is_numeric($p['fecha_nacimiento']) ? (int)$p['fecha_nacimiento'] : null;
        $actualizada = is_numeric($p['updated_at']) ? (int)$p['updated_at'] : null;
        $edad = null;
        if ($nacimiento !== null) {
            // La fecha de nacimiento es un DÍA guardado como medianoche UTC.
            $diaNacimiento = new DateTimeImmutable(gmdate('Y-m-d', intdiv($nacimiento, 1000)), $zona);
            $edad = $diaNacimiento <= $hoy ? $diaNacimiento->diff($hoy)->y : null;
        }
        $valores = [
            $p['tipo_documento'], $p['numero_documento'], $p['nombres'], $p['apellidos'],
            $nacimiento !== null ? serialDia($nacimiento) : null,
            $edad,
            $p['telefono'], $p['email'], $p['direccion'], $p['municipio'], $p['departamento'],
            $p['vereda'], $p['eps'], $p['ocupacion'],
            is_numeric($p['estrato']) ? (int)$p['estrato'] : null,
            $actualizada !== null ? serialInstante($actualizada, $zona) : null,
            origenLegible((string)($p['device_id'] ?? '')),
        ];
        $celdas = [];
        foreach (COLUMNAS_EXCEL as $i => [, , $tipo]) {
            $ref = columnaExcel($i) . $r;
            $v = $valores[$i];
            $celdas[] = match ($tipo) {
                'fecha' => celdaNumero($ref, $v, XF_FECHA + $alterna),
                'fechahora' => celdaNumero($ref, $v, XF_FECHAHORA + $alterna),
                'numero' => celdaNumero($ref, $v, XF_NUMERO + $alterna),
                default => celdaTexto($ref, (string)($v ?? ''), XF_TEXTO + $alterna),
            };
        }
        $filasXml[] = filaXml($r, $celdas, 20);

        $resumen['departamento'][(string)($p['departamento'] ?: 'Sin municipio')][] = 1;
        $resumen['eps'][(string)($p['eps'] ?: 'Sin dato')][] = 1;
        $resumen['estrato'][is_numeric($p['estrato']) ? 'Estrato ' . (int)$p['estrato'] : 'Sin dato'][] = 1;
    }
    $ultimaFila = max(4, 4 + count($filas));

    $anchos = '';
    foreach (COLUMNAS_EXCEL as $i => [, $ancho]) {
        $anchos .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $ancho . '" customWidth="1"/>';
    }
    $hojaPersonas = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetPr><tabColor rgb="FF12467E"/><pageSetUpPr fitToPage="1"/></sheetPr>'
        . '<dimension ref="A1:' . $ultimaCol . $ultimaFila . '"/>'
        . '<sheetViews><sheetView workbookViewId="0" showGridLines="0" zoomScale="110">'
        . '<pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/>'
        . '<selection pane="bottomLeft" activeCell="A5" sqref="A5"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . '<cols>' . $anchos . '</cols>'
        . '<sheetData>' . implode('', $filasXml) . '</sheetData>'
        . '<autoFilter ref="A4:' . $ultimaCol . $ultimaFila . '"/>'
        . '<mergeCells count="2"><mergeCell ref="A1:' . $ultimaCol . '1"/><mergeCell ref="A2:' . $ultimaCol . '2"/></mergeCells>'
        . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
        . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>'
        . '<headerFooter><oddFooter>&amp;LColOffline&amp;RPágina &amp;P de &amp;N</oddFooter></headerFooter>'
        // Documento y teléfono son texto a propósito (ceros a la izquierda,
        // sin notación científica): sin esto Excel los marca con un triángulo verde.
        . '<ignoredErrors><ignoredError sqref="B5:B' . $ultimaFila . ' G5:G' . $ultimaFila . '" numberStoredAsText="1"/></ignoredErrors>'
        . '</worksheet>';

    // ---- Hoja 2: resumen ----
    $filasResumen = [filaXml(1, [celdaTexto('A1', 'Resumen', XF_TITULO)], 30)];
    $filasResumen[] = filaXml(2, [celdaTexto('A2', $linea, XF_SUBTITULO)], 20);
    $filasResumen[] = filaXml(4, [celdaTexto('A4', 'Total de personas', XF_ETIQUETA), celdaNumero('B4', count($filas), XF_TOTAL)], 24);
    $r = 5;
    $secciones = [
        'departamento' => ['Por departamento', 'Departamento'],
        'eps' => ['Por EPS', 'EPS'],
        'estrato' => ['Por estrato', 'Estrato'],
    ];
    foreach ($secciones as $clave => [$titulo, $columna]) {
        $r++; // una fila en blanco entre tablas
        $filasResumen[] = filaXml($r, [celdaTexto("A$r", $titulo, XF_SECCION)], 24);
        $r++;
        $filasResumen[] = filaXml($r, [celdaTexto("A$r", $columna, XF_ENCABEZADO), celdaTexto("B$r", 'Personas', XF_ENCABEZADO), celdaTexto("C$r", '%', XF_ENCABEZADO)], 24);
        $r++;
        $conteos = array_map('count', $resumen[$clave]);
        if ($clave === 'estrato') {
            ksort($conteos);
        } else {
            arsort($conteos);
        }
        $i = 0;
        foreach ($conteos as $nombre => $cuantas) {
            $alterna = $i++ % 2;
            $filasResumen[] = filaXml($r, [
                celdaTexto("A$r", (string)$nombre, XF_TEXTO + $alterna),
                celdaNumero("B$r", $cuantas, XF_NUMERO + $alterna),
                celdaTexto("C$r", count($filas) > 0 ? number_format(100 * $cuantas / count($filas), 1, ',', '.') . ' %' : '', XF_NUMERO + $alterna),
            ], 20);
            $r++;
        }
    }
    $hojaResumen = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetPr><tabColor rgb="FFB4532A"/></sheetPr>'
        . '<sheetViews><sheetView workbookViewId="0" showGridLines="0" zoomScale="110"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . '<cols><col min="1" max="1" width="34" customWidth="1"/><col min="2" max="2" width="14" customWidth="1"/><col min="3" max="3" width="12" customWidth="1"/></cols>'
        . '<sheetData>' . implode('', $filasResumen) . '</sheetData>'
        . '<mergeCells count="2"><mergeCell ref="A1:C1"/><mergeCell ref="A2:F2"/></mergeCells>'
        . '<pageMargins left="0.5" right="0.5" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
        . '</worksheet>';

    $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
    if ($ruta === false) {
        throw new RuntimeException('No se pudo crear el archivo temporal');
    }
    $zip = new ZipArchive();
    if ($zip->open($ruta, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('No se pudo crear el archivo Excel');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>ColOffline · Personas registradas</dc:title><dc:creator>ColOffline</dc:creator>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
        . '</cp:coreProperties>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>ColOffline</Application></Properties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<bookViews><workbookView activeTab="0"/></bookViews>'
        . '<sheets><sheet name="Personas" sheetId="1" r:id="rId1"/><sheet name="Resumen" sheetId="2" r:id="rId2"/></sheets>'
        . '<definedNames>'
        . '<definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">Personas!$A$4:$' . $ultimaCol . '$' . $ultimaFila . '</definedName>'
        . '<definedName name="_xlnm.Print_Titles" localSheetId="0">Personas!$4:$4</definedName>'
        . '</definedNames></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/styles.xml', estilosExcel());
    $zip->addFromString('xl/worksheets/sheet1.xml', $hojaPersonas);
    $zip->addFromString('xl/worksheets/sheet2.xml', $hojaResumen);
    $zip->close();

    return $ruta;
}

/** Hoja de estilos: la paleta de la app (azul institucional, fondo cálido, terracota). */
function estilosExcel(): string
{
    $borde = '<border><left style="thin"><color rgb="FFE4E0D8"/></left><right style="thin"><color rgb="FFE4E0D8"/></right>'
           . '<top style="thin"><color rgb="FFE4E0D8"/></top><bottom style="thin"><color rgb="FFE4E0D8"/></bottom><diagonal/></border>';
    $bordeEncabezado = '<border><left style="thin"><color rgb="FF0C325C"/></left><right style="thin"><color rgb="FF0C325C"/></right>'
           . '<top style="thin"><color rgb="FF0C325C"/></top><bottom style="medium"><color rgb="FFB4532A"/></bottom><diagonal/></border>';
    $centro = '<alignment horizontal="center" vertical="center"/>';
    $izq = '<alignment vertical="center"/>';
    $xf = fn (int $fmt, int $font, int $fill, int $border, string $alineacion) =>
        '<xf numFmtId="' . $fmt . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0"'
        . ($fmt ? ' applyNumberFormat="1"' : '') . ' applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">' . $alineacion . '</xf>';
    $xfs = [
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>',
        $xf(0, 1, 0, 0, '<alignment vertical="center"/>'),                                   // 1 título
        $xf(0, 2, 0, 0, '<alignment vertical="center"/>'),                                   // 2 subtítulo
        $xf(0, 3, 2, 2, '<alignment horizontal="center" vertical="center" wrapText="1"/>'),  // 3 encabezado
        $xf(0, 0, 0, 1, $izq), $xf(0, 0, 3, 1, $izq),                                        // 4-5 texto
        $xf(164, 0, 0, 1, $centro), $xf(164, 0, 3, 1, $centro),                              // 6-7 fecha
        $xf(165, 0, 0, 1, $centro), $xf(165, 0, 3, 1, $centro),                              // 8-9 fecha y hora
        $xf(0, 0, 0, 1, $centro), $xf(0, 0, 3, 1, $centro),                                  // 10-11 número
        $xf(0, 4, 0, 0, '<alignment vertical="center"/>'),                                   // 12 sección
        $xf(0, 5, 4, 1, $centro),                                                            // 13 total
        $xf(0, 6, 4, 1, $izq),                                                               // 14 etiqueta del total
    ];
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy hh:mm"/></numFmts>'
        . '<fonts count="7">'
        . '<font><sz val="11"/><color rgb="FF1C2430"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="18"/><color rgb="FF12467E"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><i/><sz val="10"/><color rgb="FF5A6370"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="13"/><color rgb="FFB4532A"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="16"/><color rgb="FF12467E"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="12"/><color rgb="FF1C2430"/><name val="Calibri"/><family val="2"/></font>'
        . '</fonts>'
        . '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF12467E"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF6F4EF"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8EFF7"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="3"><border><left/><right/><top/><bottom/><diagonal/></border>' . $borde . $bordeEncabezado . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/** A, B, …, Z, AA… para el índice de columna (desde 0). */
function columnaExcel(int $indice): string
{
    $letras = '';
    for ($i = $indice + 1; $i > 0; $i = intdiv($i - 1, 26)) {
        $letras = chr(65 + ($i - 1) % 26) . $letras;
    }
    return $letras;
}

/** @param list<string> $celdas */
function filaXml(int $numero, array $celdas, float $alto): string
{
    return '<row r="' . $numero . '" ht="' . $alto . '" customHeight="1">' . implode('', $celdas) . '</row>';
}

function celdaTexto(string $ref, string $texto, int $estilo): string
{
    // Fuera los caracteres de control que XML no admite (un salto de línea sí vale).
    $limpio = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $texto);
    if ($limpio === '') {
        return '<c r="' . $ref . '" s="' . $estilo . '"/>';
    }
    return '<c r="' . $ref . '" s="' . $estilo . '" t="inlineStr"><is><t xml:space="preserve">'
        . htmlspecialchars($limpio, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
}

function celdaNumero(string $ref, int|float|null $valor, int $estilo): string
{
    if ($valor === null) {
        return '<c r="' . $ref . '" s="' . $estilo . '"/>';
    }
    return '<c r="' . $ref . '" s="' . $estilo . '"><v>' . $valor . '</v></c>';
}

/** Día (medianoche UTC en milisegundos) → número de serie de Excel. */
function serialDia(int $ms): int
{
    return intdiv($ms, 86400000) + 25569 - ($ms < 0 && $ms % 86400000 !== 0 ? 1 : 0);
}

/** Instante en milisegundos → serie de Excel en la hora de la zona dada. */
function serialInstante(int $ms, DateTimeZone $zona): float
{
    $segundos = intdiv($ms, 1000);
    $desfase = $zona->getOffset(new DateTimeImmutable('@' . $segundos));
    return round(($segundos + $desfase) / 86400 + 25569, 6);
}

/** device_id → texto que entiende un coordinador. */
function origenLegible(string $deviceId): string
{
    if (str_starts_with($deviceId, 'admin:')) {
        return 'Panel (' . substr($deviceId, 6) . ')';
    }
    if (str_starts_with($deviceId, 'pwa')) {
        return 'App web';
    }
    if ($deviceId === '') {
        return '';
    }
    return 'App Android';
}
