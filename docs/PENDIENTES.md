# Pendientes y hoja de ruta

Estado honesto del proyecto: qué falta, por qué no está y qué pasa si no se hace.

**Última revisión:** 9 de octubre de 2026, al cierre del proyecto.

---

## Resumen

| Área | Estado |
|---|---|
| Recolección offline (Android + PWA) | ✅ Completo |
| Sincronización bidireccional | ✅ Por lotes, con cursor compuesto, rechazo por fila y subida en segundo plano |
| Validación | ✅ Estricta por campo, idéntica en PWA, servidor y Android (con prueba de paridad); el formulario filtra lo que se escribe |
| Autenticación, roles y sesión vencida | ✅ Completo en los dos clientes y el panel |
| Panel de administración | ✅ Resumen, personas (ficha, edición, filtros, CSV, papelera), cuentas, monitor de sincronización y auditoría |
| Diseño | ✅ Dirección «Cálida de territorio» en la PWA, el panel (incluido el acceso) y el inicio de Android; contraste AA verificado por prueba |
| Privacidad | 🟡 Descarga por municipios y copias de seguridad cerradas; falta cifrado en el teléfono |
| Pruebas automatizadas | ✅ 254: 85 Android, 72 PWA, 90 PHP, 7 de punta a punta. Ver [PRUEBAS.md](PRUEBAS.md) |
| Catálogos | ✅ Los 1.122 municipios y las EPS de Colombia, con buscador, actualizados solos en teléfonos y servidor |
| Despliegue | ✅ Producción al día con `main` |
| Pruebas en celulares reales | ⏳ En curso; guía en [PRUEBAS-DE-CAMPO.md](PRUEBAS-DE-CAMPO.md) |

---

## 1 · Pruebas en celulares reales

**Qué falta.** Completar [PRUEBAS-DE-CAMPO.md](PRUEBAS-DE-CAMPO.md) en al menos un Android de gama baja (idealmente un Xiaomi, por su ahorro de batería agresivo) y en la PWA instalada en Chrome.

**Por qué importa.** WorkManager, la subida en segundo plano de la PWA y el ahorro de batería de cada fabricante no se pueden comprobar en la JVM ni en el servidor. La prueba de migraciones de Room (`MigracionesRoomTest`) está escrita y compila, pero necesita un dispositivo: `./gradlew connectedDebugAndroidTest`.

**Prioridad: alta** antes del uso en campo.

## 2 · Registros de prueba que ya no cumplen las reglas

**Situación.** Desde el 9 de octubre la validación es más estricta (documento solo con dígitos salvo pasaporte, teléfono de 10 dígitos, nombres sin signos). Un registro guardado antes en un teléfono que no cumpla las reglas nuevas se **rechazará** al enviarse, con su motivo.

**Qué hacer.** En la app, *Envío de datos* → **Corregir**. Si el error está en el número de documento (no se puede editar), borrar la persona y registrarla de nuevo. Los que ya están en el servidor no se tocan: las reglas solo se aplican a lo que llega.

**Prioridad: baja.** Solo afecta datos de prueba previos.

## 3 · Cifrado de datos en el teléfono

**Situación.** Evaluado y pospuesto a propósito. Ya está hecho:
- la copia de seguridad está desactivada y excluye sesión, credenciales y base;
- cada encuestador puede quedar limitado a sus municipios;
- el login sin conexión guarda PBKDF2 con sal, nunca la contraseña.

**Lo que falta.** Cifrar la base Room (SQLCipher) y el token (Android Keystore).

**Por qué no se hizo ya.** Migrar una base Room existente a SQLCipher exige exportar y reimportar los datos en el teléfono del encuestador, incluidos los registros sin enviar. Un fallo ahí destruye trabajo de campo, y no hay forma de probarlo sin dispositivos reales (punto 1). La librería `security-crypto` de AndroidX está obsoleta.

**Prioridad: media.** Después de las pruebas de campo, con la migración probada en un dispositivo.

## 4 · Pantallas de Android con rediseño propio

