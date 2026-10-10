# Historias de usuario

Catálogo funcional del sistema. Cada historia incluye criterios de aceptación verificables y **trazabilidad**: dónde está implementada y qué prueba la cubre.

## Actores

| Actor | Descripción |
|---|---|
| **Encuestador** | Personal de campo. Trabaja mayoritariamente sin conexión. Usa la app Android o la PWA. |
| **Administrador** | Coordinador. Consulta datos, exporta y gestiona cuentas desde el panel web. Tiene también rol de encuestador. |
| **Sistema** | Procesos automáticos: sincronización en segundo plano, resolución de conflictos, migraciones. |

## Estado

| Símbolo | Significado |
|---|---|
| ✅ | Implementada y verificada con pruebas automatizadas |
| 🟡 | Implementada, verificada manualmente (sin prueba automatizada) |
| ⏳ | Pendiente — ver [PENDIENTES.md](PENDIENTES.md) |

## Resumen

| Épica | Historias | ✅ | 🟡 | ⏳ |
|---|---:|---:|---:|---:|
| E1 · Acceso y sesión | 6 | 6 | 0 | 0 |
| E2 · Recolección de datos | 6 | 6 | 0 | 0 |
| E3 · Sincronización | 8 | 7 | 1 | 0 |
| E4 · Administración | 7 | 6 | 1 | 0 |
| E5 · Calidad y operación | 5 | 5 | 0 | 0 |
| E6 · Futuro | 4 | 0 | 1 | 3 |
| **Total** | **36** | **30** | **3** | **3** |

*Revisado el 9 de octubre de 2026.* Las pruebas que se citan están descritas en [PRUEBAS.md](PRUEBAS.md); «E2E n» es la prueba número *n* de punta a punta.

---

# E1 · Acceso y sesión

## HU-01 · Iniciar sesión con conexión ✅

> **Como** encuestador
> **quiero** entrar con mi número de documento y contraseña
> **para** que mi trabajo quede atribuido a mi cuenta.

**Criterios de aceptación**

1. Dado un documento y contraseña correctos, cuando envío el formulario, entonces recibo un token válido por 30 días y accedo a la aplicación.
2. Dadas credenciales incorrectas, entonces veo *"Documento o contraseña incorrectos"* — el mismo mensaje exista o no la cuenta, para no revelar qué documentos están registrados.
3. Dada una cuenta desactivada, entonces no puedo entrar aunque la contraseña sea correcta.
4. Dado un documento con caracteres no permitidos, entonces recibo `400` sin llegar a comprobar la contraseña.
5. La contraseña se verifica con **bcrypt**; nunca se almacena en claro.

**Implementación** · [`api/auth/login.php`](../api/auth/login.php) · [`LoginUseCase.kt`](../app/src/main/java/com/minsalud/encuestas/domain/usecase/LoginUseCase.kt) · [`pwa/js/screens/login.js`](../pwa/js/screens/login.js)
**Pruebas** · `AuthRepositoryImplTest` · `session.test.mjs`

---

## HU-02 · Iniciar sesión sin conexión ✅

> **Como** encuestador en zona rural
> **quiero** entrar aunque no haya señal
> **para** poder trabajar al llegar a terreno.

**Criterios de aceptación**

1. Dado que ya inicié sesión con conexión en este dispositivo, cuando entro sin red con las mismas credenciales, entonces accedo normalmente.
2. Dado un dispositivo donde **nunca** inicié sesión, entonces veo un mensaje que explica que hace falta conexión la primera vez — no *"contraseña incorrecta"*, que sería falso y dejaría al usuario sin saber qué hacer.
3. El token guardado sigue sirviendo para sincronizar cuando vuelva la señal.

**Nota de diseño.** La vigencia del token es larga (30 días) precisamente por esto: se emite en la oficina y viaja con el dispositivo.

**Implementación** · [`pwa/js/session.js`](../pwa/js/session.js) · `SessionManager.kt`
**Pruebas** · `session.test.mjs`

---

## HU-03 · Cerrar sesión ✅

> **Como** encuestador
> **quiero** cerrar sesión
> **para** que nadie use mi cuenta si presto o pierdo el dispositivo.

**Criterios de aceptación**

1. Al cerrar sesión, el token queda **revocado en el servidor** (se borra de `sesiones`), no solo olvidado en el dispositivo.
2. Se limpian las credenciales locales.
3. Si el servidor no responde, la sesión local se cierra igualmente — quedarse dentro por un fallo de red sería peor.

