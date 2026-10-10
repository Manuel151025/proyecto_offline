<?php
/**
 * Vista: monitor de sincronización. Se incluye desde index.php, que prepara
 * todas las variables.
 */
$limiteAlerta = (int)round(microtime(true) * 1000) - DIAS_ALERTA_DISPOSITIVO * 86400000;
?>

<section class="tarjeta cifras cifras-3" aria-label="Estado de la sincronización">
  <div class="cifra">
    <p class="cifra-etiqueta">Celulares conocidos</p>
    <p class="cifra-valor"><?= numero($monitor['dispositivos']) ?></p>
    <p class="cifra-nota">Han hablado con el servidor desde esta versión</p>
  </div>
  <div class="cifra">
    <p class="cifra-etiqueta">Sin sincronizar hace +<?= DIAS_ALERTA_DISPOSITIVO ?> días</p>
    <p class="cifra-valor<?= $monitor['dispositivos_inactivos'] > 0 ? ' cifra-alerta' : '' ?>"><?= numero($monitor['dispositivos_inactivos']) ?></p>
    <p class="cifra-nota">Pueden tener encuestas sin enviar</p>
  </div>
  <div class="cifra">
    <p class="cifra-etiqueta">Rechazos en 7 días</p>
    <p class="cifra-valor<?= $monitor['rechazos_7_dias'] > 0 ? ' cifra-alerta' : '' ?>"><?= numero($monitor['rechazos_7_dias']) ?></p>
    <p class="cifra-nota">Registros que el servidor no aceptó</p>
  </div>
</section>

<section class="tarjeta" aria-labelledby="t-dispositivos">
  <div class="tarjeta-cabecera">
    <div>
      <h2 id="t-dispositivos">Celulares</h2>
      <p>Los que llevan más de <?= DIAS_ALERTA_DISPOSITIVO ?> días sin comunicarse aparecen marcados.</p>
    </div>
  </div>
  <?php if ($dispositivos === []): ?>
    <div class="vacio">
      <?= icono('celular', 28) ?>
      <strong>Aún no hay celulares registrados</strong>
      Aparecerán cuando sincronicen con la versión actual de la app.
    </div>
  <?php else: ?>
    <div class="tabla-contenedor">
      <table class="tabla">
        <thead>
          <tr>
            <th scope="col">Celular</th>
            <th scope="col">Encuestador</th>
            <th scope="col">Plataforma</th>
            <th scope="col">Última subida</th>
            <th scope="col">Última descarga</th>
            <th scope="col">Estado</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($dispositivos as $d):
              $inactivo = (int)$d['ultima_actividad'] < $limiteAlerta; ?>
            <tr>
              <td><span class="celda-principal celda-codigo"><?= h($d['device_id']) ?></span></td>
              <td><?= !empty($d['encuestador']) ? h($d['encuestador']) : '<span class="apagado">—</span>' ?></td>
              <td><?= h(['pwa' => 'PWA (navegador)', 'android' => 'Android'][strtolower((string)($d['plataforma'] ?? ''))] ?? ucfirst((string)($d['plataforma'] ?? '—'))) ?><span class="celda-sec"><?= !empty($d['version_app']) ? 'versión ' . h($d['version_app']) : '' ?></span></td>
              <td class="celda-fecha"><?= h(haceCuanto($d['ultima_subida'])) ?></td>
              <td class="celda-fecha"><?= h(haceCuanto($d['ultima_descarga'])) ?></td>
              <td><span class="estado<?= $inactivo ? ' estado-alerta' : ' estado-activo' ?>"><?= $inactivo ? 'Sin sincronizar' : 'Al día' ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="tarjeta" aria-labelledby="t-rechazos">
  <div class="tarjeta-cabecera">
    <div>
      <h2 id="t-rechazos">Registros rechazados</h2>
      <p>No se reintentan: hay que corregirlos en el celular. Los 50 más recientes.</p>
    </div>
  </div>
  <?php if ($rechazos === []): ?>
    <div class="vacio">
      <?= icono('ok', 28) ?>
      <strong>Sin rechazos</strong>
      Todo lo que llegó de los celulares cumplió las reglas.
    </div>
  <?php else: ?>
    <div class="tabla-contenedor">
      <table class="tabla">
        <thead>
          <tr>
            <th scope="col">Cuándo</th>
            <th scope="col">Documento</th>
            <th scope="col">Motivo</th>
            <th scope="col">Encuestador</th>
            <th scope="col">Celular</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rechazos as $r): ?>
            <tr>
              <td class="celda-fecha"><?= h(fecha($r['creado_en'])) ?></td>
              <td><?= h(trim((string)$r['tipo_documento'] . ' ' . (string)$r['numero_documento'])) ?></td>
              <td class="celda-motivo"><?= h($r['motivo']) ?></td>
              <td><?= !empty($r['encuestador']) ? h($r['encuestador']) : '<span class="apagado">—</span>' ?></td>
              <td class="celda-codigo"><?= !empty($r['device_id']) ? h($r['device_id']) : '<span class="apagado">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
