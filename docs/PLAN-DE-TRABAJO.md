# Plan de trabajo · ColOffline

**Fecha:** 8 de octubre de 2026 · **Base:** análisis completo del 8 de octubre (código, pruebas y experimentos contra la API real).

Cada tarea tiene un identificador, el qué, el dónde, cómo se comprueba que quedó bien y un tamaño:
**S** ≈ 2 horas · **M** ≈ medio día · **L** ≈ 1–2 días. Las estimaciones son orientativas, para una persona trabajando con Claude.

---

## Estado de ejecución (8 de octubre de 2026)

Todo el trabajo está en la rama `feat/plan-de-trabajo`, con un commit por bloque.

**Verificación al cerrar:**
- 52 pruebas de integración PHP con MySQL;
- 73 pruebas unitarias de Android, más el lint y el ensamblado;
- 46 pruebas de la PWA;
- PHPStan nivel 8 sin errores;
- verificación de la paleta y de los recursos de la PWA.

| Fase | Estado | Notas |
|---|---|---|
| F0 | 🟡 | F0.1 y F0.2 hechas: `main` ya contenía la rama desplegada (PR #36), así que **D1 queda resuelta como `main`**. F0.3–F0.5 (desplegar, retirar `ADMIN_PASSWORD`, copia de la base) requieren acceso a Dokploy |
| F1 | ✅ | Todo. Los experimentos del análisis son ahora pruebas en `tests/php` |
| F2 | 🟡 | Todo el código. F2.20 (pruebas en celulares reales) queda en [PRUEBAS-DE-CAMPO.md](PRUEBAS-DE-CAMPO.md). F2.19 no hacía falta: el reporte sí estaba expuesto |
| F3 | 🟡 | F3.3 hecha (tokens como fuente única). F3.1–F3.2 requieren que lances `/design`; F3.4–F3.7 vienen después |
| F4 | ✅ | Todo, incluida la CSP. El segundo factor (F4.8) se evaluó y no se implementó: ver [PENDIENTES](PENDIENTES.md#7--identidad-única-para-panel-y-aplicación) |
| F5 | 🟡 | F5.1, F5.4 y F5.6 (salud y monitor) hechas. F5.5: prueba de migraciones escrita y compilada, falta un dispositivo para ejecutarla. F5.2 pospuesta con motivo en [PENDIENTES](PENDIENTES.md#4--cifrado-de-datos-en-el-teléfono). F5.3: ramas limpiadas; las actualizaciones de Dependabot quedan por revisar |

**Fallos nuevos encontrados durante la ejecución:**
- **PWA:** comparaba los rechazos del servidor con el id de la cola y no con el de la encuesta. Ningún rechazo se reconocía nunca: se daba por enviado.
- **Android:** `EliminarPersonaUseCase` no encola el borrado. Hoy no tiene pantalla; queda anotado en [PENDIENTES](PENDIENTES.md#6--android-no-puede-borrar-personas).

## Decisiones pendientes

Bloquean tareas concretas. Cada una trae la opción recomendada, que es la que asume este plan.

| # | Decisión | Recomendación | Bloquea |
|---|---|---|---|
| D1 | ¿Qué rama despliega Dokploy? | `main`. Mergear ahí `docs/documentacion-completa` y el rediseño del panel por PR, y apuntar Dokploy a `main` | F0.2 |
| D2 | ¿El panel puede **editar** personas? | Sí, con auditoría y sellando las marcas igual que el borrado | F4.4 |
| D3 | ¿Cada encuestador descarga **todas** las personas? | No: solo las de sus municipios asignados. Hoy cada celular lleva la base completa con datos de salud, sin cifrar | F5.1 |
| D4 | Dirección visual del rediseño | Se elige en el lienzo de Claude Design | F3.2 |

## Resumen

| Fase | Objetivo | Tamaño | Depende de |
|---|---|---|---|
| **F0** | Llevar a producción lo ya probado y ordenar las ramas | ½ día | D1 |
| **F1** | Que la sincronización no pierda ni bloquee datos (servidor) | 3 días | F0 |
| **F2** | App de campo fiable sin conexión (PWA y Android) | 4 días | F1.1 |
| **F3** | Rediseño visual con Claude Design | 1 día de diseño + 5 de implementación | El diseño puede empezar ya |
| **F4** | Panel admin completo | 5 días | F1.4, F1.5, F3.2 |
| **F5** | Privacidad, calidad y operación | 3 días | D3 |

**Total orientativo:** 21 días de trabajo, unas 4–5 semanas.

**Orden.** F0 primero: producción corre hoy una versión del panel con 12 fallos ya corregidos y probados. Después F1, porque el fallo más grave (la descarga que pierde registros) está en el servidor y los clientes dependen de su arreglo. El **diseño** (F3.1–F3.3) no toca código y puede ir en paralelo desde el primer día. Así las pantallas nuevas de F4 se construyen una sola vez, ya con el diseño final.

---

## F0 · Preparación y despliegue seguro

| ID | Tarea | Comprobación | Tamaño |
|---|---|---|---|
| F0.1 | Commit del rediseño del panel (14 sep, sin commitear) en `feat/panel-admin-rediseno` y PR | CI verde; las 30 comprobaciones del panel pasan | S |
| F0.2 | Unificar ramas según D1: `main` recibe roles, documentación, primer rediseño y F0.1 | `git log main` contiene `0db2b30`, `d9c8c6b`, `0e26d9b` y F0.1 | S |
| F0.3 | Desplegar y verificar en producción con `curl` | El panel sirve `admin.css`, ya no carga Google Fonts y pide documento | S |
| F0.4 | Quitar `ADMIN_PASSWORD` del entorno (producción ya tiene cuenta admin) | Se sigue entrando con la cuenta; sin la variable el panel no cambia | S |
| F0.5 | Copia de la base antes de F1 (`mysqldump`) | Archivo restaurable verificado en una base local | S |

## F1 · Integridad de la sincronización (servidor)

Todos estos fallos se **demostraron** contra la API real en una base desechable.

| ID | Tarea | Comprobación | Tamaño |
|---|---|---|---|
| F1.1 | **Crítico · Marca de agua.** Ver el detalle debajo de la tabla | 3 personas con el mismo sello en páginas de 2 → llegan las 3; lote de 500 en páginas de 200 → llegan 500 | L |
| F1.2 | **Validación de contenido** en `sync.php` como rechazo por fila, nunca 500: municipio existente, estrato 1–6, correo válido, fecha de nacimiento entre 1900 y hoy, nombres sin dígitos | Municipio inexistente → la fila sale en `rechazadas` y la válida del mismo lote se guarda; estrato 9 y correo inválido → rechazados | M |
| F1.3 | **Relojes adelantados:** limitar `updated_at` a la hora del servidor + 5 min, y registrar el recorte en el log | Edición con fecha a un año vista → la corrección real posterior gana | S |
| F1.4 | **Guardar los rechazos** en la tabla `sync_rechazos` (encuesta, documento, motivo, celular, encuestador, fecha), autocreada como `server_updated_at` | Cada rechazo de `sync.php` deja su fila | S |
| F1.5 | **Registro de celulares:** tabla `dispositivos` (id, encuestador, plataforma, versión, última sincronización), alimentada por las cabeceras `X-Device-Id` y `X-App-Version` | Tras sincronizar, el celular aparece con su hora | M |
| F1.6 | **Pruebas de integración PHP en CI:** PHPUnit + servicio MySQL 8. Convertir en pruebas los experimentos del análisis y las 30 comprobaciones del panel | El job `php` ejecuta pruebas reales y falla si vuelve el fallo de F1.1 | L |
| F1.7 | **Endpoint de salud** `/api/health.php`: base de datos y versión | 200 con la base arriba, 503 sin ella | S |

**Detalle de F1.1.** `sync.php` sella todo el lote con el mismo milisegundo y `cambios.php` pide lo posterior a la marca. Si una página corta un grupo con el mismo sello, el resto no baja nunca.

La solución es un cursor compuesto `(server_updated_at, tipo_documento, numero_documento)` en `cambios.php`, más una ventana de solape de 2 minutos para las transacciones que confirman fuera de orden. La mezcla en los clientes ya tolera recibir el mismo registro dos veces.

Durante la transición, `cambios.php` debe seguir aceptando la marca numérica: hay PWA en caché y APK instaladas que tardarán en actualizarse.

## F2 · App de campo: offline y sincronización

**Respuesta a «¿sincroniza al detectar red?»:**
- **Android sí**, incluso con la app cerrada (WorkManager).
- **La PWA solo con la app abierta.**

### PWA

| ID | Tarea | Comprobación | Tamaño |
|---|---|---|---|
| F2.1 | **Subir por lotes de 100.** Hoy manda toda la cola en una petición: con más de 500 pendientes el servidor responde 413 y la PWA se atasca para siempre | 1.200 pendientes simulados → 12 lotes; un lote fallido no bloquea los demás | M |
| F2.2 | Usar el cursor compuesto de F1.1 | Prueba con páginas que cortan un lote | S |
| F2.3 | **Reintentos:** espera creciente (30 s → 15 min) mientras la app esté abierta, y sincronizar también al volver a la pestaña | Con la red cortándose cada 20 s, la cola sube sin tocar nada | M |
| F2.4 | **Sincronización en segundo plano real:** el service worker hace el envío él mismo. El token pasa a IndexedDB, porque el service worker no puede leer `localStorage`. En iOS no existe; queda documentado | En Chrome Android, con la app cerrada, la cola sube al volver la señal | L |
| F2.5 | **Errores visibles:** sesión vencida → aviso fijo «Conéctate e inicia sesión para enviar N registros», sin borrar nada | Token revocado desde el panel → aparece el aviso; tras entrar, sube la cola | S |
| F2.6 | **Validación del formulario** con las mismas reglas que F1.2. Hoy tiene `novalidate`: guarda documentos de 3 dígitos que luego se rechazan para siempre | Pruebas unitarias de las reglas; error visible por campo | M |
| F2.7 | **Rechazados a la vista:** contador, motivo y acción «Corregir» que abre la persona | Un rechazo se ve en la pantalla de sincronización con su motivo | S |
| F2.8 | Limpiar de la cola lo enviado hace más de 30 días | La cola no crece sin límite | S |
| F2.9 | «Recordar sesión» hoy no hace nada: implementarlo o quitarlo | Su comportamiento coincide con lo que dice | S |
| F2.10 | Enviar las cabeceras de F1.5 | El celular aparece en el monitor | S |

### Android

| ID | Tarea | Comprobación | Tamaño |
|---|---|---|---|
| F2.11 | **Crítico · Identificador real del dispositivo.** Hoy es `"DEVICE_ID_LOCAL"` fijo (`FormularioEncuestaViewModel.kt:255`) y el servidor ve todos los Android como uno. Generar un UUID persistente en el primer arranque | Dos teléfonos → dos `device_id` distintos en el servidor | S |
| F2.12 | **Sesión vencida o revocada:** al recibir un 401, marcar «hay que volver a entrar», mostrar un aviso y llevar al login sin borrar la cola. Hoy el envío falla en silencio cada 15 min | Token revocado → aviso; tras entrar, la cola sube | M |
| F2.13 | **Login sin conexión real:** hoy solo entra la cuenta demo, escrita en el código. Guardar un hash con sal (PBKDF2) tras cada login en línea; dejar la cuenta demo solo en *debug* | Una cuenta creada en el panel entra sin red después de un login en línea | M |
| F2.14 | **Copias de seguridad:** las reglas excluyen `encuestas_prefs.xml`, que no existe; la sesión vive en `coloffline_session`. Excluir sesión y base local de la copia y de la transferencia entre equipos | Revisión de `backup_rules.xml` y `data_extraction_rules.xml` | S |
| F2.15 | No cortar un envío en curso: política `KEEP` en lugar de `REPLACE`, y un candado para que el envío periódico y el inmediato no se solapen | Guardar durante un envío no lo reinicia | S |
| F2.16 | Los rechazados no cuentan como pendientes en la lista; la pantalla de sincronización muestra el motivo | Una persona rechazada deja de figurar como «Pendiente» | S |
| F2.17 | Mensajes de resultado reales (enviados, recibidos, rechazados) en lugar de «encolada o completada» | Texto coherente con lo ocurrido | S |
| F2.18 | Usar el cursor compuesto de F1.1 | Prueba unitaria del repositorio | S |
| F2.19 | Exponer o retirar `GenerarReporteUseCase`, que hoy es código muerto | Decidido y hecho | S |

### Prueba en celulares reales (las dos apps)

| ID | Tarea | Comprobación | Tamaño |
|---|---|---|---|
| F2.20 | Lista de pruebas de campo: modo avión → registrar 20 personas → quitar el modo avión → medir cuánto tarda en subir. Repetir con la app cerrada, tras reiniciar el teléfono y con ahorro de batería activado (Xiaomi y Samsung) | Tabla de resultados en `docs/`, con tiempos por escenario | M |

## F3 · Rediseño visual con Claude Design

El brief está listo en [BRIEF-REDISENO.md](BRIEF-REDISENO.md). Claude Design solo se puede lanzar a mano: lo ejecutas tú con `/design`.

| ID | Tarea | Comprobación | Tamaño |
|---|---|---|---|
| F3.1 | Ejecutar `/design` con el brief | Lienzo publicado con las cuatro filas: direcciones, app de campo, panel, sistema | S |
| F3.2 | Elegir dirección (D4) e iterar en el editor visual del lienzo hasta aprobarlo | Lienzo aprobado | M |
| F3.3 | **Tokens como fuente única:** `design/tokens.json`, más un script que genere el CSS de la PWA y del panel y el `Theme.kt` de Android. Cierra el pendiente 9 (paleta duplicada a mano) | Cambiar un color en el JSON cambia las tres superficies | M |
| F3.4 | Implementar en la PWA: acceso, inicio, formulario, ficha y sincronización. Subir `CACHE` en `sw.js` y pasar `check-pwa-assets` | Capturas a 390 px iguales al lienzo | L |
| F3.5 | Implementar en Android (Compose), en claro y oscuro | Capturas del emulador iguales al lienzo | L |
| F3.6 | Implementar en el panel, sobre el rediseño de F0. Las pantallas nuevas de F4 nacen ya con este diseño | Capturas a 1440 y 390 px | M |
| F3.7 | Revisión de accesibilidad: contraste AA, objetivos de 48 px, lector de pantalla, estado nunca solo por color | Informe sin fallos graves | M |

Las tareas F3.4 y F3.5 van **después** de los cambios funcionales de F2 en cada pantalla, para no rehacer la misma pantalla dos veces.

## F4 · Panel de administración completo

| ID | Tarea | Comprobación | Tamaño |
|---|---|---|---|
| F4.1 | **Ficha de persona** con todos los campos (hoy fecha de nacimiento, teléfono, correo, dirección y ocupación solo salen en el CSV) | Abrir una persona desde la tabla | M |
| F4.2 | **Historial de encuestas** de cada persona: fecha, acción, encuestador y celular. La tabla `encuestas` existe y no se muestra | La línea de tiempo coincide con la base | M |
| F4.3 | **Filtros** por departamento/municipio, encuestador, fechas y estado, y **exportar lo filtrado** | El CSV contiene exactamente lo que muestra la tabla | M |
| F4.4 | **Editar persona** (D2), con la validación de F1.2 y sellando las marcas para que llegue a los celulares | La edición aparece en un celular tras sincronizar | M |
| F4.5 | **Auditoría:** tabla `auditoria_admin` (quién, qué, cuándo, antes y después) y su vista | Borrar, restaurar, editar o cambiar una cuenta deja rastro | M |
| F4.6 | **Cuentas:** sesiones abiertas en celulares (listar y cerrar), desbloquear intentos fallidos, último acceso | Cerrar una sesión → ese celular recibe 401 | M |
| F4.7 | **Monitor de sincronización:** celulares (F1.5), rechazos (F1.4) y alerta de celulares sin sincronizar desde hace más de 3 días | Un rechazo provocado aparece en el monitor | L |
| F4.8 | **Seguridad:** cabecera CSP; evaluar un segundo factor solo para el panel (pendiente 5) | CSP activa sin romper la página | M |
| F4.9 | Pruebas automáticas del panel (login, roles, borrar, restaurar, exportar, editar) dentro de F1.6 | En el CI | M |

## F5 · Privacidad, calidad y operación

| ID | Tarea | Comprobación | Tamaño |
|---|---|---|---|
| F5.1 | **Alcance por encuestador** (D3): asignar municipios a cada cuenta; `cambios.php` solo entrega esos | Un encuestador de Cauca no descarga personas de Chocó | L |
| F5.2 | **Cifrado en el teléfono:** token en almacenamiento cifrado y base Room cifrada (SQLCipher); evaluar qué es viable en la PWA | Inspección del almacenamiento del dispositivo | M |
| F5.3 | **Repositorio:** borrar las ramas ya mergeadas y atender Dependabot (Retrofit 3 es un cambio mayor que hay que probar; acciones v7) | Sin ramas obsoletas; dependencias al día con CI verde | M |
| F5.4 | **Documentación:** `PENDIENTES.md` (hoy dice «Panel ✅ Completo»), `API.md` (cursor compuesto y nuevos endpoints), `ARQUITECTURA.md` (sincronización) | Documentos coherentes con el código | M |
| F5.5 | **Pruebas instrumentadas mínimas en Android:** migraciones de Room y worker (pendiente 2) | Corren en el emulador | M |
| F5.6 | **Observabilidad:** errores por día en el panel, a partir de F1.7 | Un 500 provocado aparece contado | S |

---

## Calendario sugerido

| Semana | Trabajo | Al cerrar la semana |
|---|---|---|
| 1 | F0 completa · F1 · lanzar `/design` y elegir dirección (F3.1–F3.2) | Desplegar F0 y F1; verificar en producción |
| 2 | F2 (PWA y Android) · tokens (F3.3) | Nueva APK y PWA publicadas; pruebas de campo (F2.20) |
| 3 | Diseño en la app (F3.4–F3.5) · F4.1–F4.3 | Desplegar |
| 4 | F4.4–F4.9 · diseño en el panel (F3.6) · accesibilidad (F3.7) | Desplegar |
| 5 | F5 | Documentación al día |

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Clientes viejos (PWA en caché, APK instaladas) siguen usando la marca numérica | `cambios.php` acepta ambas durante la transición; subir `CACHE` en la PWA; plan para reinstalar la APK, que no está en una tienda |
| Registros históricos enviados con `DEVICE_ID_LOCAL` | No se pueden atribuir. El monitor los agrupa como «Android anterior a la versión X» |
| Fabricantes que cierran WorkManager para ahorrar batería | La prueba F2.20 lo mide; guía para el encuestador sobre cómo excluir la app del ahorro de batería |
| Retrofit 3 rompe la compilación | Rama propia y CI antes de mergear |
| El rediseño se come el calendario | El diseño corre en paralelo y se implementa pantalla por pantalla junto a sus cambios funcionales |

## Línea base al empezar

- **PWA:** 37 pruebas en verde, en dos husos horarios.
- **Android:** 68 pruebas en verde.
- **PHP:** PHPStan nivel 8 sin errores; sin pruebas de PHP (las añade F1.6).
- **Panel rediseñado:** 30 comprobaciones de integración en verde, pero sin commitear.

---

**Documentos relacionados:** [Brief de rediseño](BRIEF-REDISENO.md) · [Pendientes](PENDIENTES.md) · [Arquitectura](ARQUITECTURA.md) · [API](API.md) · [Historias de usuario](HISTORIAS-DE-USUARIO.md)
