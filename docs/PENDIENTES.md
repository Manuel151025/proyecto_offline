# Pendientes y hoja de ruta

Estado honesto del proyecto: qué falta, por qué no está, y qué pasa si no se hace.

**Última revisión:** 11 de agosto de 2026

---

## Resumen

| Área | Estado |
|---|---|
| Recolección offline (Android + PWA) | ✅ Completo |
| Sincronización bidireccional | ✅ Completo |
| Resolución de conflictos | ✅ Completo |
| Autenticación y roles | ✅ Completo |
| Panel de administración | ✅ Completo |
| Integración continua | ✅ Completo |
| Pruebas automatizadas | 🟡 105, sin cubrir PHP ni interfaz |
| Descarga de encuestas | ⏳ Solo se descargan personas |
| Uso del rol en los clientes | ⏳ Se recibe, no se usa |

---

## 1 · Sin pruebas automatizadas para PHP

**Qué falta.** No hay PHPUnit. La API se verifica con `php -l`, **PHPStan nivel 8** y guardas de regresión basadas en `grep` dentro de CI.

**Por qué importa.** Toda la lógica de resolución de conflictos del servidor —Last-Write-Wins, validación por fila, marca de agua— no tiene prueba unitaria. Las guardas de CI comprueban que el código *existe*, no que *funciona*.

**Mitigación actual.** Cada cambio de esta parte se verificó manualmente contra una base MySQL local, reproduciendo el escenario completo. Está documentado en los mensajes de commit.

**Coste de arreglarlo.** Medio. Añadir PHPUnit y una base de pruebas en CI son unas horas; escribir las pruebas de `sync.php` y `cambios.php`, algo más.

**Prioridad: alta.** Es la brecha más grande de la cobertura.

---

## 2 · Sin pruebas instrumentadas de interfaz

**Qué falta.** No existe `app/src/androidTest`. Las 68 pruebas de Android son unitarias en JVM.

**Consecuencia.** No se verifican automáticamente:
- Las migraciones de Room contra un dispositivo real (aunque el esquema exportado sí se valida en cada compilación)
- La navegación entre pantallas Compose
- El comportamiento real del `SyncWorker` bajo WorkManager

**Prioridad: media.** El dominio y los datos, que es donde se pierde información, sí están cubiertos.

---

## 3 · El historial de encuestas no se descarga

**Qué falta.** `cambios.php` entrega **personas**. La tabla `encuestas` —el registro de trazabilidad— solo existe completa en el servidor.

**Consecuencia.** Un encuestador que recibe una persona registrada por otro dispositivo ve sus datos, pero no cuándo ni quién la encuestó.

**Qué haría falta.** Un endpoint equivalente con su propia marca de agua, más el almacenamiento en Room e IndexedDB. La tabla `encuestas` no tiene hoy columna de sello del servidor: haría falta añadirla (con automigración, como `server_updated_at`).

**Prioridad: media.** Es la HU-32.

---

## 4 · Los clientes reciben el rol pero no lo usan

**Qué falta.** `login.php` devuelve `rol` y `requerirAutenticacion` lo resuelve, pero ni la PWA ni Android hacen nada con él.

**Consecuencia.** Un administrador tiene que ir al panel web para cualquier tarea de administración.

**Decisión tomada.** Se eligió deliberadamente que el administrador entre por el panel, para acotar el alcance. El campo se dejó disponible para cuando se quiera cambiar.

**Prioridad: baja.** Es la HU-33.

---

## 5 · Identidad única para panel y aplicación

**Situación.** Las mismas credenciales sirven para el panel y para la aplicación de campo: es **una sola identidad**, no dos.

**Riesgo.** Si el dispositivo de un administrador se pierde, sus credenciales sirven también para el panel.

**Alternativa.** Credenciales separadas, o segundo factor solo para el panel.

**Prioridad: baja**, pero es un cambio **barato ahora y molesto más adelante**, cuando haya cuentas creadas.

---

## 6 · El bloqueo del panel se puede provocar

**Situación.** El contador anti fuerza bruta del panel es global, porque el panel no pide usuario en modo arranque.

**Riesgo.** Alguien que conozca la URL puede mantenerlo bloqueado a base de intentos fallidos.

**Por qué se aceptó.** Es preferible a permitir fuerza bruta ilimitada sobre un panel que puede borrar datos. El bloqueo caduca solo a los 15 minutos y los intentos quedan en `error_log`.

**Escape.** Con acceso al servidor:

```sql
DELETE FROM intentos_login WHERE documento = '#admin';
```

**Mejora posible.** Ahora que el panel pide documento, el contador podría ser por cuenta salvo en modo arranque. Reduce el problema a la ventana inicial.

**Prioridad: baja.**

---

## 7 · Reporte no accesible desde el dispositivo

**Situación.** `GenerarReporteUseCase` existe y está inyectado en `SyncViewModel`, pero **ninguna pantalla lo expone**.

**Consecuencia.** Código muerto que aparenta una funcionalidad inexistente.

**Dos salidas válidas:** exponerlo en una pantalla, o retirarlo. Dejarlo así es lo peor de ambas.

**Prioridad: baja.** Es la HU-34.

---

## 8 · Sin observabilidad más allá del log

**Qué falta.** No hay métricas ni alertas. Diagnosticar exige entrar al servidor a leer `error_log`.

**Consecuencia.** Un aumento de rechazos, de fallos de autenticación o de errores 500 pasa inadvertido hasta que alguien lo reporta.

**Mínimo útil.** Un endpoint de salud (`/api/health.php`) que verifique la conexión a la base, más un contador de errores por día en el panel.

**Prioridad: media** si el sistema pasa a uso real con varios encuestadores.

---

## 9 · La paleta vive duplicada

**Situación.** Los colores institucionales están declarados en `pwa/css/base.css` y en `presentation/theme/Theme.kt`. Deben mantenerse en espejo **a mano**.

**Riesgo.** Divergencia silenciosa entre plataformas.

**Mitigación posible.** Generar ambos desde un JSON común en tiempo de compilación.

**Prioridad: baja.** Son dos archivos y el README lo advierte.

---

## 10 · Deuda operativa

| Punto | Estado |
|---|---|
| Cerrar PRs obsoletas de Dependabot (#14, #15, #16, #18, #19) | Pendiente |
| PRs de acciones válidas (#13, #17, #20, #21) | Por revisar |
| Retirar `ADMIN_PASSWORD` del entorno tras crear el primer admin | Pendiente del despliegue |
| Limpiar registros históricos inválidos en producción | Ya es posible desde el panel |

---

## Lo que **no** está pendiente

Conviene decirlo, porque en una revisión superficial pueden parecer ausencias:

| Aparente ausencia | Realidad |
|---|---|
| No hay migraciones manuales que ejecutar | Deliberado: `api/esquema.php` las aplica solo, porque el despliegue no tiene consola de base de datos |
| La PWA no usa framework | Deliberado: sin *build step* ni dependencias en ejecución, el Service Worker cachea archivos reales |
| No hay refresh tokens | Deliberado: la vigencia larga es lo que permite trabajar 30 días sin conectividad |
| El borrado no elimina filas | Deliberado: un `DELETE` real impediría que los dispositivos se enteraran del borrado |
| No hay índice `(deleted_at, updated_at)` en MySQL | Deliberado: el servidor accede a `personas` solo por clave primaria |

---

## Documentos relacionados

- [Arquitectura](ARQUITECTURA.md) · [Historias de usuario](HISTORIAS-DE-USUARIO.md) · [API](API.md)
