<?php
/**
 * Vista: resumen. Se incluye desde index.php, que prepara todas las variables.
 */
$totales = array_column($porDia, 'total');
$totalDias = array_sum($totales);
$maxDia = $totales === [] ? 0 : max($totales);
$tope = topeEje($maxDia);
$indiceMax = $maxDia > 0 ? array_search($maxDia, $totales, true) : false;
$ultimoDia = count($porDia) - 1;
?>

  <?php if ($monitor['dispositivos_inactivos'] > 0 || $monitor['rechazos_7_dias'] > 0): ?>
    <?php
      $alertas = [];
      if ($monitor['dispositivos_inactivos'] > 0) {
          $alertas[] = '<strong>' . h(cantidad($monitor['dispositivos_inactivos'], 'celular lleva', 'celulares llevan'))
                     . '</strong> más de ' . DIAS_ALERTA_DISPOSITIVO . ' días sin sincronizar';
      }
      if ($monitor['rechazos_7_dias'] > 0) {
          $alertas[] = '<strong>' . h(cantidad($monitor['rechazos_7_dias'], 'registro rechazado', 'registros rechazados')) . '</strong> en 7 días';
      }
    ?>
    <?= cajaAviso('advertencia', implode(' · ', $alertas) . '. <a href="' . h(urlPanel(['seccion' => 'sincronizacion'])) . '">Ver el monitor</a>') ?>
  <?php endif; ?>

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
                $borde = ($i < 2 ? ' borde-ini' : ($i > $ultimoDia - 2 ? ' borde-fin' : '')) . ($i === $ultimoDia ? ' hoy' : ''); ?>
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

