<?php
/**
 * Vista: registro de auditoría. Se incluye desde index.php, que prepara todas
 * las variables.
 */
?>

<section class="tarjeta" aria-label="Registro de auditoría">
  <?php if ($registrosAuditoria === []): ?>
    <div class="vacio">
      <?= icono('auditoria', 28) ?>
      <strong>Todavía no hay acciones registradas</strong>
      Cada vez que un administrador entre, borre, restaure, edite o cambie una cuenta, quedará aquí.
    </div>
  <?php else: ?>
    <div class="tabla-contenedor">
      <table class="tabla">
        <thead>
          <tr>
            <th scope="col">Cuándo</th>
            <th scope="col">Administrador</th>
            <th scope="col">Acción</th>
            <th scope="col">Sobre</th>
            <th scope="col">Detalle</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($registrosAuditoria as $a):
              $objeto = (string)$a['objeto'];
              $enlace = null;
              if (str_starts_with($objeto, 'persona:')) {
                  [$tipoA, $numeroA] = array_pad(explode(' ', substr($objeto, 8), 2), 2, '');
                  $enlace = urlPanel(['seccion' => 'persona', 'tipo' => $tipoA, 'numero' => $numeroA]);
              } ?>
            <tr>
              <td class="celda-fecha"><?= h(fecha($a['creado_en'])) ?></td>
              <td><?= h($a['nombre_admin']) ?></td>
              <td><?= h(ETIQUETAS_AUDITORIA[$a['accion']] ?? $a['accion']) ?></td>
              <td><?= $enlace !== null ? '<a href="' . h($enlace) . '">' . h($objeto) . '</a>' : h($objeto) ?></td>
              <td class="celda-motivo"><?= h(describirAuditoria($a['detalle'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <nav class="paginacion" aria-label="Paginación">
      <span><?= h(cantidad($totalAuditoria, 'registro', 'registros')) ?></span>
      <span class="paginacion-botones">
        <?php if ($pagina > 1): ?>
          <a class="btn btn-secundario btn-sm" href="<?= h(urlPanel(['seccion' => 'auditoria', 'p' => $pagina - 1])) ?>"><?= icono('anterior', 14) ?>Más recientes</a>
        <?php endif; ?>
        <span>Página <?= $pagina ?> de <?= $totalPaginas ?></span>
        <?php if ($pagina < $totalPaginas): ?>
          <a class="btn btn-secundario btn-sm" href="<?= h(urlPanel(['seccion' => 'auditoria', 'p' => $pagina + 1])) ?>">Anteriores<?= icono('siguiente', 14) ?></a>
        <?php endif; ?>
      </span>
    </nav>
  <?php endif; ?>
</section>
