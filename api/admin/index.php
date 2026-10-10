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
// Solo recursos propios: aunque se colara HTML en algún dato, no podría cargar
// scripts de fuera ni enviar formularios a otro sitio. Los estilos en línea
// se permiten porque el gráfico pasa sus alturas como variables CSS.
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
     . "img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; "
     . "base-uri 'self'; object-src 'none'");
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../esquema.php';
require_once __DIR__ . '/../rate_limit.php';
require_once __DIR__ . '/../personas/validacion.php';
require_once __DIR__ . '/consultas.php';
require_once __DIR__ . '/vista.php';
$pdo = conectarBD();
asegurarCatalogoMunicipios($pdo);

require_once __DIR__ . '/../politica.php';
require_once __DIR__ . '/../correo.php';

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

/**
 * Deja constancia de una acción del administrador que está dentro.
 *
 * @param array<string, mixed>|null $detalle
 */
function auditar(PDO $pdo, string $accion, string $objeto, ?array $detalle = null): void
{
    $id = $_SESSION['admin_id'] ?? null;
    registrarAuditoria($pdo, $id === null ? null : (int)$id, (string)($_SESSION['admin_nombre'] ?? '?'), $accion, $objeto, $detalle);
}

/** 'YYYY-MM-DD' de un campo de fecha → medianoche UTC en ms (como la guardan los celulares). */
function fechaFormularioAMs(string $valor): ?int
{
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor, new DateTimeZone('UTC'));
    return $fecha === false ? null : $fecha->getTimestamp() * 1000;
}

/**
 * Lee los filtros de la lista de personas desde la URL.
 *
 * Las fechas se interpretan en hora de Colombia: "hasta el 8" incluye todo el
 * día 8, hasta la medianoche local.
 *
 * @return array{departamento?: string, municipio?: string, encuestador?: int, desde?: int, hasta?: int}
 */
