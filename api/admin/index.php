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

require_once '../cors.php';
aplicarCabecerasDeSeguridad();
require_once '../db.php';
require_once __DIR__ . '/consultas.php';
require_once __DIR__ . '/../esquema.php';
require_once __DIR__ . '/../rate_limit.php';
$pdo = conectarBD();

/** Longitud mínima al crear o cambiar la contraseña de un encuestador. */
const MIN_LONGITUD_PASSWORD = 10;

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

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '');
    $action = $_POST['action'] ?? '';

    if (!$csrfOk) {
        $error = 'Sesión expirada, intenta de nuevo.';
    } elseif ($action === 'login') {
        $password = (string)($_POST['password'] ?? '');
        $documento = trim($_POST['numero_documento'] ?? '');

        // La clave del contador anti fuerza bruta es el documento cuando se
        // entra con cuenta, y la clave interna del panel en modo arranque,
        // donde no hay documento que usar.
        $claveIntentos = $modoArranque ? CLAVE_PANEL_ADMIN : $documento;

        if (!$modoArranque && $documento === '') {
            $error = 'Escribe tu número de documento';
        } elseif ($claveIntentos !== '' && ($bloqueo = segundosDeBloqueo($pdo, $claveIntentos)) > 0) {
            $minutos = (int)ceil($bloqueo / 60);
            $error = "Demasiados intentos fallidos. Espera $minutos minuto(s).";
        } else {
            // En modo arranque vale la contraseña compartida; con cuentas
            // creadas, solo documento + contraseña de un administrador activo.
            $cuenta = null;
            $entra = false;

            if ($modoArranque) {
                $entra = hash_equals($adminPassword, $password);
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
                $_SESSION['admin_id'] = $cuenta['id'] ?? null;
                $_SESSION['admin_nombre'] = $cuenta['nombre'] ?? 'Administrador inicial';
                // Entrar bien borra el historial: al administrador legítimo no
                // le debe quedar deuda por unos tecleos mal puestos de ayer.
                limpiarIntentos($pdo, $claveIntentos);
            } else {
                if ($claveIntentos !== '') {
                    registrarIntentoFallido($pdo, $claveIntentos);
                }
                error_log('[admin] intento de acceso fallido al panel');
                // Mensaje único: no revela si el documento corresponde a un
                // administrador o si lo que falló fue la contraseña.
                $error = $modoArranque ? 'Contraseña incorrecta' : 'Credenciales incorrectas';
            }
        }
    } elseif ($action === 'logout') {
        // Se destruye la sesión entera, no solo la marca de autenticado.
        $_SESSION = [];
        session_regenerate_id(true);
        // El token CSRF se inicializa más arriba, antes de procesar el POST:
        // si no se repone aquí, el formulario de login quedaría sin token y
        // el siguiente envío sería rechazado.
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    } elseif ($action === 'borrar_persona' && !empty($_SESSION['admin_ok'])) {
        // Existe para poder retirar registros que ningún cliente puede tocar:
        // los que se crearon antes de que el servidor validara el documento y
        // que ahora la propia validación impide reenviar. Sin esto no hay
        // forma de quitarlos del sistema.
        $tipo = trim($_POST['tipo_documento'] ?? '');
        $numero = trim($_POST['numero_documento'] ?? '');

        if ($tipo === '' || $numero === '') {
            $error = 'Falta el documento de la persona a borrar';
        } else {
            try {
                // Queda registrado QUIÉN borró, no solo que fue "el panel".
                // Era imposible antes, cuando la contraseña era compartida.
                $autor = 'admin:' . ($_SESSION['admin_nombre'] ?? '?');
                $ok = borrarPersona($pdo, $tipo, $numero, $autor) ? '1' : '0';
                header('Location: index.php?seccion=personas&borrada=' . $ok);
                exit;
            } catch (PDOException $e) {
                error_log('[admin] borrar persona: ' . $e->getMessage());
                $error = 'No se pudo borrar. Intenta de nuevo.';
            }
        }
    } elseif ($action === 'restaurar_persona' && !empty($_SESSION['admin_ok'])) {
        $tipo = trim($_POST['tipo_documento'] ?? '');
        $numero = trim($_POST['numero_documento'] ?? '');

        if ($tipo === '' || $numero === '') {
            $error = 'Falta el documento de la persona a restaurar';
        } else {
            try {
                $autor = 'admin:' . ($_SESSION['admin_nombre'] ?? '?');
                $ok = restaurarPersona($pdo, $tipo, $numero, $autor) ? '1' : '0';
                header('Location: index.php?seccion=personas&borradas=1&restaurada=' . $ok);
                exit;
            } catch (PDOException $e) {
                error_log('[admin] restaurar persona: ' . $e->getMessage());
                $error = 'No se pudo restaurar. Intenta de nuevo.';
            }
        }
    } elseif ($action === 'save' && !empty($_SESSION['admin_ok'])) {
        $id = trim($_POST['id'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $documento = trim($_POST['numero_documento'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $activo = isset($_POST['activo']) ? 1 : 0;
        $rol = ($_POST['rol'] ?? '') === 'admin' ? 'admin' : 'encuestador';

        // Deja de ser administrador activo tras este guardado, sea porque se
        // le cambia el rol o porque se le desactiva.
        $dejaDeSerAdmin = $id !== '' && ($rol !== 'admin' || $activo === 0);

        if ($nombre === '' || $documento === '') {
            $error = 'Nombre y número de documento son obligatorios';
        } elseif ($id === '' && $password === '') {
            $error = 'La contraseña es obligatoria para cuentas nuevas';
        } elseif ($password !== '' && mb_strlen($password) < MIN_LONGITUD_PASSWORD) {
            // Solo se valida al fijar o cambiar la contraseña: las cuentas
            // existentes no quedan bloqueadas por una regla nueva.
            $error = 'La contraseña debe tener al menos ' . MIN_LONGITUD_PASSWORD . ' caracteres';
        } elseif ($dejaDeSerAdmin && esUltimoAdminActivo($pdo, (int)$id)) {
            // Sin esto se puede uno dejar fuera del panel con dos clics, y
            // recuperarlo exigiría entrar a la base de datos por SSH.
            $error = 'No puedes quitar el rol de administrador ni desactivar la única cuenta '
                   . 'de administrador que queda. Crea otra antes.';
        } else {
            try {
                asegurarRolEncuestador($pdo);

                if ($id !== '') {
                    if ($password !== '') {
                        $stmt = $pdo->prepare("UPDATE encuestadores SET nombre = ?, numero_documento = ?, password_hash = ?, activo = ?, rol = ? WHERE id = ?");
                        $stmt->execute([$nombre, $documento, password_hash($password, PASSWORD_BCRYPT), $activo, $rol, $id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE encuestadores SET nombre = ?, numero_documento = ?, activo = ?, rol = ? WHERE id = ?");
                        $stmt->execute([$nombre, $documento, $activo, $rol, $id]);
                    }
                } else {
                    $stmt = $pdo->prepare("INSERT INTO encuestadores (nombre, numero_documento, password_hash, activo, rol) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$nombre, $documento, password_hash($password, PASSWORD_BCRYPT), $activo, $rol]);
                }

                // Si se acaba de crear el primer administrador estando en modo
                // arranque, la sesión sigue siendo la de la contraseña
                // compartida. Se cierra para obligar a entrar con la cuenta
                // nueva: si no, ADMIN_PASSWORD seguiría dando acceso durante
                // toda esta sesión pese a haber dejado de ser válida.
                if ($modoArranque && $rol === 'admin' && $activo === 1) {
                    $_SESSION = [];
                    session_regenerate_id(true);
                    $_SESSION['csrf'] = bin2hex(random_bytes(16));
                    header('Location: index.php?primer_admin=1');
                    exit;
                }

                header('Location: index.php?seccion=encuestadores');
                exit;
            } catch (PDOException $e) {
                error_log('[admin] ' . $e->getMessage());
                $error = ($e->getCode() === '23000')
                    ? 'Ese número de documento ya está registrado'
                    : 'Error al guardar. Intenta de nuevo.';
            }
        }
    }
}

$loggedIn = !empty($_SESSION['admin_ok']);

// --- Exportación a CSV -------------------------------------------------------
// Va antes de emitir HTML: una vez enviado el cuerpo ya no se pueden cambiar
// las cabeceras.
if ($loggedIn && ($_GET['exportar'] ?? '') === 'personas') {
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
            // Las fechas se guardan en milisegundos; en el CSV van legibles.
            foreach (['fecha_nacimiento', 'updated_at'] as $campo) {
                if (!empty($f[$campo])) {
                    $f[$campo] = date('Y-m-d', (int)$f[$campo] / 1000);
                }
            }
            fputcsv($salida, $f, ';');
        }
    }
    exit;
}

// --- Datos de la vista -------------------------------------------------------
$seccion = $_GET['seccion'] ?? 'resumen';
if (!in_array($seccion, ['resumen', 'personas', 'encuestadores'], true)) {
    $seccion = 'resumen';
}

$editRow = null;
if ($loggedIn && isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT id, nombre, numero_documento, activo, rol FROM encuestadores WHERE id = ?');
    $stmt->execute([$_GET['edit']]);
    $editRow = $stmt->fetch() ?: null;
    $seccion = 'encuestadores';
}

$encuestadores = $loggedIn ? consultarEncuestadores($pdo) : [];

$resumen = [];
$porMunicipio = [];
$porDia = [];
$porEncuestador = [];
$personas = [];
$totalPersonas = 0;
$busqueda = trim((string)($_GET['q'] ?? ''));
$pagina = max(1, (int)($_GET['p'] ?? 1));
$porPagina = 25;

// La papelera es una vista aparte de la misma sección, no una pestaña propia:
// mirar lo borrado es una comprobación puntual, no un sitio donde se trabaja.
$verBorradas = ($_GET['borradas'] ?? '') === '1';

/** Cuántas hay en la papelera, para no ofrecerla vacía. */
$totalBorradas = $loggedIn && $seccion === 'personas' ? contarPersonas($pdo, '', true) : 0;

if ($loggedIn) {
    if ($seccion === 'resumen') {
        $resumen        = resumenGeneral($pdo);
        $porMunicipio   = personasPorMunicipio($pdo);
        $porDia         = encuestasPorDia($pdo);
        $porEncuestador = encuestasPorEncuestador($pdo);
    } elseif ($seccion === 'personas') {
        $totalPersonas = contarPersonas($pdo, $busqueda, $verBorradas);
        $personas      = consultarPersonas($pdo, $busqueda, $porPagina, ($pagina - 1) * $porPagina, $verBorradas);
    }
}

$totalPaginas = max(1, (int)ceil($totalPersonas / $porPagina));

/** Escapa un valor para insertarlo en HTML. */
function h(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

/** Fecha legible a partir de milisegundos. */
function fecha(mixed $ms): string
{
    return empty($ms) ? '—' : date('d/m/Y H:i', (int)((int)$ms / 1000));
}

/** Etiqueta corta (dd/mm) para el eje del gráfico, a partir de 'YYYY-MM-DD'. */
function etiquetaDia(string $dia): string
{
    $ts = strtotime($dia);
    // strtotime devuelve false ante una fecha que no reconoce; en ese caso se
    // muestra el valor crudo antes que romper la página entera.
    return $ts === false ? $dia : date('d/m', $ts);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Admin · ColOffline</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --primary: #12467E;
    --primary-gradient: linear-gradient(135deg, #12467E 0%, #0D325C 100%);
    --primary-dark: #0C325C;
    --primary-tint: #EEF4FA;
    --primary-glow: rgba(18, 70, 126, 0.15);
    --surface: #FFFFFF;
    --surface-alt: #F8FAFC;
    --bg: #F1F5F9;
    --texto: #0F172A;
    --texto-2: #475569;
    --texto-3: #94A3B8;
    --divisor: #E2E8F0;
    --borde: #CBD5E1;
    --ok: #15803D;
    --ok-bg: #F0FDF4;
    --ok-border: #BBF7D0;
    --error: #B91C1C;
    --error-bg: #FEF2F2;
    --error-border: #FECACA;
    --radio: 12px;
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
    --shadow-md: 0 10px 15px -3px rgba(15, 23, 42, 0.08), 0 4px 6px -4px rgba(15, 23, 42, 0.04);
  }
  * { box-sizing: border-box; }
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--bg);
    color: var(--texto);
    margin: 0;
    -webkit-font-smoothing: antialiased;
    line-height: 1.5;
  }
  .barra { height: 4px; background: var(--primary-gradient); }
  .wrap { max-width: 1120px; margin: 0 auto; padding: 28px 20px 56px; }

  header.top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 24px;
    flex-wrap: wrap;
    background: var(--surface);
    padding: 16px 20px;
    border-radius: var(--radio);
    border: 1px solid var(--divisor);
    box-shadow: var(--shadow-sm);
  }
  .marca { display: flex; align-items: center; gap: 12px; }
  .logo {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    background: var(--primary-gradient);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    box-shadow: 0 4px 10px rgba(18, 70, 126, 0.25);
  }
  h1 { font-size: 1.25rem; font-weight: 700; margin: 0; color: var(--texto); letter-spacing: -0.02em; }
  .sub { font-size: .8rem; color: var(--texto-2); margin: 2px 0 0; }
  .sub strong { color: var(--primary); font-weight: 600; }

  nav.tabs {
    display: flex;
    gap: 6px;
    background: var(--surface);
    padding: 6px;
    border-radius: 12px;
    border: 1px solid var(--divisor);
    margin-bottom: 24px;
    box-shadow: var(--shadow-sm);
  }
  nav.tabs a {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    font-size: .88rem;
    font-weight: 500;
    color: var(--texto-2);
    text-decoration: none;
    border-radius: 8px;
    transition: all 0.2s ease;
  }
  nav.tabs a:hover { color: var(--primary); background: var(--primary-tint); }
  nav.tabs a.on {
    color: #fff;
    background: var(--primary-gradient);
    font-weight: 600;
    box-shadow: 0 2px 8px rgba(18, 70, 126, 0.25);
  }

  .tarjetas {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
  }
  .kpi {
    background: var(--surface);
    border: 1px solid var(--divisor);
    border-radius: var(--radio);
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: var(--shadow-sm);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
  }
  .kpi:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
  }
  .kpi-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }
  .icon-personas { background: #EEF4FA; color: #12467E; }
  .icon-encuestas { background: #F0FDF4; color: #15803D; }
  .icon-encuestadores { background: #FEF3C7; color: #B45309; }
  .icon-dispositivos { background: #F3E8FF; color: #6B21A8; }
  .kpi .n { font-size: 1.85rem; font-weight: 700; line-height: 1.1; color: var(--texto); letter-spacing: -0.03em; }
  .kpi .t { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--texto-2); margin-top: 4px; }

  .panel {
    background: var(--surface);
    border: 1px solid var(--divisor);
    border-radius: var(--radio);
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: var(--shadow-sm);
  }
  .panel h2 {
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--texto);
    margin: 0 0 16px;
    letter-spacing: -0.01em;
  }
  .dos { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 24px; }

  .grafico { display: flex; align-items: flex-end; gap: 8px; height: 160px; padding-top: 12px; }
  .barra-col { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 6px; height: 100%; }
  .barra-val {
    width: 100%;
    background: var(--primary-gradient);
    border-radius: 6px 6px 0 0;
    min-height: 4px;
    transition: transform 0.2s ease, opacity 0.2s ease;
  }
  .barra-col:hover .barra-val { opacity: 0.85; transform: scaleY(1.03); }
  .barra-eti { font-size: .68rem; font-weight: 500; color: var(--texto-3); white-space: nowrap; }
  .barra-num { font-size: .72rem; font-weight: 700; color: var(--primary); }

  .lista-barras { display: flex; flex-direction: column; gap: 14px; }
  .fila-barra { display: grid; grid-template-columns: 1fr auto; gap: 6px; align-items: center; }
  .fila-barra .n { font-size: .85rem; font-weight: 500; }
  .fila-barra .v { font-size: .85rem; font-weight: 700; color: var(--primary); }
  .pista { grid-column: 1 / -1; height: 8px; background: #F1F5F9; border-radius: 99px; overflow: hidden; margin-top: 2px; }
  .relleno { height: 100%; background: var(--primary-gradient); border-radius: 99px; }

  table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: .88rem; }
  th {
    text-align: left;
    font-size: .72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--texto-2);
    background: var(--surface-alt);
    border-bottom: 1px solid var(--divisor);
    padding: 10px 14px;
    white-space: nowrap;
  }
  th:first-child { border-top-left-radius: 8px; }
  th:last-child { border-top-right-radius: 8px; }
  td { padding: 12px 14px; border-bottom: 1px solid var(--divisor); vertical-align: middle; }
  tr:last-child td { border-bottom: none; }
  tbody tr { transition: background 0.15s ease; }
  tbody tr:hover { background: var(--primary-tint); }
  .vacio { text-align: center; color: var(--texto-2); padding: 40px 16px; font-size: .9rem; }

  .badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: .72rem;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 99px;
    text-transform: uppercase;
    letter-spacing: .03em;
  }
  .badge.si { background: var(--ok-bg); color: var(--ok); border: 1px solid var(--ok-border); }
  .badge.no { background: var(--error-bg); color: var(--error); border: 1px solid var(--error-border); }

  input[type=text], input[type=password], input[type=search], select {
    width: 100%;
    padding: 10px 14px;
    font-size: .9rem;
    font-family: inherit;
    color: var(--texto);
    border: 1px solid var(--borde);
    border-radius: 8px;
    outline: none;
    background: var(--surface);
    transition: all 0.2s ease;
  }
  input:focus, select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-glow);
  }
  label.campo { display: block; font-size: .78rem; font-weight: 600; color: var(--texto-2); margin: 0 0 6px; }
  .fila-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px; }

  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 18px;
    border: none;
    border-radius: 8px;
    background: var(--primary-gradient);
    color: #fff;
    font-size: .88rem;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s ease;
    box-shadow: 0 2px 4px rgba(18, 70, 126, 0.15);
  }
  .btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(18, 70, 126, 0.25);
  }
  .btn.sec {
    background: var(--surface);
    color: var(--primary);
    border: 1px solid var(--borde);
    box-shadow: var(--shadow-sm);
  }
  .btn.sec:hover {
    background: var(--primary-tint);
    border-color: var(--primary);
  }
  .btn.peligro {
    background: transparent;
    color: var(--texto-2);
    border: 1px solid var(--borde);
    padding: 6px 12px;
    font-size: .82rem;
  }
  .btn.peligro:hover {
    background: var(--error-bg);
    color: var(--error);
    border-color: var(--error-border);
  }

  .aviso {
    padding: 14px 16px;
    border-radius: 10px;
    font-size: .88rem;
    line-height: 1.5;
    margin-bottom: 20px;
    background: var(--error-bg);
    color: var(--error);
    border: 1px solid var(--error-border);
  }
  .aviso.ok { background: var(--ok-bg); color: var(--ok); border-color: var(--ok-border); }
  .buscador { display: flex; gap: 10px; margin-bottom: 18px; flex-wrap: wrap; }
  .buscador input { flex: 1; min-width: 220px; }
  .paginacion { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-top: 18px; flex-wrap: wrap; }
  .paginacion .info { font-size: .82rem; color: var(--texto-2); }

  .login-caja {
    max-width: 400px;
    margin: 60px auto;
  }
  .login-caja .panel {
    box-shadow: var(--shadow-md);
    border-radius: 16px;
    padding: 32px 28px;
  }
