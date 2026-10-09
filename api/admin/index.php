<?php
// La cookie de sesión no debe ser accesible desde JavaScript ni viajar en
// claro, y SameSite=Strict evita que se envíe en peticiones de otros sitios.
// Hay que fijarlo ANTES de session_start(), o no aplica.
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
]);
session_start();

require_once __DIR__ . '/../cors.php';
aplicarCabecerasDeSeguridad();
// Datos personales de salud: ni el navegador ni un proxy intermedio deben
// guardar copia de estas páginas ni del CSV exportado.
header('Cache-Control: no-store');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../esquema.php';
require_once __DIR__ . '/../rate_limit.php';
require_once __DIR__ . '/consultas.php';
require_once __DIR__ . '/vista.php';
$pdo = conectarBD();

/** Longitud mínima al crear o cambiar la contraseña de una cuenta. */
const MIN_LONGITUD_PASSWORD = 10;

/** Tiempo sin actividad tras el que la sesión del panel caduca. */
const INACTIVIDAD_MAXIMA_SEGUNDOS = 3600;

/**
 * Formato de documento de una cuenta: el mismo que exige api/auth/login.php.
 *
 * El panel no lo validaba. Con más de 20 caracteres, registrar el intento
 * fallido chocaba con el VARCHAR(20) de intentos_login y, con el modo estricto
 * de MySQL 8, la excepción sin capturar devolvía un 500. Y se podían crear
 * cuentas con documentos como "1.020.300" que login.php rechaza: esa persona
 * no conseguía entrar nunca a la app.
 */
const PATRON_DOCUMENTO_CUENTA = '/^[A-Za-z0-9\-]{1,20}$/';

/**
 * Zona en la que se muestran las horas y se cortan los días. Los servidores
 * trabajan en UTC, y sin esto el panel iba cinco horas adelantado.
 */
const ZONA_HORARIA = 'America/Bogota';

const PERSONAS_POR_PAGINA = 25;

date_default_timezone_set(ZONA_HORARIA);
$zona = new DateTimeZone(ZONA_HORARIA);

/** Parámetro GET como texto. Un arreglo (?q[]=x) cuenta como vacío. */
function textoGet(string $clave): string
{
    $valor = $_GET[$clave] ?? '';
    return is_string($valor) ? trim($valor) : '';
}

/** Parámetro POST como texto recortado. No usar para contraseñas. */
function textoPost(string $clave): string
{
    $valor = $_POST[$clave] ?? '';
    return is_string($valor) ? trim($valor) : '';
}

