<?php
/**
 * Vista: personas. Se incluye desde index.php, que prepara todas las variables.
 */
?>
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

    <details class="filtros"<?= $hayFiltros ? ' open' : '' ?>>
      <summary><?= icono('filtro', 14) ?>Filtros<?= $hayFiltros ? ' · ' . h(cantidad(count($filtros), 'activo', 'activos')) : '' ?></summary>
      <form class="filtros-campos" method="get" action="index.php">
        <input type="hidden" name="seccion" value="personas">
        <?php if ($verBorradas): ?><input type="hidden" name="borradas" value="1"><?php endif; ?>
        <?php if ($busqueda !== ''): ?><input type="hidden" name="q" value="<?= h($busqueda) ?>"><?php endif; ?>
        <div class="campo">
          <label class="campo-etiqueta" for="f-departamento">Departamento</label>
          <select class="input" id="f-departamento" name="departamento">
            <option value="">Todos</option>
            <?php foreach (array_unique(array_column($municipios, 'departamento')) as $depto): ?>
              <option value="<?= h($depto) ?>"<?= textoGet('departamento') === $depto ? ' selected' : '' ?>><?= h($depto) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="campo">
          <label class="campo-etiqueta" for="f-municipio">Municipio</label>
          <select class="input" id="f-municipio" name="municipio">
            <option value="">Todos</option>
            <?php $grupo = null; foreach ($municipios as $m): ?>
              <?php if ($m['departamento'] !== $grupo): ?>
                <?= $grupo !== null ? '</optgroup>' : '' ?><optgroup label="<?= h($m['departamento']) ?>">
                <?php $grupo = $m['departamento']; ?>
              <?php endif; ?>
              <option value="<?= h($m['codigo']) ?>"<?= textoGet('municipio') === (string)$m['codigo'] ? ' selected' : '' ?>><?= h($m['nombre']) ?></option>
            <?php endforeach; ?>
            <?= $grupo !== null ? '</optgroup>' : '' ?>
          </select>
        </div>
        <div class="campo">
          <label class="campo-etiqueta" for="f-encuestador">Encuestador</label>
          <select class="input" id="f-encuestador" name="encuestador">
            <option value="">Todos</option>
            <?php foreach ($encuestadoresFiltro as $en): ?>
              <option value="<?= (int)$en['id'] ?>"<?= textoGet('encuestador') === (string)$en['id'] ? ' selected' : '' ?>><?= h($en['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="campo">
          <label class="campo-etiqueta" for="f-desde">Actualizada desde</label>
          <input class="input" type="date" id="f-desde" name="desde" value="<?= h(textoGet('desde')) ?>">
        </div>
        <div class="campo">
          <label class="campo-etiqueta" for="f-hasta">Hasta</label>
          <input class="input" type="date" id="f-hasta" name="hasta" value="<?= h(textoGet('hasta')) ?>">
        </div>
        <div class="filtros-acciones">
          <button class="btn btn-secundario" type="submit">Aplicar</button>
          <?php if ($hayFiltros): ?>
            <a class="btn btn-fantasma" href="<?= h(urlPanel(['seccion' => 'personas', 'borradas' => $verBorradas ? '1' : null, 'q' => $busqueda])) ?>">Quitar filtros</a>
          <?php endif; ?>
        </div>
      </form>
    </details>

    <?php if ($verBorradas && $totalBorradas > 0): ?>
      <?= cajaAviso('info', 'Siguen en la base de datos, marcadas como borradas, y los celulares las ocultan. '
          . 'Restaurar devuelve la persona a todos los dispositivos en su próxima sincronización.') ?>
    <?php endif; ?>

    <?php if (($busqueda !== '' || $hayFiltros) && $personas !== []): ?>
      <p class="nota-lista" role="status"><?= h(cantidad($totalPersonas, 'resultado', 'resultados')) ?><?= $busqueda !== '' ? ' para «' . h($busqueda) . '»' : ' con los filtros aplicados' ?></p>
    <?php endif; ?>

    <?php if ($personas === []): ?>
      <div class="vacio">
        <?= icono($busqueda !== '' ? 'buscar' : 'vacio', 28) ?>
        <?php if ($busqueda !== '' || $hayFiltros): ?>
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
                  <a class="celda-principal celda-enlace" href="<?= h(urlPanel(['seccion' => 'persona', 'tipo' => (string)$p['tipo_documento'], 'numero' => (string)$p['numero_documento']])) ?>"><?= h($nombreCompleto) ?></a>
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
        $base = $parametrosLista;
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
