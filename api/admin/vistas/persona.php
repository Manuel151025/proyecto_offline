<?php
/**
 * Vista: ficha de una persona. Se incluye desde index.php, que prepara todas
 * las variables.
 */
$p = $personaFicha;
$tipoP = (string)$p['tipo_documento'];
$numeroP = (string)$p['numero_documento'];
$nombreP = trim((string)$p['nombres'] . ' ' . (string)$p['apellidos']);
$borrada = !empty($p['deleted_at']);
$editarPersona = textoGet('editar_persona') === '1' || $errorPersona !== null;
$valores = $formPersona ?? $p;
$fichaUrl = urlPanel(['seccion' => 'persona', 'tipo' => $tipoP, 'numero' => $numeroP]);
$vacio = '<span class="apagado">—</span>';
$dato = fn (mixed $v): string => ($v === null || $v === '') ? $vacio : h($v);
?>

<a class="volver-lista" href="<?= h(urlPanel(['seccion' => 'personas'])) ?>"><?= icono('volver', 16) ?>Volver a personas</a>

<section class="tarjeta ficha-cabecera" aria-label="Persona">
  <div class="ficha-identidad">
    <span class="avatar avatar-grande" aria-hidden="true"><?= h(iniciales($nombreP)) ?></span>
    <div>
      <h2><?= h($nombreP) ?></h2>
      <p><?= h($tipoP) ?> <?= h($numeroP) ?> · <span class="estado<?= $borrada ? '' : ' estado-activo' ?>"><?= $borrada ? 'En la papelera' : 'Activa' ?></span></p>
    </div>
  </div>
  <div class="ficha-acciones">
    <?php if (!$borrada && !$editarPersona): ?>
      <a class="btn btn-secundario" href="<?= h(urlPanel(['seccion' => 'persona', 'tipo' => $tipoP, 'numero' => $numeroP, 'editar_persona' => '1'])) ?>#editar">
        <?= icono('editar', 16) ?>Editar
      </a>
    <?php endif; ?>
    <form method="post" action="index.php" data-confirmar="<?= h($borrada
        ? "¿Restaurar a $nombreP? Volverá a aparecer en todos los celulares en su próxima sincronización."
        : "¿Borrar a $nombreP? Desaparecerá también de los celulares en su próxima sincronización.") ?>">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="<?= $borrada ? 'restaurar_persona' : 'borrar_persona' ?>">
      <input type="hidden" name="tipo_documento" value="<?= h($tipoP) ?>">
      <input type="hidden" name="numero_documento" value="<?= h($numeroP) ?>">
      <input type="hidden" name="nombre" value="<?= h($nombreP) ?>">
      <input type="hidden" name="volver" value="<?= h(http_build_query(['seccion' => 'persona', 'tipo' => $tipoP, 'numero' => $numeroP])) ?>">
      <?php if ($borrada): ?>
        <button type="submit" class="btn btn-secundario"><?= icono('restaurar', 16) ?>Restaurar</button>
      <?php else: ?>
        <button type="submit" class="btn btn-peligro"><?= icono('borrar', 16) ?>Borrar</button>
      <?php endif; ?>
    </form>
  </div>
</section>

