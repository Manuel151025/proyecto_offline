# Pruebas en celulares reales

Lo que las pruebas automáticas no pueden comprobar: que un teléfono real, sin señal y con ahorro de batería, guarde el trabajo y lo envíe solo al recuperar la red.

**Cuándo:** antes de cada salida a campo con una versión nueva.

## Preparación

| Elemento | Detalle |
|---|---|
| Android | Un teléfono de gama baja; idealmente también un Xiaomi o un Samsung, por su ahorro de batería agresivo |
| PWA | Chrome en Android, instalada en la pantalla de inicio |
| Cuenta | Una cuenta de encuestador de prueba creada en el panel |
| Panel | Abierto en la sección **Sincronización**, para ver llegar cada celular |

Anota la versión de la app (aparece en el monitor del panel) y la fecha.

## Escenarios

Marca cada fila. «Tiempo» es lo que tarda en aparecer el registro en el panel tras recuperar la red.

| # | Escenario | Pasos | Esperado | Android | PWA | Tiempo |
|---|---|---|---|---|---|---|
| 1 | Registro sin señal | Modo avión → registrar 20 personas | Todas guardadas; aviso «Guardado en el teléfono» | ☐ | ☐ | — |
| 2 | Reconexión con la app abierta | Quitar modo avión con la app en pantalla | Suben solas, sin tocar nada | ☐ | ☐ | |
| 3 | Reconexión con la app cerrada | Registrar en modo avión, cerrar la app, quitar modo avión | Android: suben solas. PWA en Chrome: suben solas; en iOS, al abrir | ☐ | ☐ | |
| 4 | Tras reiniciar el teléfono | Registrar en modo avión, reiniciar, quitar modo avión | Android: suben solas sin abrir la app | ☐ | n/a | |
| 5 | Ahorro de batería | Activar ahorro máximo y repetir el 3 | Anotar si se retrasa o no ocurre | ☐ | ☐ | |
| 6 | Señal intermitente | Activar y desactivar datos cada 20 s durante 2 min | Todo termina subiendo, sin duplicados en el panel | ☐ | ☐ | |
| 7 | Más de 500 pendientes | Registrar en lote sin señal (o con datos de prueba) | Sube en lotes de 100, sin quedarse atascado | ☐ | ☐ | |
| 8 | Sesión revocada | Desde el panel, Cuentas → «Cerrar sesiones en celulares» | Aviso de sesión vencida; tras entrar con red, la cola sube | ☐ | ☐ | |
| 9 | Login sin conexión | Cerrar sesión con red, activar modo avión, volver a entrar | Entra con la cuenta real (no solo la demo) | ☐ | ☐ | |
| 10 | Registro inválido | Registrar un documento de 3 dígitos | El formulario lo impide con un mensaje en el campo | ☐ | ☐ | — |
| 11 | Descarga de otro celular | Registrar en el teléfono A y sincronizar el B | La persona aparece en el B | ☐ | ☐ | |
| 12 | Borrado desde el panel | Borrar en el panel y sincronizar | Desaparece del celular | ☐ | ☐ | |
| 13 | Migración de la base (solo Android) | Instalar la versión nueva sobre la anterior con datos sin enviar | Los datos siguen ahí y suben | ☐ | n/a | — |

## Pruebas automáticas con dispositivo

Con el teléfono conectado por USB y la depuración activada:

```bash
./gradlew connectedDebugAndroidTest
```

Ejecuta `MigracionesRoomTest`, que construye la base en la versión 1 con datos sin enviar, la migra a la actual y comprueba que nada se perdió.

## Resultado

| Fecha | Versión | Teléfonos | Fallos | Responsable |
|---|---|---|---|---|
| | | | | |