function filtrosDeLaUrl(DateTimeZone $zona): array
{
    $filtros = [];
    if (textoGet('municipio') !== '') {
        $filtros['municipio'] = mb_substr(textoGet('municipio'), 0, 10);
    }
    if (textoGet('departamento') !== '') {
        $filtros['departamento'] = mb_substr(textoGet('departamento'), 0, 100);
    }
    if (ctype_digit(textoGet('encuestador'))) {
        $filtros['encuestador'] = (int)textoGet('encuestador');
    }
    foreach (['desde' => '+0 days', 'hasta' => '+1 day'] as $clave => $desplazamiento) {
        $dia = DateTimeImmutable::createFromFormat('!Y-m-d', textoGet($clave), $zona);
        if ($dia !== false) {
            $filtros[$clave] = $dia->modify($desplazamiento)->getTimestamp() * 1000;
        }
    }
    return $filtros;
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
$errorPersona = null;   // Error del formulario de edición de una persona.
$formPersona = null;    // Lo enviado al editar una persona, para repintarlo tras un error.
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
                        auditar($pdo, 'entrar', 'panel');
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
        if (!empty($_SESSION['admin_ok'])) {
            auditar($pdo, 'salir', 'panel');
        }
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
        // Con filtros, `volver` trae la consulta completa de la lista. Solo se
        // aceptan las claves conocidas: nunca una URL arbitraria.
        parse_str(textoPost('volver'), $consultaVolver);
        if (($consultaVolver['seccion'] ?? '') === 'personas' || ($consultaVolver['seccion'] ?? '') === 'persona') {
            $permitidas = ['seccion', 'borradas', 'q', 'p', 'departamento', 'municipio', 'encuestador', 'desde', 'hasta', 'tipo', 'numero'];
            $volver = urlPanel(array_map('strval', array_filter(
                array_intersect_key($consultaVolver, array_flip($permitidas)),
                'is_string'
            )));
        }

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
                    auditar($pdo, 'borrar_persona', "persona:$tipo $numero", ['nombre' => $quien]);
                    avisar(
                        'ok',
                        "Se borró a $quien. Los celulares la retirarán en su próxima sincronización.",
                        ['tipo_documento' => $tipo, 'numero_documento' => $numero, 'nombre' => $nombre]
                    );
                } else {
                    avisar('info', 'Esa persona ya no estaba en la base de datos.');
                }
            } elseif (restaurarPersona($pdo, $tipo, $numero, $autor)) {
                auditar($pdo, 'restaurar_persona', "persona:$tipo $numero", ['nombre' => $quien]);
                avisar('ok', "Se restauró a $quien. Volverá a los celulares en su próxima sincronización.");
            } else {
                avisar('info', 'Esa persona no estaba borrada.');
            }
        } catch (PDOException $e) {
            error_log('[admin] ' . $action . ': ' . $e->getMessage());
            avisar('error', 'No se pudo completar la acción. Intenta de nuevo.');
        }
        redirigir($volver);
    } elseif ($action === 'editar_persona') {
        $tipo = textoPost('tipo_documento');
        $numero = textoPost('numero_documento');
        $fichaUrl = urlPanel(['seccion' => 'persona', 'tipo' => $tipo, 'numero' => $numero]);
        $fila = [
            'nombres' => textoPost('nombres'), 'apellidos' => textoPost('apellidos'),
            'fecha_nacimiento' => fechaFormularioAMs(textoPost('fecha_nacimiento')),
            'telefono' => textoPost('telefono'), 'email' => textoPost('email'), 'direccion' => textoPost('direccion'),
            'vereda' => textoPost('vereda'), 'eps' => textoPost('eps'), 'ocupacion' => textoPost('ocupacion'),
            'estrato' => textoPost('estrato') === '' ? null : textoPost('estrato'),
            'municipio_codigo' => textoPost('municipio_codigo'),
        ];
        $formPersona = $fila;

        $antes = buscarPersonaCompleta($pdo, $tipo, $numero);
        if ($antes === null) {
            avisar('error', 'Esa persona ya no existe.');
            redirigir(urlPanel(['seccion' => 'personas']));
        }
        try {
            // Las MISMAS reglas que aplica la sincronización (api/personas/validacion.php).
            $codigos = array_flip(array_map('strval', array_column(listarMunicipios($pdo), 'codigo')));
            $datos = [
                'nombres' => nombreValidado($fila, 'nombres'),
                'apellidos' => nombreValidado($fila, 'apellidos'),
                'fecha_nacimiento' => fechaNacimientoValidada($fila),
                'telefono' => telefonoValidado($fila),
                'email' => emailValidado($fila),
                'direccion' => textoLibreValidado($fila, 'direccion'),
                'vereda' => textoLibreValidado($fila, 'vereda'),
                'eps' => textoLibreValidado($fila, 'eps'),
                'ocupacion' => textoLibreValidado($fila, 'ocupacion'),
                'estrato' => estratoValidado($fila),
                'municipio_codigo' => municipioValidado($fila, $codigos),
            ];
            actualizarPersonaDesdePanel($pdo, $tipo, $numero, $datos, 'admin:' . (string)($_SESSION['admin_nombre'] ?? '?'));
            $cambios = [];
            foreach ($datos as $campo => $valor) {
                if ((string)($antes[$campo] ?? '') !== (string)($valor ?? '')) {
                    $cambios[$campo] = ['antes' => $antes[$campo] ?? null, 'despues' => $valor];
                }
            }
            auditar($pdo, 'editar_persona', "persona:$tipo $numero", ['cambios' => $cambios]);
            avisar('ok', 'Cambios guardados. Llegarán a los celulares en su próxima sincronización.');
            redirigir($fichaUrl);
        } catch (DatoInvalido $e) {
            $errorPersona = $e->getMessage();
        } catch (PDOException $e) {
            error_log('[admin] editar persona: ' . $e->getMessage());
            $errorPersona = 'No se pudo guardar. Intenta de nuevo.';
        }
    } elseif ($action === 'probar_correo') {
        // Comprueba la configuración SMTP enviándole un correo al propio administrador.
        $yo = isset($_SESSION['admin_id']) ? buscarCuentaPorId($pdo, (int)$_SESSION['admin_id']) : null;
        $destino = (string)($yo['email'] ?? '');
        if (!correoConfigurado()) {
            avisar('error', 'El correo no está configurado: faltan las variables SMTP_HOST, SMTP_USUARIO y SMTP_CLAVE. '
                          . 'La guía está en docs/DESPLIEGUE.md.');
        } elseif ($destino === '') {
            avisar('error', 'Tu cuenta no tiene correo. Agrégalo en tu cuenta (más abajo) y vuelve a probar.');
        } else {
            $texto = "Este es un correo de prueba del panel de ColOffline.\r\n\r\nSi lo recibes, la recuperación de contraseña por correo funciona.";
            $html = '<p style="font-family:Arial,sans-serif;font-size:15px">Este es un correo de prueba del panel de <strong>ColOffline</strong>.</p>'
                  . '<p style="font-family:Arial,sans-serif;font-size:15px">Si lo recibes, la recuperación de contraseña por correo funciona.</p>';
            $ok = enviarCorreo($destino, 'Prueba de correo de ColOffline', $texto, $html);
            auditar($pdo, 'probar_correo', 'panel', ['enviado' => $ok]);
            avisar($ok ? 'ok' : 'error', $ok
                ? "Correo de prueba enviado a $destino. Revisa la bandeja de entrada (y la de spam)."
                : 'No se pudo enviar el correo. Revisa SMTP_USUARIO y SMTP_CLAVE (la contraseña de aplicación de Google) y que el servidor pueda salir por el puerto ' . (getenv('SMTP_PUERTO') ?: '465') . '.');
        }
        redirigir(urlPanel(['seccion' => 'cuentas']));
    } elseif ($action === 'cerrar_sesiones' || $action === 'desbloquear_cuenta') {
        $id = textoPost('id');
        $cuenta = ctype_digit($id) ? buscarCuentaPorId($pdo, (int)$id) : null;
        if ($cuenta === null) {
            avisar('error', 'Esa cuenta ya no existe.');
            redirigir(urlPanel(['seccion' => 'cuentas']));
        }
        $documentoCuenta = (string)($cuenta['numero_documento'] ?? '');
        if ($action === 'cerrar_sesiones') {
            revocarSesionesApi($pdo, (int)$id);
            auditar($pdo, 'cerrar_sesiones', "cuenta:$documentoCuenta");
            avisar('ok', 'Se cerraron las sesiones de ' . (string)$cuenta['nombre'] . ' en los celulares. '
                       . 'Tendrá que volver a entrar con conexión; lo que tenga sin enviar no se pierde.');
        } else {
            limpiarIntentos($pdo, $documentoCuenta);
            auditar($pdo, 'desbloquear_cuenta', "cuenta:$documentoCuenta");
            avisar('ok', (string)$cuenta['nombre'] . ' ya puede volver a intentar entrar.');
        }
        redirigir(urlPanel(['seccion' => 'cuentas', 'editar' => $id]) . '#form-cuenta');
    } elseif ($action === 'save') {
        $id = textoPost('id');
        $nombre = textoPost('nombre');
        $documento = textoPost('numero_documento');
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $activo = isset($_POST['activo']) ? 1 : 0;
        $rol = textoPost('rol') === 'admin' ? 'admin' : 'encuestador';
        // Correo para «¿Olvidaste tu contraseña?». Opcional.
        $email = mb_strtolower(textoPost('email'));
        // Municipios que descargará esta cuenta. Ninguno = todos.
        $municipiosSel = array_values(array_unique(array_filter(
            array_map(fn ($c) => is_string($c) ? trim($c) : '', is_array($_POST['municipios'] ?? null) ? $_POST['municipios'] : []),
            fn ($c) => $c !== ''
        )));
        $formCuenta = ['id' => $id, 'nombre' => $nombre, 'numero_documento' => $documento, 'email' => $email, 'rol' => $rol, 'activo' => $activo, 'municipios' => $municipiosSel];
        $codigosValidos = array_flip(array_map('strval', array_column(listarMunicipios($pdo), 'codigo')));

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
        } elseif ($email !== '' && (mb_strlen($email) > 100 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errorCuenta = 'El correo no es válido. Revísalo o déjalo vacío.';
        } elseif (array_diff($municipiosSel, array_keys($codigosValidos)) !== []) {
            $errorCuenta = 'Hay municipios que no existen en la lista.';
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

                $idGuardado = $cuentaActual === null ? (int)$pdo->lastInsertId() : (int)$id;
                guardarMunicipiosAsignados($pdo, $idGuardado, $municipiosSel);
                asegurarRecuperacion($pdo);
                $pdo->prepare('UPDATE encuestadores SET email = ? WHERE id = ?')
                    ->execute([$email !== '' ? $email : null, $idGuardado]);

                // Nunca la contraseña: solo si cambió.
                auditar($pdo, $cuentaActual === null ? 'crear_cuenta' : 'editar_cuenta', "cuenta:$documento", [
                    'nombre' => $nombre, 'rol' => $rol, 'activo' => $activo, 'cambio_contrasena' => $nuevoHash !== null,
                    'con_correo' => $email !== '',
                    'municipios' => count($municipiosSel),
                    'antes' => $cuentaActual === null ? null : [
                        'nombre' => $cuentaActual['nombre'], 'rol' => $cuentaActual['rol'], 'activo' => (int)$cuentaActual['activo'],
                    ],
                ]);

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

$busqueda = mb_substr(textoGet('q'), 0, 100);
$filtros = filtrosDeLaUrl($zona);

// --- Exportación a CSV -------------------------------------------------------
// Va antes de emitir HTML: una vez enviado el cuerpo ya no se pueden cambiar
// las cabeceras.
if ($loggedIn && textoGet('exportar') === 'personas') {
    // Lo mismo que muestra la tabla: búsqueda y filtros incluidos.
    $filas = personasParaExportar($pdo, $busqueda, $filtros);
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
if (!in_array($seccion, ['resumen', 'personas', 'persona', 'cuentas', 'sincronizacion', 'auditoria'], true)) {
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
            'email'            => (string)($cuentaEditada['email'] ?? ''),
            'rol'              => (string)$cuentaEditada['rol'],
            'activo'           => (int)$cuentaEditada['activo'],
            'municipios'       => municipiosAsignados($pdo, (int)$cuentaEditada['id']),
        ];
    }
}
if ($errorCuenta !== null) {
    $seccion = 'cuentas';
}
// En modo arranque lo único que tiene sentido crear es el primer administrador.
$formCuenta ??= ['id' => '', 'nombre' => '', 'numero_documento' => '', 'email' => '', 'rol' => $modoArranque ? 'admin' : 'encuestador', 'activo' => 1, 'municipios' => []];
$editando = $formCuenta['id'] !== '';

$resumen = ['personas' => 0, 'borradas' => 0, 'encuestas' => 0, 'encuestadores' => 0, 'cuentas' => 0, 'dispositivos' => 0, 'ultima_sync' => null];
$porDia = [];
$porMunicipio = [];
$porEncuestador = [];
$cuentas = [];

$verBorradas = textoGet('borradas') === '1';
$pagina = max(1, (int)textoGet('p'));
$personas = [];
$totalActivas = 0;
$totalBorradas = 0;
$totalPersonas = 0;
$totalPaginas = 1;

$monitor = ['rechazos_7_dias' => 0, 'dispositivos_inactivos' => 0, 'dispositivos' => 0];
$municipios = [];
$encuestadoresFiltro = [];
$personaFicha = null;
$historial = [];
$auditoriaPersona = [];
$sesionesPorCuenta = [];
$asignacionesPorCuenta = [];
$sesionesCuenta = [];
$bloqueoCuenta = 0;
$dispositivos = [];
$rechazos = [];
$registrosAuditoria = [];
$totalAuditoria = 0;
$hayFiltros = $filtros !== [];

if ($loggedIn) {
    if ($seccion === 'resumen') {
        $resumen        = resumenGeneral($pdo);
        $porDia         = encuestasPorDia($pdo, $zona, 14);
        $porMunicipio   = personasPorMunicipio($pdo);
        $porEncuestador = encuestasPorEncuestador($pdo);
        $monitor        = resumenMonitor($pdo);
    } elseif ($seccion === 'personas') {
        $municipios = listarMunicipios($pdo);
        $encuestadoresFiltro = encuestadoresParaFiltro($pdo);
        $totalActivas  = contarPersonas($pdo);
        $totalBorradas = contarPersonas($pdo, '', true);
        $totalPersonas = $busqueda === '' && !$hayFiltros
            ? ($verBorradas ? $totalBorradas : $totalActivas)
            : contarPersonas($pdo, $busqueda, $verBorradas, $filtros);
        $totalPaginas = max(1, (int)ceil($totalPersonas / PERSONAS_POR_PAGINA));
        // Una página fuera de rango (?p=999, o la última tras borrar su única
        // fila) decía "todavía no se ha sincronizado ninguna persona" y
        // escondía la paginación. Se lleva a la última que existe.
        $pagina = min($pagina, $totalPaginas);
        $personas = consultarPersonas($pdo, $busqueda, PERSONAS_POR_PAGINA, ($pagina - 1) * PERSONAS_POR_PAGINA, $verBorradas, $filtros);
    } elseif ($seccion === 'persona') {
        $tipoFicha = textoGet('tipo') !== '' ? textoGet('tipo') : textoPost('tipo_documento');
        $numeroFicha = textoGet('numero') !== '' ? textoGet('numero') : textoPost('numero_documento');
        $personaFicha = buscarPersonaCompleta($pdo, $tipoFicha, $numeroFicha);
        if ($personaFicha === null) {
            avisar('error', 'Esa persona no existe.');
            redirigir(urlPanel(['seccion' => 'personas']));
        }
        $historial = encuestasDePersona($pdo, $tipoFicha, $numeroFicha);
        $auditoriaPersona = consultarAuditoria($pdo, 20, 0, "persona:$tipoFicha $numeroFicha");
        $municipios = listarMunicipios($pdo);
    } elseif ($seccion === 'sincronizacion') {
        $monitor = resumenMonitor($pdo);
        $dispositivos = consultarDispositivos($pdo);
        $rechazos = rechazosRecientes($pdo, 50);
    } elseif ($seccion === 'auditoria') {
        $totalAuditoria = contarAuditoria($pdo);
        $totalPaginas = max(1, (int)ceil($totalAuditoria / 50));
        $pagina = min($pagina, $totalPaginas);
        $registrosAuditoria = consultarAuditoria($pdo, 50, ($pagina - 1) * 50);
    } else {
        $cuentas = consultarEncuestadores($pdo);
        $sesionesPorCuenta = contarSesionesPorCuenta($pdo);
        $asignacionesPorCuenta = contarAsignacionesPorCuenta($pdo);
        $municipios = listarMunicipios($pdo);
        if ($editando) {
            $sesionesCuenta = sesionesDeCuenta($pdo, (int)$formCuenta['id']);
            if ($formCuenta['numero_documento'] !== '' && preg_match(PATRON_DOCUMENTO_CUENTA, $formCuenta['numero_documento'])) {
                $bloqueoCuenta = segundosDeBloqueo($pdo, $formCuenta['numero_documento']);
            }
        }
    }
}

// Estado completo de la lista de personas (búsqueda, filtros, página), para
// enlaces y para volver a ella tras una acción.
$parametrosLista = array_filter([
    'seccion' => 'personas',
    'borradas' => $verBorradas ? '1' : null,
    'q' => $busqueda,
    'departamento' => textoGet('departamento'),
    'municipio' => textoGet('municipio'),
    'encuestador' => textoGet('encuestador'),
    'desde' => textoGet('desde'),
    'hasta' => textoGet('hasta'),
], fn ($v) => $v !== null && $v !== '');

$titulos = [
    'resumen' => 'Resumen', 'personas' => 'Personas', 'cuentas' => 'Cuentas',
    'sincronizacion' => 'Sincronización', 'auditoria' => 'Auditoría', 'persona' => 'Ficha de persona',
];
// La ficha no es una sección del menú: se llega desde la lista.
$navegacion = array_diff_key($titulos, ['persona' => true]);
$descripciones = [
    'resumen'  => 'Cómo va la recolección en campo.',
    'personas' => 'Personas registradas por los encuestadores, tal como llegan a todos los celulares.',
    'persona'  => 'Todos los datos de la persona, su historial de encuestas y los cambios hechos desde el panel.',
    'cuentas'  => 'Encuestadores de campo y administradores de este panel.',
    'sincronizacion' => 'Celulares conectados, registros rechazados y equipos que llevan días sin enviar.',
    'auditoria' => 'Todo lo que hacen los administradores en este panel.',
];
$csrf = (string)$_SESSION['csrf'];
$idAdmin = $_SESSION['admin_id'] ?? null;
$version = fn (string $archivo): int => (int)(filemtime(__DIR__ . '/' . $archivo) ?: 0);

// Estado de la lista de personas, para volver a ella tras borrar o restaurar.
$camposVolver = '<input type="hidden" name="volver" value="' . h(http_build_query($parametrosLista + ($pagina > 1 ? ['p' => $pagina] : []))) . '">'
              . '<input type="hidden" name="volver_q" value="' . h($busqueda) . '">'
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
<main class="acceso">
  <section class="acceso-portada">
    <svg class="acceso-curvas" viewBox="0 0 600 400" fill="none" stroke="#E7A07B" stroke-width="1.6" aria-hidden="true" focusable="false">
      <path d="M-20 360 C90 250 170 300 260 220 S420 120 620 170"/>
      <path d="M-20 390 C100 290 190 336 282 258 S440 162 620 205"/>
      <path d="M-20 420 C110 330 210 372 304 296 S460 204 620 240"/>
      <path d="M-20 450 C120 370 230 408 326 334 S480 246 620 276"/>
    </svg>
    <div class="marca marca-clara">
      <img src="../../pwa/icons/icon.svg" alt="" width="40" height="40">
      <div>
        <p class="marca-nombre">ColOffline</p>
        <p class="marca-sub">Encuestas demográficas sin conexión</p>
      </div>
    </div>
    <div class="acceso-mensaje">
      <p class="acceso-titular">Lo que se registra en campo, en un solo lugar.</p>
      <ul class="acceso-puntos">
        <li><?= icono('resumen', 20) ?><span>Avance diario por municipio y por encuestador.</span></li>
        <li><?= icono('sincronizacion', 20) ?><span>Celulares sin enviar y registros rechazados con su motivo.</span></li>
        <li><?= icono('cuentas', 20) ?><span>Cuentas, roles y municipios de cada encuestador.</span></li>
      </ul>
    </div>
    <p class="acceso-pie">Acceso restringido a administradores. Cada acción queda en la auditoría. · <a href="../../pwa/privacidad.html">Privacidad</a></p>
  </section>

  <section class="acceso-lado">
  <div class="tarjeta acceso-tarjeta" role="region" aria-labelledby="titulo-acceso">
    <div class="titulo">
      <span class="acceso-candado"><?= icono('cuentas', 20) ?></span>
      <h1 id="titulo-acceso">Panel de administración</h1>
      <p><?= $modoArranque ? 'Configuración inicial del panel.' : 'Entra con tu cuenta de administrador.' ?></p>
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

      <button class="btn btn-primario btn-bloque btn-grande" type="submit">Ingresar</button>
    </form>
    <?php if (!$modoArranque): ?>
      <a class="acceso-olvido" href="../../pwa/index.html#/recuperar">¿Olvidaste tu contraseña?</a>
    <?php endif; ?>
  </div>

  <a class="acceso-volver" href="../../pwa/"><?= icono('volver', 16) ?>Volver a la app de encuestas</a>
  </section>
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
      <?php foreach ($navegacion as $clave => $titulo): ?>
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
          <a class="btn btn-secundario" href="<?= h(urlPanel(['exportar' => 'personas'] + array_diff_key($parametrosLista, ['seccion' => 1, 'borradas' => 1]))) ?>">
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

    <?php require __DIR__ . '/vistas/' . $seccion . '.php'; ?>
    </div>
  </main>
</div>
</body>
<?php endif; ?>
</html>