</style>
</head>
<body>
<div class="barra"></div>
<div class="wrap">

<?php if (!$loggedIn): ?>

  <div class="login-caja">
    <div class="marca" style="justify-content:center;margin-bottom:20px">
      <div class="logo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
      </div>
      <div>
        <h1>Admin · ColOffline</h1>
        <p class="sub">Ministerio de Salud</p>
      </div>
    </div>
    <div class="panel">
      <?php if ($error): ?><div class="aviso"><?= h($error) ?></div><?php endif; ?>

      <?php if (isset($_GET['primer_admin'])): ?>
        <div class="aviso ok">
          Cuenta de administrador creada. Entra con su documento y contraseña.
          La contraseña compartida ya no sirve, y puedes borrar
          <strong>ADMIN_PASSWORD</strong> del entorno.
        </div>
      <?php endif; ?>

      <?php if ($modoArranque): ?>
        <div class="aviso">
          No hay ninguna cuenta de administrador. Entra con la contraseña
          compartida y crea la primera desde <strong>Encuestadores</strong>.
          Después dejará de aceptarse.
        </div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="login">

        <?php if (!$modoArranque): ?>
          <label class="campo" for="doc">Número de documento</label>
          <input type="text" id="doc" name="numero_documento" autocomplete="username" autofocus>
          <div style="height:14px"></div>
        <?php endif; ?>

        <label class="campo" for="pw">Contraseña<?= $modoArranque ? ' de administrador' : '' ?></label>
        <input type="password" id="pw" name="password" autocomplete="current-password"
               <?= $modoArranque ? 'autofocus' : '' ?>>
        <button class="btn" type="submit" style="width:100%;justify-content:center;margin-top:16px">Ingresar</button>
      </form>
    </div>
  </div>