**Motivación.** Antes existía `clearSession()` pero **no se llamaba desde ningún sitio**: la app pedía "iniciar sesión con conexión" sin ofrecer forma de hacerlo.

**Implementación** · [`api/auth/logout.php`](../api/auth/logout.php) · `LogoutUseCase.kt`
**Pruebas** · `AuthRepositoryImplTest`

---

## HU-04 · Protección contra fuerza bruta ✅

> **Como** responsable del sistema
> **quiero** que se limiten los intentos fallidos
> **para** que no se puedan adivinar contraseñas.

**Criterios de aceptación**

1. Tras **5 intentos fallidos**, la cuenta queda bloqueada **15 minutos**.
2. La API responde `429` con cabecera `Retry-After`.
3. Un inicio de sesión correcto borra el historial de fallos.
4. Los intentos se registran **exista o no el documento**, para que el bloqueo no delate qué cuentas son reales.
5. El panel de administración aplica el mismo límite; estando bloqueado, **incluso la contraseña correcta se rechaza**.

**Nota de diseño.** Se cuenta por documento y no por IP: detrás del proxy inverso todas las peticiones comparten IP, y limitar por ella bloquearía a toda la plantilla a la vez.

**Implementación** · [`api/rate_limit.php`](../api/rate_limit.php)
**Verificación** · Manual y reproducible: intentos 1–5 rechazados con mensaje normal, el 6.º bloqueado, y la contraseña correcta también rechazada durante el bloqueo.

---

## HU-05 · Acceso por rol ✅

> **Como** administrador
> **quiero** entrar al panel con mi propia cuenta
> **para** que cada acción administrativa quede con autor.

**Criterios de aceptación**

1. El panel exige documento + contraseña de una cuenta con rol `admin` **activa**.
2. Una cuenta con rol `encuestador` no puede entrar al panel, aunque sus credenciales sean válidas.
3. Mientras no exista ninguna cuenta admin activa, se acepta `ADMIN_PASSWORD` **solo** para crear la primera; después deja de aceptarse automáticamente.
4. Al crear el primer administrador se cierra la sesión abierta con la contraseña compartida.
5. No se puede quitar el rol ni desactivar la **única** cuenta de administrador activa.
6. La respuesta del login de la API incluye el rol.

**Implementación** · [`api/admin/index.php`](../api/admin/index.php) · `buscarAdminPorDocumento` y `esUltimoAdminActivo` en [`consultas.php`](../api/admin/consultas.php)
**Pruebas** · `PanelAdminTest`: `testUnEncuestadorNoEntraAlPanel`, `testNoSePuedeQuitarElRolAlUltimoAdminActivo`, `testEnArranqueSeEditaUnEncuestadorYElPrimerAdminCierraTodasLasSesiones` · E2E 1 y 3

---

## HU-36 · Recuperar la contraseña ✅

> **Como** encuestador o administrador
> **quiero** cambiar mi contraseña si la olvido
> **para** no depender de que alguien esté disponible para dármela.

**Criterios de aceptación**

1. Desde el login de la app (PWA y Android) y del panel hay un enlace **¿Olvidaste tu contraseña?**
2. Con el documento, el servidor envía un **código de 6 dígitos** al correo de la cuenta; vence en 15 minutos.
3. La respuesta es la misma exista o no la cuenta: no sirve para averiguar documentos registrados.
4. Con el código y una contraseña nueva de al menos 10 caracteres se cambia la contraseña; el código sirve una sola vez y admite 5 intentos.
5. Al cambiarla se cierran las sesiones de la cuenta en todos los celulares y llega un correo avisando del cambio.
6. Hay límites: 5 pedidos de código por documento cada 15 minutos, y 5 códigos equivocados bloquean 15 minutos.
7. El administrador registra el correo de cada cuenta y puede **enviar un correo de prueba** para comprobar la configuración. Sin correo, cambia la contraseña desde *Cuentas*.

