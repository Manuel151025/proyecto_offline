<?php
session_start();
require_once '../db.php';

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
            $_SESSION['admin_ok'] = true;
        } else {
            $error = 'Contraseña incorrecta';
        }
    } elseif ($action === 'logout') {
        unset($_SESSION['admin_ok']);
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
                header('Location: index.php');
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

$editRow = null;
if ($loggedIn && isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT id, nombre, numero_documento, activo FROM encuestadores WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editRow = $stmt->fetch();
}

$encuestadores = $loggedIn
    ? $pdo->query("SELECT id, nombre, numero_documento, activo FROM encuestadores ORDER BY id")->fetchAll()
    : [];

function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin · ColOffline</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #F0F4F8; color: #1a1a2e; margin: 0; padding: 24px; }
  .wrap { max-width: 720px; margin: 0 auto; }
  h1 { font-size: 1.3rem; margin: 0 0 20px; }
  .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px; }
  .field { margin-bottom: 14px; }
  .field label { display: block; font-size: 0.78rem; font-weight: 600; color: #5a6070; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.4px; }
  .field input[type=text], .field input[type=password] {
    width: 100%; padding: 10px 12px; border: 1.5px solid #e0e4ea; border-radius: 8px; font-size: 0.95rem; outline: none;
  }
  .field input:focus { border-color: #1565C0; }
  .checkbox { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
  .btn { display: inline-block; padding: 10px 18px; border-radius: 8px; border: none; background: #1565C0; color: #fff; font-weight: 600; cursor: pointer; font-size: 0.9rem; text-decoration: none; }
  .btn:hover { background: #003c8f; }
  .btn-link { background: none; color: #1565C0; padding: 4px 6px; }
  .error { background: #FFEBEE; color: #C62828; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 0.88rem; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #e0e4ea; font-size: 0.88rem; }
  .badge { font-size: 0.7rem; font-weight: 700; padding: 2px 8px; border-radius: 99px; }
  .badge-on { background: #E8F5E9; color: #2E7D32; }
  .badge-off { background: #FFEBEE; color: #C62828; }
  .top-row { display: flex; justify-content: space-between; align-items: center; }
</style>
</head>
<body>
<div class="wrap">
<?php if (!$loggedIn): ?>
  <h1>Admin · ColOffline</h1>
  <div class="card">
    <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="login">
      <div class="field">
        <label for="password">Contraseña de administrador</label>
        <input type="password" id="password" name="password" autofocus required>
      </div>
      <button type="submit" class="btn">Entrar</button>
    </form>
  </div>
<?php else: ?>
  <div class="top-row">
    <h1>Encuestadores</h1>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="logout">
      <button type="submit" class="btn btn-link">Cerrar sesión</button>
    </form>
  </div>

  <div class="card">
    <h2 style="font-size:1rem;margin-top:0"><?= $editRow ? 'Editar encuestador' : 'Nuevo encuestador' ?></h2>
    <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= h($editRow['id'] ?? '') ?>">
      <div class="field">
        <label for="nombre">Nombre</label>
        <input type="text" id="nombre" name="nombre" value="<?= h($editRow['nombre'] ?? '') ?>" required>
      </div>
      <div class="field">
        <label for="numero_documento">Número de documento</label>
        <input type="text" id="numero_documento" name="numero_documento" value="<?= h($editRow['numero_documento'] ?? '') ?>" required>
      </div>
      <div class="field">
        <label for="password">Contraseña <?= $editRow ? '(dejar en blanco para no cambiarla)' : '' ?></label>
        <input type="password" id="password" name="password" autocomplete="new-password">
      </div>
      <div class="checkbox">
        <input type="checkbox" id="activo" name="activo" <?= (!$editRow || $editRow['activo']) ? 'checked' : '' ?>>
        <label for="activo" style="margin:0;text-transform:none;font-weight:500">Cuenta activa</label>
      </div>
      <button type="submit" class="btn"><?= $editRow ? 'Guardar cambios' : 'Crear encuestador' ?></button>
      <?php if ($editRow): ?><a href="index.php" class="btn btn-link">Cancelar</a><?php endif; ?>
    </form>
  </div>

  <div class="card">
    <table>
      <thead><tr><th>Nombre</th><th>Documento</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($encuestadores as $e): ?>
        <tr>
          <td><?= h($e['nombre']) ?></td>
          <td><?= h($e['numero_documento']) ?: '—' ?></td>
          <td><span class="badge <?= $e['activo'] ? 'badge-on' : 'badge-off' ?>"><?= $e['activo'] ? 'Activa' : 'Inactiva' ?></span></td>
          <td><a href="index.php?edit=<?= (int)$e['id'] ?>">Editar</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</div>
</body>
</html>