<?php else: ?>

  <header class="top">
    <div class="marca">
      <div class="logo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
      </div>
      <div>
        <h1>Admin · ColOffline</h1>
        <p class="sub">
          Ministerio de Salud ·
          <?php // Quién está dentro. ?>
          <strong><?= h($_SESSION['admin_nombre'] ?? 'Administrador') ?></strong>
        </p>
      </div>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="logout">
      <button class="btn sec" type="submit">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        <span>Salir</span>
      </button>
    </form>
  </header>

  <nav class="tabs">
    <a href="?seccion=resumen" class="<?= $seccion === 'resumen' ? 'on' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      <span>Resumen</span>
    </a>
    <a href="?seccion=personas" class="<?= $seccion === 'personas' ? 'on' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
      <span>Personas</span>
    </a>
    <a href="?seccion=encuestadores" class="<?= $seccion === 'encuestadores' ? 'on' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
      <span>Cuentas</span>
    </a>
  </nav>

  <?php if ($error): ?><div class="aviso"><?= h($error) ?></div><?php endif; ?>

  <?php if (isset($_GET['borrada'])): ?>
    <div class="aviso <?= $_GET['borrada'] === '1' ? 'ok' : '' ?>">
      <?php if ($_GET['borrada'] === '1'): ?>
        Persona borrada. Los celulares la retirarán en su próxima sincronización;
        los que tengan cambios sin enviar, cuando los suban.
        Puedes deshacerlo desde <a href="?seccion=personas&amp;borradas=1">Ver borradas</a>.
      <?php else: ?>
        Esa persona ya no estaba en la base de datos.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['restaurada'])): ?>
    <div class="aviso <?= $_GET['restaurada'] === '1' ? 'ok' : '' ?>">
      <?= $_GET['restaurada'] === '1'
          ? 'Persona restaurada. Volverá a aparecer en los celulares en su próxima sincronización.'
          : 'Esa persona no estaba borrada.' ?>
    </div>
  <?php endif; ?>

  <?php if ($seccion === 'resumen'): ?>

    <div class="tarjetas">
      <div class="kpi">
        <div class="kpi-icon icon-personas">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div>
          <div class="n"><?= (int)($resumen['personas'] ?? 0) ?></div>
          <div class="t">Personas</div>
        </div>
      </div>
      <div class="kpi">
        <div class="kpi-icon icon-encuestas">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        </div>
        <div>
          <div class="n"><?= (int)($resumen['encuestas'] ?? 0) ?></div>
          <div class="t">Encuestas</div>
        </div>
      </div>
      <div class="kpi">
        <div class="kpi-icon icon-encuestadores">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
        <div>
          <div class="n"><?= (int)($resumen['encuestadores'] ?? 0) ?></div>
          <div class="t">Encuestadores</div>
        </div>
      </div>
      <div class="kpi">
        <div class="kpi-icon icon-dispositivos">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
        </div>
        <div>
          <div class="n"><?= (int)($resumen['dispositivos'] ?? 0) ?></div>
          <div class="t">Dispositivos</div>
        </div>
      </div>
    </div>

    <div class="panel">
      <h2>Encuestas por día · últimos 14 días</h2>
      <?php if ($porDia === []): ?>
        <div class="vacio">Todavía no hay encuestas sincronizadas.</div>
      <?php else:
        $maxDia = max(array_column($porDia, 'total')) ?: 1; ?>
        <div class="grafico">
          <?php foreach ($porDia as $d): ?>
            <div class="barra-col" title="<?= h($d['dia']) ?>: <?= (int)$d['total'] ?>">
              <span class="barra-num"><?= (int)$d['total'] ?></span>
              <div class="barra-val" style="height: <?= max(2, (int)round(100 * $d['total'] / $maxDia)) ?>%"></div>
              <span class="barra-eti"><?= h(etiquetaDia($d['dia'])) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <p class="sub" style="margin-top:12px">
        Última sincronización recibida: <strong><?= h(fecha($resumen['ultima_sync'] ?? null)) ?></strong>
      </p>
    </div>

    <div class="dos">
      <div class="panel">
        <h2>Personas por municipio</h2>
        <?php if ($porMunicipio === []): ?>
          <div class="vacio">Sin datos.</div>
        <?php else:
          $maxMun = max(array_column($porMunicipio, 'total')) ?: 1; ?>
          <div class="lista-barras">
            <?php foreach ($porMunicipio as $m): ?>
              <div class="fila-barra">
                <span class="n"><?= h($m['municipio']) ?> <span style="color:var(--texto-3)">· <?= h($m['departamento']) ?></span></span>
                <span class="v"><?= (int)$m['total'] ?></span>
                <div class="pista"><div class="relleno" style="width: <?= (int)round(100 * $m['total'] / $maxMun) ?>%"></div></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="panel">
        <h2>Encuestas por encuestador</h2>
        <?php if ($porEncuestador === []): ?>
          <div class="vacio">Sin datos.</div>
        <?php else:
          $maxEnc = max(array_column($porEncuestador, 'total')) ?: 1; ?>
          <div class="lista-barras">
            <?php foreach ($porEncuestador as $e): ?>
              <div class="fila-barra">
                <span class="n"><?= h($e['nombre']) ?></span>
                <span class="v"><?= (int)$e['total'] ?></span>
                <div class="pista"><div class="relleno" style="width: <?= (int)round(100 * $e['total'] / $maxEnc) ?>%"></div></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  <?php elseif ($seccion === 'personas'): ?>

    <div class="panel">
      <h2><?= $verBorradas ? 'Personas borradas' : 'Personas registradas' ?></h2>

      <?php if ($verBorradas): ?>
        <p class="sub" style="margin-bottom:14px">
          Siguen en la base de datos, marcadas como borradas. Los celulares las
          ocultan igual. Restaurarlas las devuelve a todos los dispositivos en
          su próxima sincronización.
        </p>
      <?php endif; ?>

      <form class="buscador" method="get">
        <input type="hidden" name="seccion" value="personas">
        <?php if ($verBorradas): ?><input type="hidden" name="borradas" value="1"><?php endif; ?>
        <input type="search" name="q" value="<?= h($busqueda) ?>" placeholder="Buscar por nombre, apellido o documento…">
        <button class="btn" type="submit">Buscar</button>
        <?php if ($busqueda !== ''): ?>
          <a class="btn sec" href="?seccion=personas<?= $verBorradas ? '&borradas=1' : '' ?>">Limpiar</a>
        <?php endif; ?>
        <?php if ($verBorradas): ?>
          <a class="btn sec" href="?seccion=personas">← Volver a las activas</a>
        <?php else: ?>
          <a class="btn sec" href="?exportar=personas">Exportar CSV</a>
          <?php // Solo se ofrece si hay algo dentro: una papelera vacía es un
                // clic que no lleva a ninguna parte. ?>
          <?php if ($totalBorradas > 0): ?>
            <a class="btn sec" href="?seccion=personas&borradas=1">Ver borradas (<?= (int)$totalBorradas ?>)</a>
          <?php endif; ?>
        <?php endif; ?>
      </form>

      <?php if ($personas === []): ?>
        <div class="vacio">
          <?php if ($busqueda !== ''): ?>
            Ninguna persona coincide con la búsqueda.
          <?php elseif ($verBorradas): ?>
            No hay personas borradas.
          <?php else: ?>
            Todavía no se ha sincronizado ninguna persona.
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div style="overflow-x:auto">
        <table>
          <thead>
            <tr>
              <th>Documento</th><th>Nombre</th><th>Municipio</th>
              <th>Vereda</th><th>EPS</th><th>Estrato</th>
              <th><?= $verBorradas ? 'Borrada' : 'Actualizado' ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($personas as $p): ?>
              <tr>
                <td><?= h($p['tipo_documento']) ?> <?= h($p['numero_documento']) ?></td>
                <td><?= h($p['nombres']) ?> <?= h($p['apellidos']) ?></td>
                <td><?= h($p['municipio'] ?? '—') ?></td>
                <td><?= h($p['vereda'] ?: '—') ?></td>
                <td><?= h($p['eps'] ?: '—') ?></td>
                <td><?= h($p['estrato'] ?: '—') ?></td>
                <td style="color:var(--texto-2);white-space:nowrap">
                  <?= h(fecha($verBorradas ? $p['deleted_at'] : $p['updated_at'])) ?>
                </td>
                <td style="text-align:right">
                  <?php
                    // El confirm() no es seguridad, solo evita el clic accidental:
                    // quien tenga la sesión puede enviar el POST igualmente.
                    $nombreCompleto = trim($p['nombres'] . ' ' . $p['apellidos']);
                    $aviso = $verBorradas
                        ? "¿Restaurar a $nombreCompleto? Volverá a aparecer en todos los celulares en su próxima sincronización."
                        : "¿Borrar a $nombreCompleto? Desaparecerá también de los celulares en su próxima sincronización.";
                  ?>
                  <form method="post" style="margin:0"
                        onsubmit="return confirm('<?= h(str_replace("'", "\u{2019}", $aviso)) ?>')">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="<?= $verBorradas ? 'restaurar_persona' : 'borrar_persona' ?>">
                    <input type="hidden" name="tipo_documento" value="<?= h($p['tipo_documento']) ?>">
                    <input type="hidden" name="numero_documento" value="<?= h($p['numero_documento']) ?>">
                    <button type="submit" class="btn <?= $verBorradas ? 'sec' : 'peligro' ?>">
                      <?= $verBorradas ? 'Restaurar' : 'Borrar' ?>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>

        <div class="paginacion">
          <span class="info">
            <?= (int)$totalPersonas ?> persona(s) · página <?= (int)$pagina ?> de <?= (int)$totalPaginas ?>
          </span>
          <span style="display:flex;gap:8px">
            <?php
              // La papelera también pagina: sin arrastrar el parámetro, la
              // página 2 saltaría de vuelta a las personas activas.
              $qs = $busqueda !== '' ? '&q=' . urlencode($busqueda) : '';
              $qs .= $verBorradas ? '&borradas=1' : '';
            ?>
            <?php if ($pagina > 1): ?>
              <a class="btn sec" href="?seccion=personas&p=<?= $pagina - 1 ?><?= $qs ?>">Anterior</a>
            <?php endif; ?>
            <?php if ($pagina < $totalPaginas): ?>
              <a class="btn sec" href="?seccion=personas&p=<?= $pagina + 1 ?><?= $qs ?>">Siguiente</a>
            <?php endif; ?>
          </span>
        </div>
      <?php endif; ?>
    </div>

  <?php else: ?>

    <div class="panel">
      <h2><?= $editRow ? 'Editar cuenta' : 'Nueva cuenta' ?></h2>

      <?php if ($modoArranque): ?>
        <div class="aviso">
          Estás dentro con la contraseña compartida. Crea aquí una cuenta con rol
          <strong>Administrador</strong>: al guardarla, la contraseña compartida
          dejará de aceptarse y entrarás con documento y contraseña.
        </div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= h($editRow['id'] ?? '') ?>">
        <div class="fila-form">
          <div>
            <label class="campo" for="nombre">Nombre completo</label>
            <input type="text" id="nombre" name="nombre" value="<?= h($editRow['nombre'] ?? '') ?>">
          </div>
          <div>
            <label class="campo" for="doc">Número de documento</label>
            <input type="text" id="doc" name="numero_documento" value="<?= h($editRow['numero_documento'] ?? '') ?>">
          </div>
          <div>
            <label class="campo" for="pass">
              Contraseña <?= $editRow ? '(dejar vacío para no cambiarla)' : '' ?>
            </label>
            <input type="password" id="pass" name="password" autocomplete="new-password">
          </div>
          <div>
            <label class="campo" for="rol">Rol</label>
            <?php $rolActual = $editRow['rol'] ?? 'encuestador'; ?>
            <select id="rol" name="rol">
              <option value="encuestador" <?= $rolActual === 'encuestador' ? 'selected' : '' ?>>Encuestador</option>
              <option value="admin" <?= $rolActual === 'admin' ? 'selected' : '' ?>>Administrador</option>
            </select>
          </div>
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:.85rem;margin-bottom:14px">
          <input type="checkbox" name="activo" <?= (!$editRow || $editRow['activo']) ? 'checked' : '' ?>>
          Cuenta activa
        </label>
        <button class="btn" type="submit"><?= $editRow ? 'Guardar cambios' : 'Crear cuenta' ?></button>
        <?php if ($editRow): ?>
          <a class="btn sec" href="?seccion=encuestadores">Cancelar</a>
        <?php endif; ?>
      </form>
      <p class="sub" style="margin-top:10px">Mínimo <?= MIN_LONGITUD_PASSWORD ?> caracteres al fijar o cambiar la contraseña.</p>
    </div>

    <div class="panel">
      <h2>Cuentas</h2>
      <?php if ($encuestadores === []): ?>
        <div class="vacio">No hay cuentas registradas.</div>
      <?php else: ?>
        <table>
          <thead><tr><th>ID</th><th>Nombre</th><th>Documento</th><th>Rol</th><th>Estado</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($encuestadores as $e): ?>
              <tr>
                <td><?= h($e['id']) ?></td>
                <td><?= h($e['nombre']) ?></td>
                <td><?= h($e['numero_documento'] ?: '—') ?></td>
                <td>
                  <?php $esAdmin = ($e['rol'] ?? 'encuestador') === 'admin'; ?>
                  <span class="badge <?= $esAdmin ? 'si' : '' ?>"><?= $esAdmin ? 'Administrador' : 'Encuestador' ?></span>
                </td>
                <td><span class="badge <?= $e['activo'] ? 'si' : 'no' ?>"><?= $e['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
                <td style="text-align:right"><a class="btn sec" href="?edit=<?= h($e['id']) ?>">Editar</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  <?php endif; ?>

<?php endif; ?>

</div>
</body>
</html>