**Implementación** · [`recuperar.php`](../api/auth/recuperar.php) · [`restablecer.php`](../api/auth/restablecer.php) · [`correo.php`](../api/correo.php) · [`recuperar.js`](../pwa/js/screens/recuperar.js) · `RecuperarScreen.kt` · `RecuperarViewModel.kt`
**Pruebas** · `RecuperacionTest` (PHP, 11, con servidor SMTP falso) · `AuthRepositoryImplTest` (Android) · E2E 8
**Operación** · Configuración de Gmail paso a paso en [DESPLIEGUE.md](DESPLIEGUE.md#1--activar-olvidaste-tu-contraseña)

---

# E2 · Recolección de datos

## HU-06 · Registrar una persona sin conexión ✅

> **Como** encuestador
> **quiero** registrar los datos de una persona sin red
> **para** no depender de la cobertura para trabajar.

**Criterios de aceptación**

1. El formulario se guarda **sin esperar a la red** y muestra confirmación inmediata.
2. La persona y la encuesta se persisten en **una sola transacción atómica** junto con el evento en la cola de salida.
3. Si la aplicación se cierra inesperadamente después de guardar, el registro y su pendiente siguen ahí al reabrir.
4. Se capturan: tipo y número de documento, nombres, apellidos, fecha de nacimiento, teléfono, correo, dirección, vereda, EPS, ocupación, estrato y municipio.

**Implementación** · [`GuardarRegistroCompletoUseCase.kt`](../app/src/main/java/com/minsalud/encuestas/domain/usecase/GuardarRegistroCompletoUseCase.kt) · [`pwa/js/screens/formulario-encuesta.js`](../pwa/js/screens/formulario-encuesta.js)
**Pruebas** · `GuardarRegistroCompletoUseCaseTest` · `RegistrarEncuestaUseCaseTest`

---

## HU-07 · Validar los datos capturados ✅

> **Como** coordinador
> **quiero** que no entren datos imposibles
> **para** que la base sirva para algo.

**Criterios de aceptación**

1. Se admiten 7 tipos de documento, cada uno con su formato: **solo dígitos** en CC (6–10), TI y RC (10–11), CE (6–10), NIT (9–10) y PE (6–15); el pasaporte (PP, 6–12) es el único que admite letras.
2. Nombres y apellidos son obligatorios, de 2 a 60 caracteres, con letras (tildes y ñ incluidas), espacios, guion o apóstrofo.
3. El teléfono, si se informa, tiene 10 dígitos: celular que empieza por 3 o fijo que empieza por 60.
4. El correo, si se informa, tiene formato válido.
5. Dirección, vereda, EPS y ocupación, si se informan, tienen un largo mínimo y solo caracteres razonables.
6. La fecha de nacimiento está entre 1900 y hoy; el selector de fecha no deja elegir días futuros. El estrato va de 1 a 6.
7. **Lo que no corresponde a un campo no se puede escribir** (letras en el teléfono, números en el nombre, más caracteres que el máximo), y el teclado cambia según el tipo de documento.
8. Al guardar, cada campo con error muestra su motivo debajo, y el error se borra en cuanto se corrige.
9. **El servidor valida de nuevo todo**, con las mismas reglas. La validación del cliente no protege nada: cualquiera con un token y `curl` se la salta.

**Motivación.** En producción llegó a existir una persona con documento `"hola"`, y en una prueba en celular se guardó una cédula `sdscf1ds5ds1c` con el apellido `Velasquez.,s65`.

**Implementación** · [`validacion.js`](../pwa/js/validacion.js) · [`Validaciones.kt`](../app/src/main/java/com/minsalud/encuestas/domain/validation/Validaciones.kt) · [`validacion.php`](../api/personas/validacion.php)
**Pruebas** · `validacion.test.mjs` · `ValidacionesTest` (Android) · `ValidacionTest` (PHP) · `SincronizacionTest::testDatosInvalidosSeRechazanPorFila` · `paridad.test.mjs` · E2E 5

---

## HU-08 · Editar una persona registrada ✅

> **Como** encuestador
> **quiero** corregir los datos de alguien ya registrado
> **para** mantener la información al día.

**Criterios de aceptación**

1. El documento no es editable: es la clave de identidad del registro.
2. La edición genera una encuesta con acción `ACTUALIZACION`, de modo que el historial conserva ambas visitas.
3. Se actualiza `updated_at`, que es lo que resolverá el conflicto si otro dispositivo editó a la misma persona.

**Corrección notable.** La fecha de nacimiento se corría **un día en cada edición**: se guardaba a medianoche UTC pero se leía con métodos de zona local. El desfase se acumulaba. Se corrigió leyendo y escribiendo en UTC de forma consistente para fechas de calendario, mientras que las marcas de sincronización siguen en hora local por ser instantes reales.

**Implementación** · [`pwa/js/utils.js`](../pwa/js/utils.js) · `FormularioEncuestaViewModel.kt`
**Pruebas** · `utils.test.mjs` (se ejecuta en `America/Bogota` **y** `Asia/Tokyo`, porque el fallo solo aparecía con desfase negativo respecto a UTC)

---

## HU-09 · Eliminar una persona ✅

> **Como** encuestador
> **quiero** eliminar un registro creado por error
> **para** que no contamine las cifras.

**Criterios de aceptación**

1. El borrado es **suave**: se marca `deleted_at`, no se elimina la fila.
2. La persona desaparece de listas y conteos en todos los clientes.
3. El borrado se propaga a los demás dispositivos en su siguiente sincronización.

**Por qué suave.** La descarga entrega las filas borradas a propósito, para que los otros dispositivos se enteren. Un borrado real no dejaría nada que enviar y cada teléfono que ya la descargó se la quedaría para siempre.

**Implementación** · [`EliminarPersonaUseCase.kt`](../app/src/main/java/com/minsalud/encuestas/domain/usecase/EliminarPersonaUseCase.kt)
**Pruebas** · `EliminarPersonaUseCaseTest`

---

## HU-10 · Consultar y buscar personas ✅

> **Como** encuestador
> **quiero** ver a quién he registrado y buscarlo
> **para** no duplicar visitas.

**Criterios de aceptación**

1. La lista muestra las personas del dispositivo ordenadas por actualización reciente.
2. Se puede buscar por nombre, apellido o documento.
3. Las personas con sincronización pendiente se distinguen visualmente.
4. Con miles de registros la interfaz no se bloquea: se pintan **de a 50** y se amplía al acercarse al final.

**Nota de rendimiento.** Se usa delegación de eventos: dos escuchadores en total en lugar de dos por tarjeta.

**Implementación** · [`lista-personas.js`](../pwa/js/screens/lista-personas.js) · `ListaPersonasScreen.kt`
**Pruebas** · `ListaPersonasViewModelTest`

---

## HU-11 · Buscar el municipio y la EPS en los catálogos oficiales ✅

> **Como** encuestador
> **quiero** encontrar el municipio y la EPS escribiendo unas letras
> **para** que el dato sea homogéneo y no tenga que bajar por una lista de mil opciones.

**Criterios de aceptación**

1. El catálogo trae los **1.122 municipios** DIVIPOLA/DANE en los **33 departamentos**, con sus tildes.
2. Un solo campo busca por municipio o por departamento, sin tildes ni mayúsculas: «popa» → Popayán, «cauca» → los del Cauca con la capital primero, «cali» → Santiago de Cali.
3. Sin escribir nada se ofrecen las ciudades principales.
4. El municipio se guarda por código; si se escribe algo sin elegirlo de la lista, el formulario lo señala.
5. La EPS se busca en el catálogo de las que operan en Colombia (y regímenes especiales) o se escribe libre.
6. Todo funciona **sin conexión**: el catálogo viaja dentro de la app.
7. Cuando el catálogo cambia, se actualiza solo en los teléfonos y en el servidor.

**Motivación.** Un teléfono se quedó con una lista vieja de 3 departamentos y tildes dañadas, porque el catálogo se descargaba una sola vez y nunca se actualizaba.

**Implementación** · [`database/catalogos/`](../database/catalogos/) · [`scripts/catalogos.mjs`](../scripts/catalogos.mjs) · [`catalogos.js`](../pwa/js/catalogos.js) · [`buscador.js`](../pwa/js/componentes/buscador.js) · `BuscadorCatalogo.kt` · `asegurarCatalogoMunicipios` en [`esquema.php`](../api/esquema.php)
**Pruebas** · `catalogos.test.mjs` · `BuscadorCatalogoTest` · `CatalogoTest` (PHP) · `tildes.test.mjs` · E2E 6

---

# E3 · Sincronización

## HU-12 · Sincronizar manualmente ✅

> **Como** encuestador
> **quiero** forzar la sincronización al llegar donde hay señal
> **para** no esperar a que ocurra sola.

**Criterios de aceptación**

1. Un botón visible lanza la sincronización.
2. Se informa del resultado: cuántos se enviaron, cuántos se recibieron y cuántos fueron rechazados.
3. La interfaz refleja el nuevo estado **sin salir de la pantalla**.

**Corrección notable.** Antes había que abandonar la pantalla y volver para ver el resultado.

**Implementación** · [`pwa/js/sync.js`](../pwa/js/sync.js) · `SyncViewModel.kt`

---

## HU-13 · Sincronización automática en segundo plano ✅

> **Como** encuestador
> **quiero** que los datos suban solos cuando haya red
> **para** no tener que acordarme.

**Criterios de aceptación**

1. `WorkManager` reintenta con **backoff exponencial** cuando hay conectividad.
2. La sincronización sobrevive al cierre de la aplicación.
3. Un fallo de red deja la cola intacta para el siguiente intento.

**Implementación** · [`SyncWorker.kt`](../app/src/main/java/com/minsalud/encuestas/worker/SyncWorker.kt)
**Pruebas** · `SincronizarPendientesUseCaseTest`

---

## HU-14 · Envío por lotes ✅

> **Como** encuestador que vuelve del campo con 200 encuestas
> **quiero** que se envíen agrupadas
> **para** que no tarde una eternidad sobre una conexión mala.

**Criterios de aceptación**

1. Las peticiones crecen con el número de **lotes**, no de registros: 250 registros son **3 peticiones**, no 250.
2. El tamaño de lote es 100 (el servidor admite hasta 500).
3. Una persona editada varias veces viaja **una sola vez**, en su versión más reciente.

**Motivación.** Android enviaba una petición HTTP por registro, cada una con su negociación TLS, sobre la conexión intermitente que justifica que la app sea offline-first.

**Implementación** · [`SyncRepositoryImpl.kt`](../app/src/main/java/com/minsalud/encuestas/data/repository/SyncRepositoryImpl.kt)
**Pruebas** · `SyncRepositoryImplTest` — *"250 registros se envían en 3 lotes, no en 250 peticiones"*

---

## HU-15 · Resolución automática de conflictos ✅

> **Como** coordinador
> **quiero** que dos encuestadores puedan editar a la misma persona
> **para** no tener que arbitrar diferencias a mano.

**Criterios de aceptación**

1. El servidor conserva la versión con `updated_at` mayor (**Last-Write-Wins**).
2. Si el registro entrante es más antiguo, se ignora sin error.
3. Todo el lote se procesa en **una transacción**: o entra completo o no entra nada.

**Implementación** · [`sync.php`](../api/personas/sync.php)

---

## HU-16 · Recibir los registros de otros dispositivos ✅

> **Como** encuestador
> **quiero** ver también lo que registraron mis compañeros
> **para** no volver a visitar a alguien ya encuestado.

**Criterios de aceptación**

1. La sincronización **sube primero y baja después**.
2. La descarga es incremental: solo lo cambiado desde la última marca de agua.
3. Se pagina hasta agotar los cambios, con tope de 10 páginas por sincronización.
4. **Los cambios locales sin enviar nunca se sobrescriben.**
5. Un tipo de documento desconocido se salta sin tumbar la página entera.

**Motivación.** El síntoma reportado fue *"solo veo 3 encuestados y ya he encuestado más"*. No era un fallo de la lista: la sincronización era **solo de subida**, así que cada dispositivo era una isla.

**Implementación** · [`cambios.php`](../api/personas/cambios.php) · [`DecisionMezcla.kt`](../app/src/main/java/com/minsalud/encuestas/domain/sync/DecisionMezcla.kt) · `decidirMezcla` en [`db.js`](../pwa/js/db.js)
**Pruebas** · `DecisionMezclaTest` (6 casos) · `mezcla.test.mjs` · `SyncRepositoryImplTest`

---

## HU-17 · Confirmación fiable de lo enviado ✅

> **Como** encuestador
> **quiero** que solo se marque como enviado lo que de verdad llegó
> **para** no creer que un dato está a salvo cuando no lo está.

**Criterios de aceptación**

1. Solo se marca `SENT` lo que aparece en `processed_encuestas`.
2. Lo no confirmado vuelve a la cola.
3. Un `4xx` marca error sin reintentar en bucle; un `5xx` o un fallo de red sí se reintenta.

**Implementación** · [`SyncRepositoryImpl.kt`](../app/src/main/java/com/minsalud/encuestas/data/repository/SyncRepositoryImpl.kt)
**Pruebas** · `SyncRepositoryImplTest` — *"solo marca enviado lo que el servidor confirma"*

---

## HU-18 · Un registro inválido no bloquea la cola ✅

> **Como** encuestador
> **quiero** que un registro defectuoso no impida enviar los demás
> **para** no quedarme sin poder sincronizar nunca más.

**Criterios de aceptación**

1. El servidor **descarta la fila inválida y continúa** con el resto del lote.
2. La respuesta incluye `rechazadas` con el motivo de cada una.
3. El cliente las marca en estado **terminal**: no se reintentan.
4. Un lote sin rechazos se comporta igual que antes.
5. Un servidor que aún no envíe el campo se trata como "todo aceptado" (compatibilidad durante el despliegue).

**Motivación.** Las validaciones llamaban a una función que hace `exit`: una fila mala devolvía `400` y tumbaba el envío entero. El cliente reintentaba el mismo lote indefinidamente, así que **un registro irreparable bloqueaba la cola de un encuestador para siempre**, arrastrando hasta 100 encuestas buenas.

**Implementación** · `DatoInvalido` en [`sync.php`](../api/personas/sync.php) · `repartirRespuesta` en [`sync.js`](../pwa/js/sync.js)
**Pruebas** · `reparto.test.mjs` (5 casos) · `SyncRepositoryImplTest` (4 casos)

---

## HU-19 · Ver el estado de la sincronización 🟡

> **Como** encuestador
> **quiero** ver cuánto llevo pendiente de enviar
> **para** saber si puedo apagar el dispositivo tranquilo.

**Criterios de aceptación**

1. Se muestran los conteos de pendientes, enviados, con error y rechazados.
2. Se ve la fecha de la última sincronización correcta.
3. Todo se calcula **en local**: es información que hace falta justamente cuando no hay red.

**Implementación** · [`estado-sincronizacion.js`](../pwa/js/screens/estado-sincronizacion.js) · `EstadoSincronizacionScreen.kt`

---

# E4 · Administración

> Las historias de esta épica están cubiertas por las pruebas de integración de `tests/php` (PHPUnit contra MySQL real y el servidor embebido) y por la prueba de punta a punta.

## HU-20 · Ver el resumen general ✅

> **Como** administrador
> **quiero** un panorama del avance
> **para** informar sin tener que consultar la base a mano.

**Criterios de aceptación**

1. Se muestran totales de personas, encuestas, encuestadores y dispositivos.
2. Hay un gráfico de encuestas por día (últimos 14) construido **solo con CSS**, sin librerías externas.
3. Se ven las personas por municipio y las encuestas por encuestador.
4. Se indica la fecha de la última sincronización recibida.

**Implementación** · [`api/admin/consultas.php`](../api/admin/consultas.php)

**Pruebas** · `PanelAdminTest::testElResumenMuestraCatorceDiasYCuentaSoloEncuestadores` · `PanelFuncionesTest::testElResumenAvisaDeLosRechazosRecientes` · E2E 7

---

## HU-21 · Consultar y buscar personas ✅

> **Como** administrador
> **quiero** buscar entre todas las personas registradas
> **para** resolver consultas puntuales.

**Criterios de aceptación**

1. Búsqueda por nombre, apellido o documento.
2. Paginación de 25 en 25.
3. Se muestra el municipio resuelto a nombre, no el código.
4. Las personas borradas no aparecen.

**Pruebas** · `PanelAdminTest::testBuscaPorNombreCompletoYEscapaComodines` · `testUnaPaginaFueraDeRangoLlevaALaUltima` · `PanelFuncionesTest::testFiltrarPorMunicipioYEncuestador` · E2E 7

---

## HU-22 · Exportar a CSV ✅

> **Como** administrador
> **quiero** descargar los datos en CSV
> **para** analizarlos en una hoja de cálculo.

**Criterios de aceptación**

1. El archivo lleva **BOM UTF-8** para que Excel no destroce las tildes.
2. Las fechas van legibles, no en milisegundos.
3. Solo se exportan las personas activas.

**Pruebas** · `PanelAdminTest::testElCsvNeutralizaFormulasYNoCorreLaFechaDeNacimiento` · `PanelFuncionesTest::testElCsvExportaSoloLoFiltrado`

---

## HU-23 · Borrar una persona desde el panel ✅

> **Como** administrador
> **quiero** retirar un registro inválido
> **para** limpiar datos que ningún cliente puede corregir.

**Criterios de aceptación**

1. La acción pide confirmación y advierte que desaparecerá también de los celulares.
2. El borrado es suave y sella `updated_at` + `server_updated_at`.
3. Se propaga a todos los dispositivos en su siguiente sincronización.
4. Un dispositivo con cambios sin enviar conserva su versión hasta subirlos.
5. Queda registrado **quién** borró: `device_id` guarda `admin:<nombre>`.

**Motivación.** Quedaron en producción registros creados antes de que el servidor validara el documento. Ahora la propia validación impide reenviarlos, así que **ningún cliente puede borrarlos**.

**Pruebas** · `PanelAdminTest::testBorrarDesdeElPanelSellaParaQueLleguenLosCelulares` · `testSinSesionNoSePuedeBorrar`

---

## HU-24 · Ver y restaurar personas borradas ✅

> **Como** administrador
> **quiero** revisar lo borrado y poder deshacerlo
> **para** que un clic equivocado no sea definitivo.

**Criterios de aceptación**

1. Hay un acceso *"Ver borradas (N)"*, visible solo si hay algo dentro.
2. La lista muestra la fecha de borrado.
3. Restaurar pide confirmación y advierte que reaparecerá en los celulares.
4. La restauración sella las dos marcas, igual que el borrado, o no se propagaría.

**Pruebas** · `PanelAdminTest::testBorrarVuelveALaBusquedaYPermiteDeshacer`

---

## HU-25 · Gestionar cuentas ✅

> **Como** administrador
> **quiero** crear y editar cuentas
> **para** dar de alta al personal de campo.

**Criterios de aceptación**

1. Se puede crear una cuenta con nombre, documento, contraseña y rol.
2. La contraseña exige **mínimo 10 caracteres** al fijarla o cambiarla.
3. Editar sin escribir contraseña la deja intacta.
4. Una cuenta se puede desactivar sin borrarla.
5. Un documento duplicado da un mensaje claro, no un error genérico.

**Pruebas** · `PanelAdminTest::testUnDocumentoConPuntosSeRechazaYElFormularioConservaLoEscrito` · `testCambiarLaClaveRevocaLosTokensDelCelular` · `PanelFuncionesTest::testCerrarLasSesionesDeUnCelular` · `testDesbloquearUnaCuentaBloqueada` · `AlcanceTest::testElPanelGuardaLosMunicipiosDeUnaCuenta` · E2E 1 y 2

---

## HU-26 · Trazabilidad de las encuestas 🟡

> **Como** coordinador
> **quiero** saber quién hizo cada encuesta
> **para** poder supervisar el trabajo.

**Criterios de aceptación**

1. Cada encuesta guarda encuestador, dispositivo, fecha y acción.
2. El `id_encuestador` se toma **del token**, nunca del payload: un cliente no puede atribuir encuestas a otro.
3. El panel muestra el total por encuestador.

---

# E5 · Calidad y operación

## HU-27 · La aplicación funciona sin conexión ✅

> **Como** encuestador
> **quiero** abrir la aplicación sin señal
> **para** empezar a trabajar de inmediato.

**Criterios de aceptación**

1. El Service Worker cachea todos los recursos estáticos.
2. La navegación HTML usa **red primero**; los estáticos, caché primero.
3. Nada de `/api/` se cachea.
4. CI verifica que todo archivo listado en `sw.js` exista.

**Corrección notable.** El HTML era caché-primero. Al dividir `styles.css` en siete hojas, los navegadores siguieron sirviendo el `index.html` viejo, que apuntaba a un archivo borrado: **la aplicación quedó sin ningún estilo**. Un fallo invisible al probar en línea.

**Implementación** · [`pwa/sw.js`](../pwa/sw.js) · [`scripts/check-pwa-assets.mjs`](../scripts/check-pwa-assets.mjs)

---

## HU-28 · El esquema se actualiza solo ✅

> **Como** responsable del despliegue
> **quiero** que los cambios de esquema se apliquen sin intervención
> **para** no necesitar acceso a la base en cada versión.

**Criterios de aceptación**

1. Al faltar una columna, se crea en la primera petición que la necesite.
2. La comprobación usa `information_schema`, **no** un `try/catch` alrededor de la escritura.
3. Se ejecuta **antes** de abrir la transacción de sincronización.

**Por qué importa el orden.** En MySQL un `ALTER TABLE` provoca *commit* implícito: dentro de la transacción partiría el lote a la mitad.

**Verificado** · Contra una base sin las columnas: aparecieron solas tras la primera llamada.

---

## HU-29 · Integración continua ✅

> **Como** equipo
> **queremos** que nada llegue a `main` sin verificar
> **para** no descubrir los fallos en producción.

**Criterios de aceptación**

1. Cuatro trabajos en cada *push* y *pull request*: **android**, **php**, **pwa** y **e2e** (navegador real).
2. Android: pruebas unitarias, lint, cobertura y `assembleDebug` sobre JDK 17.
3. PHP: sintaxis de todos los archivos y **PHPStan nivel 8**.
4. PWA: pruebas con el runner nativo de Node y verificación del caché.
5. **Guardas de regresión**: CI falla si reaparece un CORS permisivo, si `sync.php` deja de exigir token, si el borrado deja de ser suave, si desaparece el límite de intentos, o si las validaciones vuelven a abortar el lote entero.
6. Dependabot vigila las dependencias.

**Implementación** · [`.github/workflows/ci.yml`](../.github/workflows/ci.yml)

---

## HU-30 · Cobertura de pruebas ✅

**Estado actual:** **271 pruebas automatizadas** — 89 en Android (JVM), 72 en la PWA, 102 de la API y el panel (71 de integración con MySQL real y 31 unitarias) y 8 de punta a punta en un navegador real. Inventario completo en [PRUEBAS.md](PRUEBAS.md).

| Suite | Qué cubre |
|---|---|
| `tests/e2e` | Panel → app → registro sin señal → envío al volver la señal → panel |
| `tests/php` | API de sincronización, descarga, panel completo y reglas de validación |
| `paridad.test.mjs` | Que las reglas de validación sean idénticas en PWA, servidor y Android |
| `contraste.test.mjs` | Contraste WCAG AA de toda la paleta, en claro y oscuro |
| `SyncRepositoryImplTest` | Lotes, confirmación parcial, rechazos terminales, descarga, paginación, fallos de red |
| `DecisionMezclaTest` | Regla de mezcla, incluido el caso que evita perder trabajo de campo |
| `AuthRepositoryImplTest` | Inicio y cierre de sesión |
| `GuardarRegistroCompletoUseCaseTest` | Atomicidad del guardado + encolado |
| `ValidacionesTest` · `GuardarPersonaUseCaseTest` | Reglas de validación |
| `ListaPersonasViewModelTest` | Estado de la interfaz |
| `mezcla.test.mjs` · `reparto.test.mjs` | Las mismas reglas del lado PWA |
| `utils.test.mjs` | Fechas en dos husos horarios |
| `session.test.mjs` · `api.test.mjs` | Sesión y cliente HTTP |

---

## HU-31 · Accesibilidad y diseño consistente ✅

> **Como** encuestador con el sol de frente
> **quiero** que el texto se lea
> **para** poder trabajar en campo abierto.

**Criterios de aceptación**

1. Todas las combinaciones de texto sobre fondo superan el mínimo **AA** de WCAG (4.5:1; 3:1 para elementos gráficos), en modo claro y oscuro.
2. Una sola paleta institucional para la PWA, el panel y Android, declarada en `design/tokens.json`.
3. Los nombres de color describen el **rol**, no el color (`BrandPrimary`, `StatusSuccess`).
4. El estado de cada registro lleva **icono y palabra**: el color nunca es la única señal.
5. Objetivos táctiles de 44 px o más y campos de 52 px de alto, pensados para usar de pie y con una mano.
6. La fuente va empaquetada: la app se ve igual sin señal.

**Motivación.** Los nombres anteriores eran `BrandGreen`; cuando la marca pasó a azul, cada pantalla que los importaba quedó mintiendo.

**Pruebas** · `contraste.test.mjs` · `scripts/tokens.mjs --verificar` (CI)

---

# E6 · Futuro

Ver [PENDIENTES.md](PENDIENTES.md) para el detalle y la justificación.

## HU-32 · Descargar el historial de encuestas ⏳

> **Como** encuestador
> **quiero** ver el historial de visitas de una persona registrada por otro
> **para** saber cuándo fue encuestada por última vez.

Hoy solo se descargan **personas**, no encuestas. Requiere un endpoint equivalente a `cambios.php` para la tabla `encuestas`.

---

## HU-33 · Adaptar la interfaz al rol ⏳

> **Como** administrador
> **quiero** ver opciones de administración dentro de la aplicación
> **para** no tener que ir al panel web.

La API ya devuelve el rol en el login; **ningún cliente lo usa todavía**.

---

## HU-34 · Reporte exportable desde el dispositivo 🟡

> **Como** encuestador
> **quiero** exportar mi trabajo del día
> **para** entregarlo sin depender del panel.

Implementada en Android después de escribirse esta historia: la pantalla de sincronización genera el CSV con `GenerarReporteUseCase` y lo comparte. Sin prueba automatizada de interfaz; la PWA no lo tiene.

---

## HU-35 · Pruebas instrumentadas de interfaz ⏳

Las 89 pruebas de Android son **unitarias en JVM**: cubren dominio, datos y ViewModels. En `androidTest` solo está `MigracionesRoomTest`, que necesita un dispositivo; ninguna prueba verifica la interfaz Compose real. La PWA sí tiene prueba de interfaz de punta a punta (`tests/e2e`).

---

## Documentos relacionados

- [Arquitectura](ARQUITECTURA.md) — diagramas y decisiones de diseño
- [Referencia de la API](API.md)
- [Pruebas](PRUEBAS.md)
- [Pendientes y hoja de ruta](PENDIENTES.md)
