# Pendientes y hoja de ruta

Estado honesto del proyecto: qué falta, por qué no está y qué pasa si no se hace.

**Última revisión:** 8 de octubre de 2026, tras ejecutar el [plan de trabajo](PLAN-DE-TRABAJO.md).

---

## Resumen

| Área | Estado |
|---|---|
| Recolección offline (Android + PWA) | ✅ Completo |
| Sincronización bidireccional | ✅ Corregida: la descarga ya no pierde registros y la PWA ya no se atasca con más de 500 pendientes |
| Validación en el servidor | ✅ Completa, compartida con el panel |
| Autenticación, roles y sesión vencida | ✅ Completo en los dos clientes |
| Panel de administración | ✅ Completo: ficha, edición, filtros, auditoría, monitor, sesiones |
| Privacidad | 🟡 Alcance por municipio y copias de seguridad cerradas; falta cifrado en el teléfono |
| Pruebas automatizadas | ✅ 52 de integración PHP con MySQL, 73 Android, 46 PWA |
| Pruebas en dispositivo real | ⏳ Guía lista en [PRUEBAS-DE-CAMPO.md](PRUEBAS-DE-CAMPO.md), sin ejecutar |
| Rediseño visual | ⏳ Brief listo; falta lanzar Claude Design |
| Despliegue | ⏳ Todo en la rama `feat/plan-de-trabajo`, sin mergear |

---

## 1 · Desplegar lo hecho

**Qué falta.** Mergear `feat/plan-de-trabajo` en `main`, verificar producción y retirar `ADMIN_PASSWORD` del entorno de Dokploy (producción ya tiene cuenta de administrador).

**Orden obligatorio.** Primero el servidor, después los clientes. Los clientes nuevos usan el cursor compuesto y lo envían como parámetros extra, que un servidor viejo ignora, así que el orden inverso no rompe nada; pero solo el servidor nuevo deja de perder registros.

**Antes de desplegar:** copia de la base (`mysqldump`). Las tablas nuevas (`sync_rechazos`, `dispositivos`, `auditoria_admin`, `encuestador_municipios`) se crean solas en la primera petición, como `server_updated_at`.

**Prioridad: alta.** Producción corre hoy la versión del panel con los fallos corregidos aquí.

## 2 · Rediseño visual

**Qué falta.** Ejecutar `/design` con [BRIEF-REDISENO.md](BRIEF-REDISENO.md), elegir dirección e implementarla (fase F3 del plan).

**Ya preparado.** La paleta vive en [`design/tokens.json`](../design/tokens.json) y `node scripts/tokens.mjs` la propaga a la PWA, el panel y Android. El CI falla si alguna diverge.

**Prioridad: media.** No bloquea nada funcional.

## 3 · Pruebas en celulares reales

**Qué falta.** Ejecutar [PRUEBAS-DE-CAMPO.md](PRUEBAS-DE-CAMPO.md) en al menos un Android de gama baja (idealmente Xiaomi, por su ahorro de batería agresivo) y en la PWA en Chrome.

**Por qué importa.** WorkManager, la sincronización en segundo plano de la PWA y el ahorro de batería no se pueden comprobar en JVM ni en el servidor. La prueba de migraciones de Room (`MigracionesRoomTest`) está escrita y compila, pero necesita un dispositivo: `./gradlew connectedDebugAndroidTest`.

**Prioridad: alta** antes del uso en campo.

## 4 · Cifrado de datos en el teléfono

**Situación.** Evaluado y pospuesto a propósito. Ya está hecho:
- la copia de seguridad está desactivada y excluye sesión, credenciales y base;
- cada encuestador puede quedar limitado a sus municipios;
- el login sin conexión guarda PBKDF2 con sal, nunca la contraseña.

**Lo que falta.** Cifrar la base Room (SQLCipher) y el token (Android Keystore).

**Por qué no se hizo ya.** Migrar una base Room existente a SQLCipher exige exportar y reimportar los datos en el teléfono del encuestador, incluidos los registros sin enviar. Un fallo ahí destruye trabajo de campo, y no hay forma de probarlo sin dispositivos reales (punto 3). La librería `security-crypto` de AndroidX está obsoleta.

**Prioridad: media.** Hacerlo después de las pruebas de campo, con la migración probada en un dispositivo.

## 5 · El historial de encuestas no llega a los celulares

**Qué falta.** `cambios.php` entrega personas, no encuestas. El panel ya muestra el historial completo en la ficha de cada persona, pero un encuestador en campo no ve quién encuestó antes a alguien. Es la HU-32.

**Prioridad: baja.**

## 6 · Android no puede borrar personas

**Hallazgo nuevo.** `EliminarPersonaUseCase` existe pero ninguna pantalla lo usa. Además, si se usara, marcaría la persona como borrada sin meterla en la cola, así que el borrado nunca llegaría al servidor. Antes de exponerlo hay que añadir el elemento a la cola, como hace `GuardarRegistroCompletoUseCase`.

**Prioridad: baja.** La PWA y el panel sí pueden borrar.

## 7 · Identidad única para panel y aplicación

**Situación.** Las mismas credenciales sirven para el panel y la app. Si se pierde el celular de un administrador, sus credenciales abren también el panel.

**Mitigación actual.** El panel revalida la sesión en cada petición, caduca tras una hora sin actividad, registra cada acción en la auditoría y permite cerrar las sesiones de un celular desde Cuentas.

**Mejora posible.** Segundo factor solo para el panel.

**Prioridad: baja.**

## 8 · Datos históricos sin identificador de dispositivo

**Situación.** Hasta esta versión, Android enviaba `DEVICE_ID_LOCAL` fijo. Esos registros no se pueden atribuir a un teléfono concreto; el monitor los muestra como un único dispositivo. Desde esta versión cada instalación tiene su UUID.

**Prioridad: informativa.** No tiene arreglo retroactivo.

## 9 · Deuda operativa

| Punto | Estado |
|---|---|
| Actualizaciones de Dependabot (Retrofit 3, Hilt, Compose, acciones v7) | Por revisar una a una. Retrofit 3 es un cambio mayor |
| Retirar `ADMIN_PASSWORD` del entorno | Pendiente del despliegue (punto 1) |
| Pruebas instrumentadas en CI | Exigen un emulador en el runner; hoy solo se compilan |

---

## Lo que **no** está pendiente

| Aparente ausencia | Realidad |
|---|---|
| No hay migraciones manuales que ejecutar | Deliberado: `api/esquema.php` crea columnas y tablas solo, porque el despliegue no tiene consola de base de datos |
| La PWA no usa framework | Deliberado: sin *build step* ni dependencias en ejecución, el service worker cachea archivos reales |
| No hay refresh tokens | Deliberado: la vigencia larga permite trabajar 30 días sin conectividad. Al vencer, los dos clientes avisan y conservan la cola |
| El borrado no elimina filas | Deliberado: un `DELETE` real impediría que los dispositivos se enteraran del borrado |
| El reporte CSV de Android | Existe y se usa desde la pantalla de sincronización |

---

## Documentos relacionados

- [Plan de trabajo](PLAN-DE-TRABAJO.md) · [Arquitectura](ARQUITECTURA.md) · [Historias de usuario](HISTORIAS-DE-USUARIO.md) · [API](API.md) · [Pruebas de campo](PRUEBAS-DE-CAMPO.md)