<?php if ($editarPersona): ?>
  <section class="tarjeta" id="editar" aria-labelledby="t-editar-persona">
    <div class="tarjeta-cabecera">
      <div>
        <h2 id="t-editar-persona">Editar datos</h2>
        <p>Se aplican las mismas reglas que en los celulares. El cambio llega a todos en su próxima sincronización.</p>
      </div>
    </div>
    <form class="tarjeta-cuerpo form-persona" method="post" action="<?= h($fichaUrl) ?>#editar">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="editar_persona">
      <input type="hidden" name="tipo_documento" value="<?= h($tipoP) ?>">
      <input type="hidden" name="numero_documento" value="<?= h($numeroP) ?>">

      <?php if ($errorPersona !== null): ?><?= cajaAviso('error', h($errorPersona)) ?><?php endif; ?>

      <div class="rejilla-campos">
        <div class="campo">
          <label class="campo-etiqueta" for="p-nombres">Nombres</label>
          <input class="input" id="p-nombres" name="nombres" value="<?= h($valores['nombres'] ?? '') ?>" maxlength="100" required>
        </div>
        <div class="campo">
          <label class="campo-etiqueta" for="p-apellidos">Apellidos</label>
          <input class="input" id="p-apellidos" name="apellidos" value="<?= h($valores['apellidos'] ?? '') ?>" maxlength="100" required>
        </div>
        <div class="campo">
          <label class="campo-etiqueta" for="p-nacimiento">Fecha de nacimiento</label>
          <input class="input" type="date" id="p-nacimiento" name="fecha_nacimiento"
                 value="<?= is_numeric($valores['fecha_nacimiento'] ?? null) ? h(gmdate('Y-m-d', intdiv((int)$valores['fecha_nacimiento'], 1000))) : '' ?>">
        </div>
        <div class="campo">
          <label class="campo-etiqueta" for="p-telefono">Teléfono</label>
          <input class="input" type="tel" id="p-telefono" name="telefono" value="<?= h($valores['telefono'] ?? '') ?>" maxlength="20">
        </div>
        <div class="campo">
          <label class="campo-etiqueta" for="p-email">Correo</label>
          <input class="input" type="email" id="p-email" name="email" value="<?= h($valores['email'] ?? '') ?>" maxlength="100">
        </div>
        <div class="campo">
          <label class="campo-etiqueta" for="p-direccion">Dirección</label>
          <input class="input" id="p-direccion" name="direccion" value="<?= h($valores['direccion'] ?? '') ?>" maxlength="150">
        </div>
        <div class="campo">
          <label class="campo-etiqueta" for="p-municipio">Municipio</label>
          <select class="input" id="p-municipio" name="municipio_codigo">
            <option value="">Sin municipio</option>
            <?php $grupo = null; foreach ($municipios as $m): ?>
              <?php if ($m['departamento'] !== $grupo): ?>
                <?= $grupo !== null ? '</optgroup>' : '' ?><optgroup label="<?= h($m['departamento']) ?>">
                <?php $grupo = $m['departamento']; ?>
              <?php endif; ?>
              <option value="<?= h($m['codigo']) ?>"<?= (string)($valores['municipio_codigo'] ?? '') === (string)$m['codigo'] ? ' selected' : '' ?>><?= h($m['nombre']) ?></option>
            <?php endforeach; ?>
            <?= $grupo !== null ? '</optgroup>' : '' ?>
          </select>
        </div>
        <div class="campo">
          <label class="campo-etiqueta" for="p-vereda">Vereda</label>
          <input class="input" id="p-vereda" name="vereda" value="<?= h($valores['vereda'] ?? '') ?>" maxlength="100">
        </div>
        <div class="campo">
          <label class="campo-etiqueta" for="p-eps">EPS</label>
          <input class="input" id="p-eps" name="eps" value="<?= h($valores['eps'] ?? '') ?>" maxlength="50">
        </div>
        <div class="campo">
          <label class="campo-etiqueta" for="p-ocupacion">Ocupación</label>
          <input class="input" id="p-ocupacion" name="ocupacion" value="<?= h($valores['ocupacion'] ?? '') ?>" maxlength="100">
        </div>
        <div class="campo">
          <label class="campo-etiqueta" for="p-estrato">Estrato</label>
          <input class="input" type="number" id="p-estrato" name="estrato" value="<?= h($valores['estrato'] ?? '') ?>" min="1" max="6">
        </div>
      </div>

      <div class="form-acciones">
        <button class="btn btn-primario" type="submit">Guardar cambios</button>
        <a class="btn btn-secundario" href="<?= h($fichaUrl) ?>">Cancelar</a>
      </div>
    </form>
  </section>
