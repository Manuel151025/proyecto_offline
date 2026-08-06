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
$pdo = conectarBD();

/** Longitud mínima al crear o cambiar la contraseña de un encuestador. */
const MIN_LONGITUD_PASSWORD = 10;

$adminPassword = getenv('ADMIN_PASSWORD');
if (!$adminPassword) {
    http_response_code(500);
    echo 'ADMIN_PASSWORD no está configurada en el entorno del servidor.';
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
        if (hash_equals($adminPassword, $_POST['password'] ?? '')) {
            // Se cambia el identificador de sesión al elevar privilegios.
            // Sin esto, quien consiguiera fijar el PHPSESSID de la víctima
            // antes del login seguiría dentro de la sesión ya autenticada
            // (fijación de sesión).
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            $_SESSION['admin_ok'] = true;
        } else {
            $error = 'Contraseña incorrecta';
        }
    } elseif ($action === 'logout') {
        // Se destruye la sesión entera, no solo la marca de autenticado.
        $_SESSION = [];
        session_regenerate_id(true);
        // El token CSRF se inicializa más arriba, antes de procesar el POST:
        // si no se repone aquí, el formulario de login quedaría sin token y
        // el siguiente envío sería rechazado.
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    } elseif ($action === 'save' && !empty($_SESSION['admin_ok'])) {
        $id = trim($_POST['id'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $documento = trim($_POST['numero_documento'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $activo = isset($_POST['activo']) ? 1 : 0;

        if ($nombre === '' || $documento === '') {
            $error = 'Nombre y número de documento son obligatorios';
        } elseif ($id === '' && $password === '') {
            $error = 'La contraseña es obligatoria para cuentas nuevas';
        } elseif ($password !== '' && mb_strlen($password) < MIN_LONGITUD_PASSWORD) {
            // Solo se valida al fijar o cambiar la contraseña: las cuentas
            // existentes no quedan bloqueadas por una regla nueva.
            $error = 'La contraseña debe tener al menos ' . MIN_LONGITUD_PASSWORD . ' caracteres';
        } else {
            try {
                if ($id !== '') {
                    if ($password !== '') {
                        $stmt = $pdo->prepare("UPDATE encuestadores SET nombre = ?, numero_documento = ?, password_hash = ?, activo = ? WHERE id = ?");
                        $stmt->execute([$nombre, $documento, password_hash($password, PASSWORD_BCRYPT), $activo, $id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE encuestadores SET nombre = ?, numero_documento = ?, activo = ? WHERE id = ?");
                        $stmt->execute([$nombre, $documento, $activo, $id]);
                    }
                } else {
                    $stmt = $pdo->prepare("INSERT INTO encuestadores (nombre, numero_documento, password_hash, activo) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$nombre, $documento, password_hash($password, PASSWORD_BCRYPT), $activo]);
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
    $stmt = $pdo->prepare('SELECT id, nombre, numero_documento, activo FROM encuestadores WHERE id = ?');
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

if ($loggedIn) {
    if ($seccion === 'resumen') {
        $resumen        = resumenGeneral($pdo);
        $porMunicipio   = personasPorMunicipio($pdo);
        $porDia         = encuestasPorDia($pdo);
        $porEncuestador = encuestasPorEncuestador($pdo);
    } elseif ($seccion === 'personas') {
        $totalPersonas = contarPersonas($pdo, $busqueda);
        $personas      = consultarPersonas($pdo, $busqueda, $porPagina, ($pagina - 1) * $porPagina);
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
<style>
  /* Misma paleta institucional que la PWA (pwa/css/base.css) y que el tema
     de Android: el panel es parte del mismo producto. */
  :root {
    --primary: #12467E; --primary-dark: #0C325C; --primary-tint: #EEF3F9;
    --surface: #fff; --surface-alt: #F7F9FC; --bg: #F2F5F9;
    --texto: #16202C; --texto-2: #5B6878; --texto-3: #8695A8;
    --divisor: #DCE3EC; --borde: #C3CDDA;
    --ok: #1B7A4B; --ok-bg: #E8F5EE; --error: #B3261E; --error-bg: #FCEEEE;
    --radio: 10px;
  }
  * { box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
         background: var(--bg); color: var(--texto); margin: 0; -webkit-font-smoothing: antialiased; }
  .barra { height: 4px; background: var(--primary); }
  .wrap { max-width: 1080px; margin: 0 auto; padding: 24px 20px 48px; }

  header.top { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
  .marca { display: flex; align-items: center; gap: 10px; }
  .logo { width: 36px; height: 36px; border-radius: 8px; background: var(--primary);
          display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; }
  h1 { font-size: 1.15rem; margin: 0; }
  .sub { font-size: .78rem; color: var(--texto-2); margin: 2px 0 0; }

  nav.tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--divisor); margin-bottom: 22px; flex-wrap: wrap; }
  nav.tabs a { padding: 9px 14px; font-size: .88rem; font-weight: 600; color: var(--texto-2);
               text-decoration: none; border-bottom: 2px solid transparent; }
  nav.tabs a:hover { color: var(--primary); }
  nav.tabs a.on { color: var(--primary); border-bottom-color: var(--primary); }

  .tarjetas { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 24px; }
  .kpi { background: var(--surface); border: 1px solid var(--divisor); border-radius: var(--radio); padding: 16px; }
  .kpi .n { font-size: 1.9rem; font-weight: 700; line-height: 1; color: var(--primary); }
  .kpi .t { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: var(--texto-2); margin-top: 6px; }

  .panel { background: var(--surface); border: 1px solid var(--divisor); border-radius: var(--radio); padding: 18px; margin-bottom: 20px; }
  .panel h2 { font-size: .95rem; margin: 0 0 14px; }
  .dos { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; }

  /* Gráfico de barras en CSS puro: sin librerías externas, que además la
     política de seguridad del sitio bloquearía. */
  .grafico { display: flex; align-items: flex-end; gap: 4px; height: 150px; padding-top: 8px; }
  .barra-col { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 4px; height: 100%; }
  .barra-val { width: 100%; background: var(--primary); border-radius: 3px 3px 0 0; min-height: 2px; transition: background .15s; }
  .barra-col:hover .barra-val { background: var(--primary-dark); }
  .barra-eti { font-size: .6rem; color: var(--texto-3); white-space: nowrap; }
  .barra-num { font-size: .65rem; font-weight: 600; color: var(--texto-2); }

  .lista-barras { display: flex; flex-direction: column; gap: 10px; }
  .fila-barra { display: grid; grid-template-columns: 1fr auto; gap: 4px; }
  .fila-barra .n { font-size: .82rem; }
  .fila-barra .v { font-size: .82rem; font-weight: 700; color: var(--primary); }
  .pista { grid-column: 1 / -1; height: 6px; background: var(--primary-tint); border-radius: 99px; overflow: hidden; }
  .relleno { height: 100%; background: var(--primary); border-radius: 99px; }

  table { width: 100%; border-collapse: collapse; font-size: .85rem; }
  th { text-align: left; font-size: .7rem; text-transform: uppercase; letter-spacing: .5px;
       color: var(--texto-2); border-bottom: 1px solid var(--divisor); padding: 8px 10px; white-space: nowrap; }
  td { padding: 10px; border-bottom: 1px solid var(--divisor); }
  tr:last-child td { border-bottom: none; }
  tbody tr:hover { background: var(--surface-alt); }
  .vacio { text-align: center; color: var(--texto-2); padding: 32px 16px; font-size: .88rem; }

  .badge { font-size: .68rem; font-weight: 700; padding: 3px 8px; border-radius: 99px; text-transform: uppercase; }
  .badge.si { background: var(--ok-bg); color: var(--ok); }
  .badge.no { background: var(--error-bg); color: var(--error); }

  input[type=text], input[type=password], input[type=search] {
    width: 100%; padding: 9px 11px; font-size: .9rem; font-family: inherit; color: var(--texto);
    border: 1px solid var(--borde); border-radius: 8px; outline: none; background: var(--surface); }
  input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(18,70,126,.16); }
  label.campo { display: block; font-size: .75rem; font-weight: 600; color: var(--texto-2); margin: 0 0 5px; }
  .fila-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 12px; }

  .btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 15px; border: none; border-radius: 8px;
         background: var(--primary); color: #fff; font-size: .85rem; font-weight: 600; font-family: inherit;
         cursor: pointer; text-decoration: none; }
  .btn:hover { background: var(--primary-dark); }
  .btn.sec { background: var(--surface); color: var(--primary); border: 1px solid var(--borde); }
  .btn.sec:hover { background: var(--primary-tint); }

  .aviso { padding: 10px 12px; border-radius: 8px; font-size: .85rem; margin-bottom: 14px;
           background: var(--error-bg); color: var(--error); border: 1px solid #F0D2D0; }
  .buscador { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
  .buscador input { flex: 1; min-width: 200px; }
  .paginacion { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 14px; flex-wrap: wrap; }
  .paginacion .info { font-size: .8rem; color: var(--texto-2); }

  .login-caja { max-width: 380px; margin: 60px auto; }
</style>
</head>
<body>
<div class="barra"></div>
<div class="wrap">

<?php if (!$loggedIn): ?>

  <div class="login-caja">
    <div class="marca" style="justify-content:center;margin-bottom:18px">
      <div class="logo">+</div>
      <div>
        <h1>Admin · ColOffline</h1>
        <p class="sub">Ministerio de Salud</p>
      </div>
    </div>
    <div class="panel">
      <?php if ($error): ?><div class="aviso"><?= h($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="login">
        <label class="campo" for="pw">Contraseña de administrador</label>
        <input type="password" id="pw" name="password" autocomplete="current-password" autofocus>
        <button class="btn" type="submit" style="width:100%;justify-content:center;margin-top:12px">Ingresar</button>
      </form>
    </div>
  </div>

<?php else: ?>

  <header class="top">
    <div class="marca">
      <div class="logo">+</div>
      <div>
        <h1>Admin · ColOffline</h1>
        <p class="sub">Ministerio de Salud · Encuestas demográficas</p>
      </div>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="logout">
      <button class="btn sec" type="submit">Salir</button>
    </form>
  </header>

  <nav class="tabs">
    <a href="?seccion=resumen" class="<?= $seccion === 'resumen' ? 'on' : '' ?>">Resumen</a>
    <a href="?seccion=personas" class="<?= $seccion === 'personas' ? 'on' : '' ?>">Personas</a>
    <a href="?seccion=encuestadores" class="<?= $seccion === 'encuestadores' ? 'on' : '' ?>">Encuestadores</a>
  </nav>

  <?php if ($error): ?><div class="aviso"><?= h($error) ?></div><?php endif; ?>

  <?php if ($seccion === 'resumen'): ?>

    <div class="tarjetas">
      <div class="kpi"><div class="n"><?= (int)($resumen['personas'] ?? 0) ?></div><div class="t">Personas</div></div>
      <div class="kpi"><div class="n"><?= (int)($resumen['encuestas'] ?? 0) ?></div><div class="t">Encuestas</div></div>
      <div class="kpi"><div class="n"><?= (int)($resumen['encuestadores'] ?? 0) ?></div><div class="t">Encuestadores</div></div>
      <div class="kpi"><div class="n"><?= (int)($resumen['dispositivos'] ?? 0) ?></div><div class="t">Dispositivos</div></div>
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
      <h2>Personas registradas</h2>
      <form class="buscador" method="get">
        <input type="hidden" name="seccion" value="personas">
        <input type="search" name="q" value="<?= h($busqueda) ?>" placeholder="Buscar por nombre, apellido o documento…">
        <button class="btn" type="submit">Buscar</button>
        <?php if ($busqueda !== ''): ?>
          <a class="btn sec" href="?seccion=personas">Limpiar</a>
        <?php endif; ?>
        <a class="btn sec" href="?exportar=personas">Exportar CSV</a>
      </form>

      <?php if ($personas === []): ?>
        <div class="vacio">
          <?= $busqueda !== '' ? 'Ninguna persona coincide con la búsqueda.' : 'Todavía no se ha sincronizado ninguna persona.' ?>
        </div>
      <?php else: ?>
        <div style="overflow-x:auto">
        <table>
          <thead>
            <tr>
              <th>Documento</th><th>Nombre</th><th>Municipio</th>
              <th>Vereda</th><th>EPS</th><th>Estrato</th><th>Actualizado</th>
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
                <td style="color:var(--texto-2);white-space:nowrap"><?= h(fecha($p['updated_at'])) ?></td>
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
            <?php $qs = $busqueda !== '' ? '&q=' . urlencode($busqueda) : ''; ?>
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
      <h2><?= $editRow ? 'Editar encuestador' : 'Nuevo encuestador' ?></h2>
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
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:.85rem;margin-bottom:14px">
          <input type="checkbox" name="activo" <?= (!$editRow || $editRow['activo']) ? 'checked' : '' ?>>
          Cuenta activa
        </label>
        <button class="btn" type="submit"><?= $editRow ? 'Guardar cambios' : 'Crear encuestador' ?></button>
        <?php if ($editRow): ?>
          <a class="btn sec" href="?seccion=encuestadores">Cancelar</a>
        <?php endif; ?>
      </form>
      <p class="sub" style="margin-top:10px">Mínimo <?= MIN_LONGITUD_PASSWORD ?> caracteres al fijar o cambiar la contraseña.</p>
    </div>

    <div class="panel">
      <h2>Encuestadores</h2>
      <?php if ($encuestadores === []): ?>
        <div class="vacio">No hay encuestadores registrados.</div>
      <?php else: ?>
        <table>
          <thead><tr><th>ID</th><th>Nombre</th><th>Documento</th><th>Estado</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($encuestadores as $e): ?>
              <tr>
                <td><?= h($e['id']) ?></td>
                <td><?= h($e['nombre']) ?></td>
                <td><?= h($e['numero_documento'] ?: '—') ?></td>
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
