<?php
/**
 * Vista: cuentas. Se incluye desde index.php, que prepara todas las variables.
 */
?>
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
          <table class="tabla tabla-densa">
            <thead>
              <tr>
                <th scope="col">Cuenta</th>
                <th scope="col">Rol</th>
                <th scope="col">Estado</th>
                <th scope="col" class="num">Encuestas</th>
                <th scope="col" class="num">Celulares</th>
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
                  <td class="num"><?= numero($sesionesPorCuenta[(int)$c['id']] ?? 0) ?></td>
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

      <?php if ($editando): ?>
        <div class="tarjeta-cuerpo seguridad-cuenta">
          <h3>Acceso desde celulares</h3>
          <?php if ($sesionesCuenta === []): ?>
            <p class="campo-ayuda">No tiene sesiones abiertas en ningún celular.</p>
          <?php else: ?>
            <p class="campo-ayuda">
              <?= h(cantidad(count($sesionesCuenta), 'sesión abierta', 'sesiones abiertas')) ?>.
              Último uso: <?= h(haceCuanto(((int)($sesionesCuenta[0]['ultimo_uso'] ?? $sesionesCuenta[0]['creado_en'])) * 1000)) ?>.
            </p>
            <form method="post" action="index.php" data-confirmar="¿Cerrar las sesiones de <?= h($formCuenta['nombre']) ?> en todos sus celulares? Tendrá que volver a entrar con conexión; lo que tenga sin enviar no se pierde.">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="cerrar_sesiones">
              <input type="hidden" name="id" value="<?= h($formCuenta['id']) ?>">
              <button class="btn btn-secundario btn-sm" type="submit"><?= icono('celular', 14) ?>Cerrar sesiones en celulares</button>
            </form>
          <?php endif; ?>

          <?php if ($bloqueoCuenta > 0): ?>
            <?= cajaAviso('advertencia', 'Bloqueada por intentos fallidos durante ' . h((string)(int)ceil($bloqueoCuenta / 60)) . ' minuto(s) más.') ?>
            <form method="post" action="index.php">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="desbloquear_cuenta">
              <input type="hidden" name="id" value="<?= h($formCuenta['id']) ?>">
              <button class="btn btn-secundario btn-sm" type="submit">Desbloquear ahora</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