<?php endif; ?>

<div class="dos-columnas">
  <section class="tarjeta" aria-labelledby="t-datos">
    <div class="tarjeta-cabecera"><div><h2 id="t-datos">Datos</h2></div></div>
    <dl class="tarjeta-cuerpo datos-persona">
      <div><dt>Fecha de nacimiento</dt><dd><?= is_numeric($p['fecha_nacimiento']) ? h(fechaNacimiento($p['fecha_nacimiento'])) : $vacio ?></dd></div>
      <div><dt>Teléfono</dt><dd><?= $dato($p['telefono']) ?></dd></div>
      <div><dt>Correo</dt><dd><?= $dato($p['email']) ?></dd></div>
      <div><dt>Dirección</dt><dd><?= $dato($p['direccion']) ?></dd></div>
      <div><dt>Municipio</dt><dd><?= !empty($p['municipio']) ? h($p['municipio']) . ' · ' . h($p['departamento']) : $vacio ?></dd></div>
      <div><dt>Vereda</dt><dd><?= $dato($p['vereda']) ?></dd></div>
      <div><dt>EPS</dt><dd><?= $dato($p['eps']) ?></dd></div>
      <div><dt>Ocupación</dt><dd><?= $dato($p['ocupacion']) ?></dd></div>
      <div><dt>Estrato</dt><dd><?= $dato($p['estrato']) ?></dd></div>
      <div><dt>Última actualización</dt><dd><?= h(fecha($p['updated_at'])) ?></dd></div>
      <div><dt>Último en escribirla</dt><dd><?= h($p['device_id']) ?></dd></div>
    </dl>
  </section>

  <section class="tarjeta" aria-labelledby="t-historial">
    <div class="tarjeta-cabecera">
      <div>
        <h2 id="t-historial">Historial de encuestas</h2>
        <p><?= h(cantidad(count($historial), 'encuesta', 'encuestas')) ?>, la más reciente primero</p>
      </div>
    </div>
    <div class="tarjeta-cuerpo">
      <?php if ($historial === []): ?>
        <div class="vacio">Sin encuestas registradas para esta persona.</div>
      <?php else: ?>
        <ol class="linea-tiempo">
          <?php foreach ($historial as $en): ?>
            <li>
              <span class="linea-punto" aria-hidden="true"></span>
              <div>
                <p class="linea-titulo"><?= h(ACCIONES_ENCUESTA[(string)$en['accion']] ?? ucfirst(strtolower((string)$en['accion']))) ?> · <?= h($en['encuestador']) ?></p>
                <p class="linea-meta"><?= h(fecha($en['fecha_encuesta'])) ?> · <?= icono('celular', 12) ?> <?= h($en['device_id']) ?></p>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
    </div>
  </section>
</div>

<section class="tarjeta" aria-labelledby="t-cambios-panel">
  <div class="tarjeta-cabecera">
    <div>
      <h2 id="t-cambios-panel">Cambios desde el panel</h2>
      <p>Quién borró, restauró o editó esta persona.</p>
    </div>
  </div>
  <div class="tarjeta-cuerpo">
    <?php if ($auditoriaPersona === []): ?>
      <div class="vacio">Ningún administrador ha modificado esta persona.</div>
    <?php else: ?>
      <ol class="linea-tiempo">
        <?php foreach ($auditoriaPersona as $a): ?>
          <li>
            <span class="linea-punto" aria-hidden="true"></span>
            <div>
              <p class="linea-titulo"><?= h(ETIQUETAS_AUDITORIA[$a['accion']] ?? $a['accion']) ?> · <?= h($a['nombre_admin']) ?></p>
              <p class="linea-meta"><?= h(fecha($a['creado_en'])) ?></p>
              <?php if (($resumenCambio = describirAuditoria($a['detalle'])) !== ''): ?><p class="linea-detalle"><?= h($resumenCambio) ?></p><?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>
</section>