/** Deja la sesión como recién abierta, con identificador y token CSRF nuevos. */
function reiniciarSesion(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/**
 * Mensaje que se muestra UNA vez, en la página a la que se redirige.
 *
 * Antes el resultado viajaba en la URL (?borrada=1): reaparecía al recargar,
 * se quedaba en los marcadores, y cualquiera podía mandar un enlace que
 * mostrara "Persona borrada" sin haber borrado nada.
 *
 * @param array<string, string>|null $deshacer Persona que se puede restaurar desde el propio aviso.
 */
function avisar(string $tipo, string $texto, ?array $deshacer = null): void
{
    $_SESSION['aviso'] = ['tipo' => $tipo, 'texto' => $texto, 'deshacer' => $deshacer];
}

/**
 * Redirige con 303 tras un POST.
 *
 * Sin esto, recargar después de entrar o de guardar volvía a enviar el
 * formulario: con el token CSRF ya renovado, el panel mostraba "sesión
 * expirada" a alguien que acababa de entrar bien.
 */
function redirigir(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

/** Huella del hash de contraseña: permite notar que cambió sin guardarlo en la sesión. */
function huellaDe(mixed $hash): string
{
    return hash('sha256', is_string($hash) ? $hash : '');
}

/**
 * MODO ARRANQUE: no existe todavía ninguna cuenta de administrador.
 *
 * Solo mientras dura, se acepta la contraseña compartida de ADMIN_PASSWORD,
 * y sirve únicamente para entrar y crear el primer administrador con nombre.
 * En cuanto existe uno activo, deja de aceptarse y la variable se puede
 * borrar del entorno.
 *
 * Se apaga sola, que es lo que hace segura la transición: no hay que acordarse
 * de retirar nada para que el secreto compartido deje de valer.
 */
$modoArranque = contarAdminsActivos($pdo) === 0;
$adminPassword = getenv('ADMIN_PASSWORD');

if ($modoArranque && !$adminPassword) {
    // Sin administradores y sin contraseña de arranque no hay forma de entrar.
    // Se dice explícitamente porque el remedio no es evidente desde fuera.
    http_response_code(500);
    echo 'No hay ninguna cuenta de administrador y ADMIN_PASSWORD no está '
       . 'configurada. Define ADMIN_PASSWORD en el entorno para poder entrar y '
       . 'crear la primera cuenta.';
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// --- Revalidación de la sesión ----------------------------------------------
// Antes, entrar una vez bastaba para siempre: la marca admin_ok no se volvía a
// mirar. Desactivar a un administrador, quitarle el rol o cambiarle la
// contraseña no lo sacaba del panel. Y al crear el primer administrador solo
// se cerraba la sesión de quien lo creaba; las demás abiertas con
// ADMIN_PASSWORD seguían dentro. Ahora cada petición confirma que la cuenta
// sigue siendo lo que era al entrar.
if (!empty($_SESSION['admin_ok'])) {
    $motivo = null;
    $idSesion = $_SESSION['admin_id'] ?? null;

    if (time() - (int)($_SESSION['ultimo_uso'] ?? 0) > INACTIVIDAD_MAXIMA_SEGUNDOS) {
        $motivo = 'Tu sesión se cerró tras una hora sin actividad.';
    } elseif ($idSesion === null) {
        if (!$modoArranque) {
            $motivo = 'Ya existe una cuenta de administrador. Entra con tu documento y contraseña.';
        }
    } else {
        $cuenta = buscarCuentaPorId($pdo, (int)$idSesion);
        if ($cuenta === null || $cuenta['rol'] !== 'admin' || (int)$cuenta['activo'] !== 1
            || !hash_equals((string)($_SESSION['huella'] ?? ''), huellaDe($cuenta['password_hash']))) {
            $motivo = 'Tu acceso cambió desde que entraste. Vuelve a iniciar sesión.';
        } else {
            $_SESSION['admin_nombre'] = (string)$cuenta['nombre'];
        }
    }

    if ($motivo !== null) {
        reiniciarSesion();
        avisar('info', $motivo);
    } else {
        $_SESSION['ultimo_uso'] = time();
    }
}

$error = null;          // Error general: acceso o petición caducada.
$errorCuenta = null;    // Error del formulario de cuentas.
$formCuenta = null;     // Lo enviado, para no obligar a reescribirlo tras un error.
$documentoLogin = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = hash_equals((string)$_SESSION['csrf'], textoPost('csrf'));
    $action = textoPost('action');

    if (!$csrfOk) {
        $error = 'La página caducó. Vuelve a intentarlo.';
    } elseif ($action === 'login') {
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $documento = textoPost('numero_documento');
        $documentoLogin = $documento;

        // La clave del contador anti fuerza bruta es el documento cuando se
        // entra con cuenta, y la clave interna del panel en modo arranque,
        // donde no hay documento que usar.
        $claveIntentos = $modoArranque ? CLAVE_PANEL_ADMIN : $documento;

        if (!$modoArranque && $documento === '') {
            $error = 'Escribe tu número de documento.';
        } elseif (!$modoArranque && !preg_match(PATRON_DOCUMENTO_CUENTA, $documento)) {
            $error = 'El documento solo lleva letras, dígitos y guiones, hasta 20 caracteres.';
        } else {
            try {
                $bloqueo = segundosDeBloqueo($pdo, $claveIntentos);
                if ($bloqueo > 0) {
                    $minutos = (int)ceil($bloqueo / 60);
                    $error = "Demasiados intentos fallidos. Espera $minutos minuto(s).";
                } else {
                    // En modo arranque vale la contraseña compartida; con cuentas
                    // creadas, solo documento + contraseña de un administrador activo.
                    $cuenta = null;

                    if ($modoArranque) {
                        $entra = hash_equals((string)$adminPassword, $password);
                    } else {
                        $cuenta = buscarAdminPorDocumento($pdo, $documento);
                        // password_verify se ejecuta contra un hash falso cuando la
                        // cuenta no existe, para que responder tarde lo mismo en los
                        // dos casos y no se pueda deducir qué documentos son admin.
                        $hash = $cuenta['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
                        $entra = password_verify($password, (string)$hash) && $cuenta !== null;
                    }

                    if ($entra) {
                        // Se cambia el identificador de sesión al elevar privilegios.
                        // Sin esto, quien consiguiera fijar el PHPSESSID de la víctima
                        // antes del login seguiría dentro de la sesión ya autenticada
                        // (fijación de sesión).
                        session_regenerate_id(true);
                        $_SESSION['csrf'] = bin2hex(random_bytes(16));
                        $_SESSION['admin_ok'] = true;
                        $_SESSION['admin_id'] = $cuenta === null ? null : (int)$cuenta['id'];
                        $_SESSION['admin_nombre'] = $cuenta === null ? 'Administrador inicial' : (string)$cuenta['nombre'];
                        $_SESSION['huella'] = $cuenta === null ? '' : huellaDe($cuenta['password_hash']);
                        $_SESSION['ultimo_uso'] = time();
                        // Entrar bien borra el historial: al administrador legítimo no
                        // le debe quedar deuda por unos tecleos mal puestos de ayer.
                        limpiarIntentos($pdo, $claveIntentos);
                        redirigir(urlPanel(['seccion' => textoGet('seccion')]));
                    }

                    registrarIntentoFallido($pdo, $claveIntentos);
                    error_log('[admin] intento de acceso fallido al panel');
                    // Mensaje único: no revela si el documento corresponde a un
                    // administrador o si lo que falló fue la contraseña.
                    $error = $modoArranque ? 'Contraseña incorrecta.' : 'Documento o contraseña incorrectos.';
                }
            } catch (PDOException $e) {
                error_log('[admin] acceso: ' . $e->getMessage());
                $error = 'No se pudo comprobar el acceso. Intenta de nuevo.';
            }
        }
    } elseif ($action === 'logout') {
        // Se destruye la sesión entera, no solo la marca de autenticado.
        reiniciarSesion();
        avisar('ok', 'Sesión cerrada.');
        redirigir('index.php');
    } elseif (empty($_SESSION['admin_ok'])) {
        // Todo lo demás exige sesión. Si la revalidación de arriba la acaba de
        // cerrar, el aviso que dejó explica por qué se vuelve al acceso.
        redirigir('index.php');
    } elseif ($action === 'borrar_persona' || $action === 'restaurar_persona') {
        // Borrar existe para poder retirar registros que ningún cliente puede
        // tocar: los que se crearon antes de que el servidor validara el
        // documento y que ahora la propia validación impide reenviar.
        $tipo = textoPost('tipo_documento');
        $numero = textoPost('numero_documento');
        $nombre = textoPost('nombre');

        // Se vuelve a la misma búsqueda y página. Antes cada borrado devolvía
        // a la página 1 sin búsqueda, y había que volver a buscar para seguir.
        $volver = urlPanel([
            'seccion'  => 'personas',
            'borradas' => textoPost('volver_borradas') === '1' ? '1' : null,
            'q'        => textoPost('volver_q'),
            'p'        => ctype_digit(textoPost('volver_p')) ? textoPost('volver_p') : null,
        ]);

        if ($tipo === '' || $numero === '') {
            avisar('error', 'Falta el documento de la persona.');
            redirigir($volver);
        }

        $quien = $nombre !== '' ? $nombre : "$tipo $numero";
        // Queda registrado QUIÉN lo hizo, no solo que fue "el panel".
        $autor = 'admin:' . (string)($_SESSION['admin_nombre'] ?? '?');

        try {
            if ($action === 'borrar_persona') {
                if (borrarPersona($pdo, $tipo, $numero, $autor)) {
                    avisar(
                        'ok',
                        "Se borró a $quien. Los celulares la retirarán en su próxima sincronización.",
                        ['tipo_documento' => $tipo, 'numero_documento' => $numero, 'nombre' => $nombre]
                    );
                } else {
                    avisar('info', 'Esa persona ya no estaba en la base de datos.');
                }
            } elseif (restaurarPersona($pdo, $tipo, $numero, $autor)) {
                avisar('ok', "Se restauró a $quien. Volverá a los celulares en su próxima sincronización.");
            } else {
                avisar('info', 'Esa persona no estaba borrada.');
            }
        } catch (PDOException $e) {
            error_log('[admin] ' . $action . ': ' . $e->getMessage());
            avisar('error', 'No se pudo completar la acción. Intenta de nuevo.');
        }
        redirigir($volver);
    } elseif ($action === 'save') {
        $id = textoPost('id');
        $nombre = textoPost('nombre');
        $documento = textoPost('numero_documento');
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $activo = isset($_POST['activo']) ? 1 : 0;
        $rol = textoPost('rol') === 'admin' ? 'admin' : 'encuestador';
        $formCuenta = ['id' => $id, 'nombre' => $nombre, 'numero_documento' => $documento, 'rol' => $rol, 'activo' => $activo];

        $cuentaActual = ($id !== '' && ctype_digit($id)) ? buscarCuentaPorId($pdo, (int)$id) : null;

        // Deja de ser administrador activo tras este guardado, sea porque se
        // le cambia el rol o porque se le desactiva.
        $dejaDeSerAdmin = $cuentaActual !== null && ($rol !== 'admin' || $activo === 0);

        if ($id !== '' && $cuentaActual === null) {
            $errorCuenta = 'Esa cuenta ya no existe.';
        } elseif ($nombre === '' || $documento === '') {
            $errorCuenta = 'Nombre y número de documento son obligatorios.';
        } elseif (mb_strlen($nombre) > 100) {
            $errorCuenta = 'El nombre no puede superar 100 caracteres.';
        } elseif (!preg_match(PATRON_DOCUMENTO_CUENTA, $documento)) {
            $errorCuenta = 'El documento solo admite letras, dígitos y guiones, hasta 20 caracteres, '
                         . 'sin puntos ni espacios. Con otro formato la app no dejaría entrar.';
        } elseif ($id === '' && $password === '') {
            $errorCuenta = 'La contraseña es obligatoria para cuentas nuevas.';
        } elseif ($password !== '' && mb_strlen($password) < MIN_LONGITUD_PASSWORD) {
            // Solo se valida al fijar o cambiar la contraseña: las cuentas
            // existentes no quedan bloqueadas por una regla nueva.
            $errorCuenta = 'La contraseña debe tener al menos ' . MIN_LONGITUD_PASSWORD . ' caracteres.';
        } elseif ($dejaDeSerAdmin && esUltimoAdminActivo($pdo, (int)$id)) {
            // Sin esto se puede uno dejar fuera del panel con dos clics, y
            // recuperarlo exigiría entrar a la base de datos por SSH.
            $errorCuenta = 'No puedes quitar el rol de administrador ni desactivar la única cuenta '
                         . 'de administrador activa. Crea otra antes.';
        } else {
            try {
                asegurarRolEncuestador($pdo);
                $nuevoHash = $password !== '' ? password_hash($password, PASSWORD_BCRYPT) : null;

                if ($cuentaActual !== null) {
                    if ($nuevoHash !== null) {
                        $stmt = $pdo->prepare('UPDATE encuestadores SET nombre = ?, numero_documento = ?, password_hash = ?, activo = ?, rol = ? WHERE id = ?');
                        $stmt->execute([$nombre, $documento, $nuevoHash, $activo, $rol, $id]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE encuestadores SET nombre = ?, numero_documento = ?, activo = ?, rol = ? WHERE id = ?');
                        $stmt->execute([$nombre, $documento, $activo, $rol, $id]);
                    }

                    // Cambiar la contraseña o desactivar la cuenta saca también a
                    // los celulares ya autenticados. Sus datos sin enviar no se
                    // pierden: la PWA conserva la cola y Android reintenta los
                    // envíos en ERROR; ambos piden entrar de nuevo y los suben.
                    if ($nuevoHash !== null || $activo === 0) {
                        revocarSesionesApi($pdo, (int)$id);
                    }

                    // Quien cambia su propia contraseña sigue dentro: se renueva
                    // la huella para que la revalidación no lo expulse.
                    if ($nuevoHash !== null && (int)$id === ($_SESSION['admin_id'] ?? null)) {
                        $_SESSION['huella'] = huellaDe($nuevoHash);
                    }
                } else {
                    $stmt = $pdo->prepare('INSERT INTO encuestadores (nombre, numero_documento, password_hash, activo, rol) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([$nombre, $documento, $nuevoHash, $activo, $rol]);
                }

                // Si se acaba de crear el primer administrador estando en modo
                // arranque, la sesión sigue siendo la de la contraseña
                // compartida. Se cierra para obligar a entrar con la cuenta
                // nueva. (Las demás sesiones de arranque las cierra la
                // revalidación en su siguiente petición.)
                if ($modoArranque && $rol === 'admin' && $activo === 1) {
                    reiniciarSesion();
                    avisar('ok', 'Cuenta de administrador creada. Entra con su documento y contraseña. '
                               . 'La contraseña de arranque ya no sirve: puedes borrar ADMIN_PASSWORD del entorno.');
                    redirigir('index.php');
                }

                avisar('ok', $cuentaActual === null ? "Cuenta de $nombre creada." : "Cambios guardados en la cuenta de $nombre.");
                redirigir(urlPanel(['seccion' => 'cuentas']));
            } catch (PDOException $e) {
                error_log('[admin] guardar cuenta: ' . $e->getMessage());
                $errorCuenta = ($e->getCode() === '23000')
                    ? 'Ese número de documento ya está registrado en otra cuenta.'
                    : 'No se pudo guardar. Intenta de nuevo.';
            }
        }
    }
}

$loggedIn = !empty($_SESSION['admin_ok']);

$aviso = is_array($_SESSION['aviso'] ?? null) ? $_SESSION['aviso'] : null;
unset($_SESSION['aviso']);

// --- Exportación a CSV -------------------------------------------------------
// Va antes de emitir HTML: una vez enviado el cuerpo ya no se pueden cambiar
// las cabeceras.
if ($loggedIn && textoGet('exportar') === 'personas') {
    $filas = personasParaExportar($pdo);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="personas-' . date('Y-m-d') . '.csv"');
    $salida = fopen('php://output', 'w');
    if ($salida === false) {
        error_log('[admin] no se pudo abrir php://output para exportar');
        exit;
    }
    // BOM para que Excel reconozca el UTF-8 y no destroce las tildes.
    fwrite($salida, "\xEF\xBB\xBF");
    if ($filas !== []) {
        fputcsv($salida, array_keys($filas[0]), ';');
        foreach ($filas as $f) {
            // La fecha de nacimiento es un DÍA que los clientes guardan como
            // medianoche UTC: se lee en UTC, o en Colombia saldría el día
            // anterior. updated_at sí es un instante y va en hora local.
            if (is_numeric($f['fecha_nacimiento'])) {
                $f['fecha_nacimiento'] = gmdate('Y-m-d', intdiv((int)$f['fecha_nacimiento'], 1000));
            }
            if (is_numeric($f['updated_at'])) {
                $f['updated_at'] = date('Y-m-d H:i', intdiv((int)$f['updated_at'], 1000));
            }
            fputcsv($salida, array_map('celdaCsv', $f), ';');
        }
    }
    exit;
}

// --- Datos de la vista -------------------------------------------------------
$seccion = textoGet('seccion');
if ($seccion === 'encuestadores') {
    // Nombre anterior de la sección: los enlaces guardados siguen sirviendo.
    $seccion = 'cuentas';
}
if (!in_array($seccion, ['resumen', 'personas', 'cuentas'], true)) {
    $seccion = 'resumen';
}

$editarId = textoGet('editar') !== '' ? textoGet('editar') : textoGet('edit');
if ($loggedIn && $formCuenta === null && $editarId !== '') {
    $seccion = 'cuentas';
    $cuentaEditada = ctype_digit($editarId) ? buscarCuentaPorId($pdo, (int)$editarId) : null;
    if ($cuentaEditada === null) {
        $errorCuenta = 'Esa cuenta no existe. Puedes crear una nueva.';
    } else {
        $formCuenta = [
            'id'               => (string)$cuentaEditada['id'],
            'nombre'           => (string)$cuentaEditada['nombre'],
            'numero_documento' => (string)($cuentaEditada['numero_documento'] ?? ''),
            'rol'              => (string)$cuentaEditada['rol'],
            'activo'           => (int)$cuentaEditada['activo'],
        ];
    }
}
if ($errorCuenta !== null) {
    $seccion = 'cuentas';
}
// En modo arranque lo único que tiene sentido crear es el primer administrador.
$formCuenta ??= ['id' => '', 'nombre' => '', 'numero_documento' => '', 'rol' => $modoArranque ? 'admin' : 'encuestador', 'activo' => 1];
$editando = $formCuenta['id'] !== '';

$resumen = ['personas' => 0, 'borradas' => 0, 'encuestas' => 0, 'encuestadores' => 0, 'cuentas' => 0, 'dispositivos' => 0, 'ultima_sync' => null];
$porDia = [];
$porMunicipio = [];
$porEncuestador = [];
$cuentas = [];

$busqueda = mb_substr(textoGet('q'), 0, 100);
$verBorradas = textoGet('borradas') === '1';
$pagina = max(1, (int)textoGet('p'));
$personas = [];
$totalActivas = 0;
$totalBorradas = 0;
$totalPersonas = 0;
$totalPaginas = 1;

if ($loggedIn) {
    if ($seccion === 'resumen') {
        $resumen        = resumenGeneral($pdo);
        $porDia         = encuestasPorDia($pdo, $zona, 14);
        $porMunicipio   = personasPorMunicipio($pdo);
        $porEncuestador = encuestasPorEncuestador($pdo);
    } elseif ($seccion === 'personas') {
        $totalActivas  = contarPersonas($pdo);
        $totalBorradas = contarPersonas($pdo, '', true);
        $totalPersonas = $busqueda === ''
            ? ($verBorradas ? $totalBorradas : $totalActivas)
            : contarPersonas($pdo, $busqueda, $verBorradas);
        $totalPaginas = max(1, (int)ceil($totalPersonas / PERSONAS_POR_PAGINA));
        // Una página fuera de rango (?p=999, o la última tras borrar su única
        // fila) decía "todavía no se ha sincronizado ninguna persona" y
        // escondía la paginación. Se lleva a la última que existe.
        $pagina = min($pagina, $totalPaginas);
        $personas = consultarPersonas($pdo, $busqueda, PERSONAS_POR_PAGINA, ($pagina - 1) * PERSONAS_POR_PAGINA, $verBorradas);
    } else {
        $cuentas = consultarEncuestadores($pdo);
    }
}

$titulos = ['resumen' => 'Resumen', 'personas' => 'Personas', 'cuentas' => 'Cuentas'];
$descripciones = [
    'resumen'  => 'Cómo va la recolección en campo.',
    'personas' => 'Personas registradas por los encuestadores, tal como llegan a todos los celulares.',
    'cuentas'  => 'Encuestadores de campo y administradores de este panel.',
];
$csrf = (string)$_SESSION['csrf'];
$idAdmin = $_SESSION['admin_id'] ?? null;
$version = fn (string $archivo): int => (int)(filemtime(__DIR__ . '/' . $archivo) ?: 0);

// Estado de la lista de personas, para volver a ella tras borrar o restaurar.
$camposVolver = '<input type="hidden" name="volver_q" value="' . h($busqueda) . '">'
              . '<input type="hidden" name="volver_p" value="' . $pagina . '">'
              . '<input type="hidden" name="volver_borradas" value="' . ($verBorradas ? '1' : '') . '">';

$htmlAviso = '';
if ($aviso !== null) {
    $contenido = h($aviso['texto'] ?? '');
    $deshacer = $aviso['deshacer'] ?? null;
    if ($loggedIn && is_array($deshacer)) {
        $contenido .= ' <form method="post" action="index.php">'
            . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
            . '<input type="hidden" name="action" value="restaurar_persona">'
            . '<input type="hidden" name="tipo_documento" value="' . h($deshacer['tipo_documento'] ?? '') . '">'
            . '<input type="hidden" name="numero_documento" value="' . h($deshacer['numero_documento'] ?? '') . '">'
            . '<input type="hidden" name="nombre" value="' . h($deshacer['nombre'] ?? '') . '">'
            . $camposVolver
            . '<button type="submit" class="btn-enlace">Deshacer</button></form>';
    }
    $htmlAviso = cajaAviso((string)($aviso['tipo'] ?? 'info'), $contenido);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $loggedIn ? h($titulos[$seccion]) . ' · ' : '' ?>Panel de administración · ColOffline</title>
<link rel="icon" href="../../pwa/icons/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="admin.css?v=<?= $version('admin.css') ?>">
<script src="admin.js?v=<?= $version('admin.js') ?>" defer></script>
</head>

<?php if (!$loggedIn): ?>
<body class="pagina-acceso">
<div class="franja"></div>
<main class="acceso">
  <div class="marca">
    <img src="../../pwa/icons/icon.svg" alt="" width="38" height="38">
    <div>
      <p class="marca-nombre">ColOffline</p>
      <p class="marca-sub">Ministerio de Salud · Encuestas demográficas</p>
    </div>
  </div>

  <section class="tarjeta acceso-tarjeta" aria-labelledby="titulo-acceso">
    <div class="titulo">
      <h1 id="titulo-acceso">Panel de administración</h1>
      <p><?= $modoArranque ? 'Configuración inicial del panel.' : 'Entra con una cuenta de administrador.' ?></p>
    </div>

    <?= $htmlAviso ?>
    <?php if ($error !== null): ?><?= cajaAviso('error', h($error)) ?><?php endif; ?>

    <?php if ($modoArranque): ?>
      <?= cajaAviso('info', 'Todavía no hay ninguna cuenta de administrador. Entra con la contraseña de '
          . 'arranque (<strong>ADMIN_PASSWORD</strong>) y crea la primera desde <strong>Cuentas</strong>. '
          . 'En cuanto exista, esa contraseña dejará de aceptarse.') ?>
    <?php endif; ?>

    <form method="post" action="<?= h(urlPanel(['seccion' => textoGet('seccion')])) ?>">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="login">

      <?php if (!$modoArranque): ?>
        <div class="campo">
          <label class="campo-etiqueta" for="login-doc">Número de documento</label>
          <input class="input" type="text" id="login-doc" name="numero_documento" value="<?= h($documentoLogin) ?>"
                 autocomplete="username" maxlength="20" required <?= $documentoLogin === '' ? 'autofocus' : '' ?>>
        </div>
      <?php endif; ?>

      <div class="campo">
        <label class="campo-etiqueta" for="login-clave"><?= $modoArranque ? 'Contraseña de arranque' : 'Contraseña' ?></label>
        <div class="input-grupo">
          <input class="input" type="password" id="login-clave" name="password" autocomplete="current-password" required
                 <?= $modoArranque || $documentoLogin !== '' ? 'autofocus' : '' ?>>
          <button type="button" class="ver-clave" data-ver-clave="login-clave" aria-pressed="false" hidden>Ver</button>
        </div>
      </div>

      <button class="btn btn-primario btn-bloque" type="submit">Ingresar</button>
    </form>
  </section>

  <a class="acceso-volver" href="../../pwa/"><?= icono('volver', 16) ?>Volver a la app de encuestas</a>
</main>
</body>

<?php else: ?>
<body>
<a class="saltar" href="#contenido">Saltar al contenido</a>
<div class="app">

  <aside class="lateral">
    <div class="marca">
      <img src="../../pwa/icons/icon.svg" alt="" width="38" height="38">
      <div>
        <p class="marca-nombre">ColOffline</p>
        <p class="marca-sub">Panel de administración</p>
      </div>
    </div>

    <nav class="nav" aria-label="Secciones">
      <?php foreach ($titulos as $clave => $titulo): ?>
        <a href="<?= h(urlPanel(['seccion' => $clave])) ?>"<?= $seccion === $clave ? ' aria-current="page"' : '' ?>>
          <?= icono($clave) ?><span><?= h($titulo) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="lateral-pie">
      <div class="usuario">
        <span class="avatar" aria-hidden="true"><?= h(iniciales((string)($_SESSION['admin_nombre'] ?? ''))) ?></span>
        <div class="usuario-datos">
          <p class="usuario-nombre"><?= h($_SESSION['admin_nombre'] ?? 'Administrador') ?></p>
          <p class="usuario-rol"><?= $idAdmin === null ? 'Contraseña de arranque' : 'Administrador' ?></p>
        </div>
      </div>
      <form method="post" action="index.php">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="logout">
        <button class="btn btn-fantasma btn-bloque" type="submit" aria-label="Cerrar sesión" title="Cerrar sesión">
          <?= icono('salir') ?><span>Cerrar sesión</span>
        </button>
      </form>
    </div>
  </aside>

  <main class="principal" id="contenido">
    <header class="encabezado">
      <div>
        <h1><?= h($titulos[$seccion]) ?></h1>
        <p><?= h($descripciones[$seccion]) ?></p>
      </div>
      <div class="encabezado-acciones">
        <?php if ($seccion === 'personas' && !$verBorradas && $totalActivas > 0): ?>
          <a class="btn btn-secundario" href="<?= h(urlPanel(['exportar' => 'personas'])) ?>">
            <?= icono('descargar', 16) ?>Exportar CSV
          </a>
        <?php elseif ($seccion === 'cuentas'): ?>
          <a class="btn btn-primario" href="<?= h(urlPanel(['seccion' => 'cuentas'])) ?>#form-cuenta">
            <?= icono('mas', 16) ?>Nueva cuenta
          </a>
        <?php endif; ?>
      </div>
    </header>

    <div class="pila">
    <?= $htmlAviso ?>
    <?php if ($error !== null): ?><?= cajaAviso('error', h($error)) ?><?php endif; ?>

    <?php if ($seccion === 'resumen'):
        $totales = array_column($porDia, 'total');
        $totalDias = array_sum($totales);
        $maxDia = $totales === [] ? 0 : max($totales);
        $tope = topeEje($maxDia);
        $indiceMax = $maxDia > 0 ? array_search($maxDia, $totales, true) : false;
        $ultimoDia = count($porDia) - 1;
    ?>

      <section class="tarjeta cifras" aria-label="Totales">
        <div class="cifra">
          <p class="cifra-etiqueta">Personas activas</p>
          <p class="cifra-valor"><?= numero($resumen['personas']) ?></p>
          <p class="cifra-nota"><?= $resumen['borradas'] > 0 ? h(numero($resumen['borradas']) . ' en la papelera') : 'Papelera vacía' ?></p>
        </div>
        <div class="cifra">
          <p class="cifra-etiqueta">Encuestas</p>
          <p class="cifra-valor"><?= numero($resumen['encuestas']) ?></p>
          <p class="cifra-nota"><?= h(numero($totalDias)) ?> en los últimos 14 días</p>
        </div>
        <div class="cifra">
          <p class="cifra-etiqueta">Encuestadores activos</p>
          <p class="cifra-valor"><?= numero($resumen['encuestadores']) ?></p>
          <p class="cifra-nota"><?= h(cantidad($resumen['cuentas'], 'cuenta', 'cuentas')) ?> en total</p>
        </div>
        <div class="cifra">
          <p class="cifra-etiqueta">Dispositivos</p>
          <p class="cifra-valor"><?= numero($resumen['dispositivos']) ?></p>
          <p class="cifra-nota">Han enviado alguna encuesta</p>
        </div>
      </section>

      <section class="tarjeta" aria-labelledby="t-dias">
        <div class="tarjeta-cabecera">
          <div>
            <h2 id="t-dias">Encuestas por día</h2>
            <?php // El eje solo lleva el número del día: el rango dice de qué meses son. ?>
            <p>
              <?= $porDia === [] ? 'Últimos 14 días' : h(etiquetaDia($porDia[0]['dia'])['larga'] . ' – ' . etiquetaDia($porDia[$ultimoDia]['dia'])['larga']) ?>
              · hora de Colombia
            </p>
          </div>
          <p class="tarjeta-dato">Última sincronización<br><strong><?= h(haceCuanto($resumen['ultima_sync'])) ?></strong></p>
        </div>
        <div class="tarjeta-cuerpo">
          <?php if ($totalDias === 0): ?>
            <div class="vacio">
              <?= icono('vacio', 28) ?>
              <strong>Sin encuestas en los últimos 14 días</strong>
              Cuando los encuestadores sincronicen, aquí verás la actividad de cada día.
            </div>
          <?php else: ?>
            <p class="sr-only">
              En los últimos 14 días se sincronizaron <?= h(cantidad($totalDias, 'encuesta', 'encuestas')) ?>.
              <?php if ($indiceMax !== false): ?>El día con más fue <?= h(etiquetaDia($porDia[$indiceMax]['dia'])['larga']) ?>, con <?= numero($maxDia) ?>.<?php endif; ?>
              Los valores de cada día están en la tabla "Ver datos".
            </p>
            <div class="grafico" aria-hidden="true" style="--columnas: <?= count($porDia) ?>">
              <div class="grafico-eje-y">
                <span style="top: 0"><?= numero($tope) ?></span>
                <span style="top: 50%"><?= numero(intdiv($tope, 2)) ?></span>
                <span style="top: 100%">0</span>
              </div>
              <div class="grafico-area">
                <span class="grafico-guia" style="top: 0"></span>
                <span class="grafico-guia" style="top: 50%"></span>
                <?php foreach ($porDia as $i => $d):
                    $etiqueta = etiquetaDia($d['dia']);
                    $borde = $i < 2 ? ' borde-ini' : ($i > $ultimoDia - 2 ? ' borde-fin' : ''); ?>
                  <div class="grafico-col<?= $borde ?>" style="--h: <?= round(100 * $d['total'] / $tope, 2) ?>%">
                    <span class="grafico-barra"></span>
                    <?php if ($i === $indiceMax): ?><span class="grafico-valor"><?= numero($d['total']) ?></span><?php endif; ?>
                    <span class="grafico-tip"><?= h($etiqueta['larga']) ?> · <strong><?= h(cantidad($d['total'], 'encuesta', 'encuestas')) ?></strong></span>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="grafico-eje-x">
                <?php foreach ($porDia as $i => $d):
                    $etiqueta = etiquetaDia($d['dia']);
                    // Hoy siempre rotulado; en pantallas estrechas se oculta uno de cada dos.
                    $clases = trim(($i === $ultimoDia ? 'hoy' : '') . (($ultimoDia - $i) % 2 === 1 ? ' alterno' : '')); ?>
                  <span class="<?= $clases ?>"><?= h($etiqueta['dia']) ?><span class="semana"><?= $i === $ultimoDia ? 'hoy' : h($etiqueta['semana']) ?></span></span>
                <?php endforeach; ?>
              </div>
            </div>

            <details class="datos">
              <summary>Ver datos</summary>
              <div class="tabla-contenedor">
                <table class="tabla tabla-compacta">
                  <thead><tr><th scope="col">Día</th><th scope="col" class="num">Encuestas</th></tr></thead>
                  <tbody>
                    <?php foreach (array_reverse($porDia) as $d): ?>
                      <tr><td><?= h(etiquetaDia($d['dia'])['larga']) ?></td><td class="num"><?= numero($d['total']) ?></td></tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </details>
          <?php endif; ?>
        </div>
      </section>

      <div class="dos-columnas">
        <section class="tarjeta" aria-labelledby="t-municipios">
          <div class="tarjeta-cabecera">
            <div>
              <h2 id="t-municipios">Personas por municipio</h2>
              <p>Los municipios con más personas activas</p>
            </div>
          </div>
          <div class="tarjeta-cuerpo">
            <?php if ($porMunicipio === []): ?>
              <div class="vacio">Todavía no hay personas registradas.</div>
            <?php else:
                $maxMunicipio = max(array_column($porMunicipio, 'total')) ?: 1; ?>
              <ol class="ranking">
                <?php foreach ($porMunicipio as $m): ?>
                  <li>
                    <span class="ranking-nombre"><?= h($m['municipio']) ?><?php if ($m['departamento'] !== '—' && $m['departamento'] !== $m['municipio']): ?> <small>· <?= h($m['departamento']) ?></small><?php endif; ?></span>
                    <span class="ranking-valor"><?= numero($m['total']) ?></span>
                    <span class="ranking-pista" aria-hidden="true"><span class="ranking-relleno" style="--w: <?= round(100 * $m['total'] / $maxMunicipio, 2) ?>%"></span></span>
                  </li>
                <?php endforeach; ?>
              </ol>
            <?php endif; ?>
          </div>
        </section>

        <section class="tarjeta" aria-labelledby="t-encuestadores">
          <div class="tarjeta-cabecera">
            <div>
              <h2 id="t-encuestadores">Encuestas por encuestador</h2>
              <p>Encuestadores activos y cualquier cuenta con encuestas</p>
            </div>
          </div>
          <div class="tarjeta-cuerpo">
            <?php if ($porEncuestador === []): ?>
              <div class="vacio">Todavía no hay encuestadores activos.</div>
            <?php else:
                $maxEncuestador = max(array_column($porEncuestador, 'total')) ?: 1; ?>
              <ol class="ranking">
                <?php foreach ($porEncuestador as $e): ?>
                  <li>
                    <span class="ranking-nombre"><?= h($e['nombre']) ?></span>
                    <span class="ranking-valor"><?= numero($e['total']) ?></span>
                    <span class="ranking-pista" aria-hidden="true"><span class="ranking-relleno" style="--w: <?= round(100 * $e['total'] / $maxEncuestador, 2) ?>%"></span></span>
                  </li>
                <?php endforeach; ?>
              </ol>
            <?php endif; ?>
          </div>
        </section>
      </div>

    <?php elseif ($seccion === 'personas'): ?>

      <section class="tarjeta" aria-label="<?= $verBorradas ? 'Papelera' : 'Personas activas' ?>">
        <div class="barra-herramientas">
          <nav class="pestanas" aria-label="Estado de las personas">
            <a href="<?= h(urlPanel(['seccion' => 'personas'])) ?>"<?= !$verBorradas ? ' aria-current="page"' : '' ?>>
              Activas <span class="conteo"><?= numero($totalActivas) ?></span>
            </a>
            <a href="<?= h(urlPanel(['seccion' => 'personas', 'borradas' => '1'])) ?>"<?= $verBorradas ? ' aria-current="page"' : '' ?>>
              Papelera <span class="conteo"><?= numero($totalBorradas) ?></span>
            </a>
          </nav>

          <form class="buscador" method="get" action="index.php" role="search">
            <input type="hidden" name="seccion" value="personas">
            <?php if ($verBorradas): ?><input type="hidden" name="borradas" value="1"><?php endif; ?>
            <div class="buscador-campo">
              <label class="sr-only" for="buscar">Buscar personas</label>
              <?= icono('buscar', 16) ?>
              <input class="input" type="search" id="buscar" name="q" value="<?= h($busqueda) ?>"
                     placeholder="Nombre completo o documento" maxlength="100">
            </div>
            <button class="btn btn-secundario" type="submit">Buscar</button>
            <?php if ($busqueda !== ''): ?>
              <a class="btn btn-fantasma" href="<?= h(urlPanel(['seccion' => 'personas', 'borradas' => $verBorradas ? '1' : null])) ?>">
                <?= icono('cerrar', 16) ?>Limpiar
              </a>
            <?php endif; ?>
          </form>
        </div>

        <?php if ($verBorradas && $totalBorradas > 0): ?>
          <?= cajaAviso('info', 'Siguen en la base de datos, marcadas como borradas, y los celulares las ocultan. '
              . 'Restaurar devuelve la persona a todos los dispositivos en su próxima sincronización.') ?>
        <?php endif; ?>

        <?php if ($busqueda !== '' && $personas !== []): ?>
          <p class="nota-lista" role="status"><?= h(cantidad($totalPersonas, 'resultado', 'resultados')) ?> para «<?= h($busqueda) ?>»</p>
        <?php endif; ?>

        <?php if ($personas === []): ?>
          <div class="vacio">
            <?= icono($busqueda !== '' ? 'buscar' : 'vacio', 28) ?>
            <?php if ($busqueda !== ''): ?>
              <strong>Sin resultados</strong>
              Ninguna persona <?= $verBorradas ? 'de la papelera ' : '' ?>coincide con «<?= h($busqueda) ?>».
              <br><a class="btn btn-secundario" href="<?= h(urlPanel(['seccion' => 'personas', 'borradas' => $verBorradas ? '1' : null])) ?>">Limpiar búsqueda</a>
            <?php elseif ($verBorradas): ?>
              <strong>La papelera está vacía</strong>
              Las personas que borres aparecerán aquí y podrás restaurarlas.
            <?php else: ?>
              <strong>Todavía no hay personas</strong>
              Aparecerán aquí cuando los encuestadores sincronicen sus registros.
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="tabla-contenedor">
            <table class="tabla">
              <thead>
                <tr>
                  <th scope="col">Persona</th>
                  <th scope="col">Municipio</th>
                  <th scope="col">Vereda</th>
                  <th scope="col">EPS</th>
                  <th scope="col" class="num">Estrato</th>
                  <th scope="col"><?= $verBorradas ? 'Borrada' : 'Actualizada' ?></th>
                  <th scope="col"><span class="sr-only">Acciones</span></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($personas as $p):
                    $nombreCompleto = trim((string)$p['nombres'] . ' ' . (string)$p['apellidos']);
                    $confirmacion = $verBorradas
                        ? "¿Restaurar a $nombreCompleto? Volverá a aparecer en todos los celulares en su próxima sincronización."
                        : "¿Borrar a $nombreCompleto? Desaparecerá también de los celulares en su próxima sincronización."; ?>
                  <tr>
                    <td>
                      <span class="celda-principal"><?= h($nombreCompleto) ?></span>
                      <span class="celda-sec"><?= h($p['tipo_documento']) ?> <?= h($p['numero_documento']) ?></span>
                    </td>
                    <td>
                      <?php if (!empty($p['municipio'])): ?>
                        <?= h($p['municipio']) ?><span class="celda-sec"><?= h($p['departamento']) ?></span>
                      <?php else: ?><span class="apagado">—</span><?php endif; ?>
                    </td>
                    <td><?= !empty($p['vereda']) ? h($p['vereda']) : '<span class="apagado">—</span>' ?></td>
                    <td><?= !empty($p['eps']) ? h($p['eps']) : '<span class="apagado">—</span>' ?></td>
                    <td class="num"><?= !empty($p['estrato']) ? h($p['estrato']) : '<span class="apagado">—</span>' ?></td>
                    <td class="celda-fecha"><?= h(fecha($verBorradas ? $p['deleted_at'] : $p['updated_at'])) ?></td>
                    <td class="acciones">
                      <?php // El confirm() no es seguridad, solo evita el clic accidental:
                            // quien tenga la sesión puede enviar el POST igualmente. ?>
                      <form method="post" action="index.php" data-confirmar="<?= h($confirmacion) ?>">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="<?= $verBorradas ? 'restaurar_persona' : 'borrar_persona' ?>">
                        <input type="hidden" name="tipo_documento" value="<?= h($p['tipo_documento']) ?>">
                        <input type="hidden" name="numero_documento" value="<?= h($p['numero_documento']) ?>">
                        <input type="hidden" name="nombre" value="<?= h($nombreCompleto) ?>">
                        <?= $camposVolver ?>
                        <?php if ($verBorradas): ?>
                          <button type="submit" class="btn btn-secundario btn-sm" aria-label="Restaurar a <?= h($nombreCompleto) ?>">
                            <?= icono('restaurar', 14) ?>Restaurar
                          </button>
                        <?php else: ?>
                          <button type="submit" class="btn btn-peligro btn-sm" aria-label="Borrar a <?= h($nombreCompleto) ?>">
                            <?= icono('borrar', 14) ?>Borrar
                          </button>
                        <?php endif; ?>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php
            $desde = ($pagina - 1) * PERSONAS_POR_PAGINA + 1;
            $hasta = min($pagina * PERSONAS_POR_PAGINA, $totalPersonas);
            $base = ['seccion' => 'personas', 'borradas' => $verBorradas ? '1' : null, 'q' => $busqueda];
          ?>
          <nav class="paginacion" aria-label="Paginación">
            <span>Mostrando <?= numero($desde) ?>–<?= numero($hasta) ?> de <?= numero($totalPersonas) ?></span>
            <span class="paginacion-botones">
              <?php if ($pagina > 1): ?>
                <a class="btn btn-secundario btn-sm" href="<?= h(urlPanel($base + ['p' => $pagina - 1])) ?>"><?= icono('anterior', 14) ?>Anterior</a>
              <?php else: ?>
                <span class="btn btn-secundario btn-sm" aria-disabled="true"><?= icono('anterior', 14) ?>Anterior</span>
              <?php endif; ?>
              <span>Página <?= $pagina ?> de <?= $totalPaginas ?></span>
              <?php if ($pagina < $totalPaginas): ?>
                <a class="btn btn-secundario btn-sm" href="<?= h(urlPanel($base + ['p' => $pagina + 1])) ?>">Siguiente<?= icono('siguiente', 14) ?></a>
              <?php else: ?>
                <span class="btn btn-secundario btn-sm" aria-disabled="true">Siguiente<?= icono('siguiente', 14) ?></span>
              <?php endif; ?>
            </span>
          </nav>
        <?php endif; ?>
      </section>

    <?php else: ?>

      <?php if ($modoArranque): ?>
        <?= cajaAviso('advertencia', 'Estás dentro con la contraseña de arranque. Crea una cuenta con rol '
            . '<strong>Administrador</strong>: al guardarla, esa contraseña dejará de aceptarse y entrarás '
            . 'con documento y contraseña.') ?>
      <?php endif; ?>

      <div class="rejilla-cuentas">
        <section class="tarjeta" aria-label="Lista de cuentas">
          <?php if ($cuentas === []): ?>
            <div class="vacio">
              <?= icono('cuentas', 28) ?>
              <strong>No hay cuentas</strong>
              Crea la primera con el formulario.
            </div>
          <?php else: ?>
            <div class="tabla-contenedor">
              <table class="tabla">
                <thead>
                  <tr>
                    <th scope="col">Cuenta</th>
                    <th scope="col">Rol</th>
                    <th scope="col">Estado</th>
                    <th scope="col" class="num">Encuestas</th>
                    <th scope="col">Última encuesta</th>
                    <th scope="col"><span class="sr-only">Acciones</span></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($cuentas as $c):
                      $esAdmin = ($c['rol'] ?? 'encuestador') === 'admin';
                      $esActiva = (int)$c['activo'] === 1;
                      $esEditada = $editando && (string)$c['id'] === $formCuenta['id']; ?>
                    <tr<?= $esEditada ? ' class="fila-activa"' : '' ?>>
                      <td>
                        <span class="celda-principal"><?= h($c['nombre']) ?></span><?php if ((int)$c['id'] === $idAdmin): ?><span class="insignia insignia-tu">Tú</span><?php endif; ?>
                        <span class="celda-sec"><?= !empty($c['numero_documento']) ? h($c['numero_documento']) : 'Sin documento' ?></span>
                      </td>
                      <td><span class="insignia<?= $esAdmin ? ' insignia-admin' : '' ?>"><?= $esAdmin ? 'Administrador' : 'Encuestador' ?></span></td>
                      <td><span class="estado<?= $esActiva ? ' estado-activo' : '' ?>"><?= $esActiva ? 'Activa' : 'Inactiva' ?></span></td>
                      <td class="num"><?= numero((int)$c['encuestas']) ?></td>
                      <td class="celda-fecha"><?= h(haceCuanto($c['ultima_actividad'])) ?></td>
                      <td class="acciones">
                        <a class="btn btn-fantasma btn-sm" href="<?= h(urlPanel(['seccion' => 'cuentas', 'editar' => (string)$c['id']])) ?>#form-cuenta"
                           aria-label="Editar la cuenta de <?= h($c['nombre']) ?>"><?= icono('editar', 14) ?>Editar</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>

        <section class="tarjeta form-cuenta" id="form-cuenta" aria-labelledby="t-form-cuenta">
          <div class="tarjeta-cabecera">
            <div>
              <h2 id="t-form-cuenta"><?= $editando ? 'Editar cuenta' : 'Nueva cuenta' ?></h2>
              <p><?= $editando ? h($formCuenta['nombre']) : 'Para un encuestador o un administrador.' ?></p>
            </div>
          </div>
          <form class="tarjeta-cuerpo" method="post"
                action="<?= h(urlPanel(['seccion' => 'cuentas', 'editar' => $editando ? $formCuenta['id'] : null])) ?>#form-cuenta">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= h($formCuenta['id']) ?>">

            <?php if ($errorCuenta !== null): ?><?= cajaAviso('error', h($errorCuenta)) ?><?php endif; ?>

            <div class="campo">
              <label class="campo-etiqueta" for="cuenta-nombre">Nombre completo</label>
              <input class="input" type="text" id="cuenta-nombre" name="nombre" value="<?= h($formCuenta['nombre']) ?>"
                     maxlength="100" required autocomplete="off">
            </div>

            <div class="campo">
              <label class="campo-etiqueta" for="cuenta-doc">Número de documento</label>
              <input class="input" type="text" id="cuenta-doc" name="numero_documento" value="<?= h($formCuenta['numero_documento']) ?>"
                     maxlength="20" pattern="[A-Za-z0-9\-]{1,20}" required autocomplete="off" aria-describedby="ayuda-doc">
              <p class="campo-ayuda" id="ayuda-doc">Con él se entra a la app. Letras, dígitos y guiones.</p>
            </div>

            <div class="campo">
              <label class="campo-etiqueta" for="cuenta-clave">Contraseña</label>
              <div class="input-grupo">
                <input class="input" type="password" id="cuenta-clave" name="password" autocomplete="new-password"
                       minlength="<?= MIN_LONGITUD_PASSWORD ?>" <?= $editando ? '' : 'required' ?> aria-describedby="ayuda-clave">
                <button type="button" class="ver-clave" data-ver-clave="cuenta-clave" aria-pressed="false" hidden>Ver</button>
              </div>
              <p class="campo-ayuda" id="ayuda-clave">
                <?= $editando
                    ? 'Déjala vacía para no cambiarla. Si la cambias, se cierran sus sesiones en los celulares.'
                    : 'Mínimo ' . MIN_LONGITUD_PASSWORD . ' caracteres.' ?>
              </p>
            </div>

            <fieldset class="grupo-opciones">
              <legend class="campo-etiqueta">Rol</legend>
              <label class="opcion">
                <input type="radio" name="rol" value="encuestador"<?= $formCuenta['rol'] !== 'admin' ? ' checked' : '' ?>>
                <span><strong>Encuestador</strong><span>Registra personas desde la app en campo.</span></span>
              </label>
              <label class="opcion">
                <input type="radio" name="rol" value="admin"<?= $formCuenta['rol'] === 'admin' ? ' checked' : '' ?>>
                <span><strong>Administrador</strong><span>Además entra a este panel: ve y borra personas, y gestiona cuentas.</span></span>
              </label>
            </fieldset>

            <div class="campo">
              <label class="casilla">
                <input type="checkbox" name="activo"<?= (int)$formCuenta['activo'] === 1 ? ' checked' : '' ?> aria-describedby="ayuda-activo">
                Cuenta activa
              </label>
              <p class="campo-ayuda" id="ayuda-activo">Una cuenta inactiva no puede entrar ni sincronizar.</p>
            </div>

            <div class="form-acciones">
              <button class="btn btn-primario" type="submit"><?= $editando ? 'Guardar cambios' : 'Crear cuenta' ?></button>
              <?php if ($editando): ?>
                <a class="btn btn-secundario" href="<?= h(urlPanel(['seccion' => 'cuentas'])) ?>">Cancelar</a>
              <?php endif; ?>
            </div>
          </form>
        </section>
      </div>

    <?php endif; ?>
    </div>
  </main>
</div>
</body>
<?php endif; ?>
</html>