**Situación.** Android ya usa la paleta, la fuente Figtree y las esquinas de la dirección nueva en todas sus pantallas, y el inicio tiene la tarjeta del día y el botón terracota. El acceso, el formulario y el envío **heredan** ese estilo, pero no tienen todavía la composición de la PWA (banda azul en el acceso, título «Envío de datos», tarjetas de conteo).

**Prioridad: baja.** Es cosmético: la validación y el filtro de campos ya son los mismos que en la PWA.

## 5 · El historial de encuestas no llega a los celulares

**Qué falta.** `cambios.php` entrega personas, no encuestas. El panel muestra el historial completo en la ficha de cada persona, pero un encuestador en campo no ve quién la encuestó antes. Es la HU-32.

**Prioridad: baja.**

## 6 · Android no puede borrar personas

**Situación.** `EliminarPersonaUseCase` existe pero ninguna pantalla lo usa. Si se usara, marcaría la persona como borrada sin meterla en la cola, así que el borrado nunca llegaría al servidor. Antes de exponerlo hay que añadir el elemento a la cola, como hace `GuardarRegistroCompletoUseCase`.

**Prioridad: baja.** La PWA y el panel sí pueden borrar.

## 7 · Identidad única para panel y aplicación

**Situación.** Las mismas credenciales sirven para el panel y la app. Si se pierde el celular de un administrador, sus credenciales abren también el panel.

**Mitigación actual.** El panel revalida la sesión en cada petición, caduca tras una hora sin actividad, registra cada acción en la auditoría y permite cerrar las sesiones de un celular desde *Cuentas*.

**Mejora posible.** Segundo factor solo para el panel.

**Prioridad: baja.**

## 8 · Datos históricos sin identificador de dispositivo

**Situación.** Las primeras versiones de Android enviaban `DEVICE_ID_LOCAL` fijo. Esos registros no se pueden atribuir a un teléfono concreto; el monitor los muestra como un único dispositivo. Cada instalación actual tiene su UUID.

**Prioridad: informativa.** No tiene arreglo retroactivo.

## 9 · Deuda operativa

| Punto | Estado |
|---|---|
| PR de Dependabot de androidx que exige `compileSdk 37` + AGP 9 | Cerrarlo: la parte compatible ya se aplicó; lo demás espera a migrar a AGP 9 |
| Otras actualizaciones de Dependabot (Retrofit 3, OkHttp 5, Hilt, Compose BOM) | Revisar una a una; Retrofit 3 y OkHttp 5 son cambios mayores |
| `ADMIN_PASSWORD` en el entorno de Dokploy | Retirarla si sigue definida: producción ya tiene administrador y la variable no se acepta, pero no hace falta tenerla |
| Archivos del andamiaje inicial (`scaffold.ps1`, `step*.ps1`, `update_step5.ps1`, `design_handoff_login/`) | Se pueden borrar del repositorio: ya no los usa nada |
| Pruebas instrumentadas en CI | Exigen un emulador en el runner; hoy solo se compilan |

---

## Lo que **no** está pendiente

| Aparente ausencia | Realidad |
|---|---|
| No hay migraciones manuales que ejecutar | Deliberado: `api/esquema.php` crea columnas y tablas solo, porque el despliegue no tiene consola de base de datos |
| La PWA no usa framework | Deliberado: sin *build step* ni dependencias en ejecución, el service worker cachea archivos reales |
| Las pruebas de punta a punta no usan Playwright | Deliberado: se controla el navegador con el protocolo DevTools y Node 22, sin `node_modules` |
| No hay refresh tokens | Deliberado: la vigencia larga permite trabajar 30 días sin conectividad. Al vencer, los dos clientes avisan y conservan la cola |
| El borrado no elimina filas | Deliberado: un `DELETE` real impediría que los dispositivos se enteraran del borrado |
| La fuente no viene de Google Fonts | Deliberado: va empaquetada para que la app se vea igual sin señal y no se filtre la IP de nadie |

---

## Documentos relacionados

- [Arquitectura](ARQUITECTURA.md) · [Historias de usuario](HISTORIAS-DE-USUARIO.md) · [API](API.md) · [Pruebas](PRUEBAS.md) · [Pruebas de campo](PRUEBAS-DE-CAMPO.md) · [Manual](MANUAL.md) · [Plan de trabajo](PLAN-DE-TRABAJO.md)
