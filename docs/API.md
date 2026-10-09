# Referencia de la API

API REST en PHP 8 sin framework. Todas las respuestas son JSON salvo el panel de administración, que devuelve HTML.

**Base:** `https://encuestas.manuelcardenas.online/api`

## Índice

| Método | Ruta | Auth | Propósito |
|---|---|:---:|---|
| `POST` | [`/auth/login.php`](#post-authloginphp) | — | Emitir token |
| `POST` | [`/auth/logout.php`](#post-authlogoutphp) | Bearer | Revocar token |
| `POST` | [`/personas/sync.php`](#post-personassyncphp) | Bearer | **Subir** encuestas |
| `GET` | [`/personas/cambios.php`](#get-personascambiosphp) | Bearer | **Bajar** cambios |
| `GET` | [`/municipios/index.php`](#get-municipiosindexphp) | — | Catálogo DANE |
| `GET` | [`/health.php`](#get-healthphp) | — | Salud de la API y la base |
| `GET/POST` | [`/admin/index.php`](#panel-de-administración) | Sesión PHP | Panel web |

---

## Convenciones

### Autenticación

Los endpoints marcados **Bearer** exigen la cabecera:

```http
Authorization: Bearer <token>
```

El token se obtiene en `/auth/login.php`, dura **30 días** y en el servidor solo se guarda su **hash SHA-256**. Una filtración de la tabla `sesiones` no permite suplantar a nadie.

### Fechas

Todas las marcas temporales son **enteros en milisegundos desde época Unix**. Las fechas de calendario (nacimiento) se interpretan en **UTC**; las marcas de sincronización son instantes reales.

### Errores

Formato uniforme:

```json
{ "success": false, "message": "Descripción para el usuario" }
```

| Código | Cuándo |
|---|---|
| `400` | Payload mal formado o campo con formato inválido |
| `401` | Token ausente, inválido o vencido |
| `403` | Cuenta desactivada |
| `405` | Método no permitido |
| `413` | Lote por encima de 500 registros |
| `429` | Demasiados intentos fallidos (incluye `Retry-After`) |
| `500` | Error de servidor — el detalle va al log, nunca al cliente |

> Los mensajes de excepción **nunca** llegan al cliente: revelarían host, usuario de base de datos y rutas internas.

### Cabeceras del dispositivo

Los clientes envían en `sync.php` y `cambios.php`:

| Cabecera | Ejemplo | Uso |
|---|---|---|
| `X-Device-Id` | `android_5f1c…` / `pwa_9a2e…` | Identifica el celular en el monitor del panel |
| `X-Plataforma` | `android` · `pwa` | |
| `X-App-Version` | `1.0` · `2.0.0` | Para saber qué versión corre en campo |

Son opcionales: sin ellas el servidor responde igual, solo que el monitor no puede mostrar ese celular.

### CORS

No se usa `Access-Control-Allow-Origin: *`. Solo se reflejan orígenes presentes en `ALLOWED_ORIGINS`.

> CORS es una política del **navegador**. `curl` y la app Android la ignoran: la protección real es el token.

Cabeceras de seguridad aplicadas en todas las respuestas: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` y `Strict-Transport-Security` sobre HTTPS.

---

## `POST /auth/login.php`

Valida credenciales y emite un token.

**Cuerpo**

```json
{ "numero_documento": "1000000001", "password": "Demo2026Salud" }
```

**Respuesta `200`**

```json
{
  "success": true,
  "token": "8f3a…",
  "expira_en": 1789065600,
  "encuestador": {
    "id": 1,
    "nombre": "Docente Demo",
    "numero_documento": "1000000001",
    "rol": "encuestador"
  }
}
```

`rol` es `encuestador` o `admin`. El token en claro se devuelve **una sola vez**.

**Errores**

| Código | Motivo |
|---|---|
| `400` | Faltan campos, o el documento excede 20 caracteres o usa algo distinto de letras, dígitos y guiones |
| `401` | Credenciales incorrectas o cuenta desactivada — mismo mensaje en ambos casos |
| `429` | 5 fallos en 15 minutos |

> El formato del documento se valida **antes** de comprobar la contraseña. El documento es la clave del contador anti fuerza bruta: sin esa validación, un cliente podía enviar la clave interna del panel (`#admin`) y dejarlo bloqueado sin tocarlo.

---

## `POST /auth/logout.php`

Revoca el token actual. **Idempotente**: siempre responde `200`, aunque el token no exista o ya haya vencido — distinguir esos casos solo serviría para averiguar qué tokens son válidos.

```json
{ "success": true, "message": "Sesión cerrada" }
```

---

## `POST /personas/sync.php`

Sube un lote de personas y encuestas. Resuelve conflictos por **Last-Write-Wins** dentro de una transacción.

**Cuerpo**

```json
{
  "personas": [
    {
      "tipo_documento": "CC",
      "numero_documento": "1098765432",
      "nombres": "Ana María",
      "apellidos": "Torres Gómez",
      "fecha_nacimiento": 631152000000,
      "telefono": "3001234567",
      "email": "ana@example.com",
      "direccion": "Vereda La Esperanza",
      "vereda": "La Esperanza",
      "eps": "Nueva EPS",
      "ocupacion": "Agricultora",
      "estrato": 2,
      "municipio_codigo": "18001",
      "updated_at": 1786387933257,
      "device_id": "dev-a1b2c3",
      "deleted_at": null
    }
  ],
  "encuestas": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "tipo_documento": "CC",
      "numero_documento": "1098765432",
      "fecha_encuesta": 1786387933000,
      "device_id": "dev-a1b2c3",
      "accion": "CREACION"
    }
  ]
}
```

**Campos obligatorios** — persona: `tipo_documento`, `numero_documento`, `nombres`, `apellidos`, `updated_at`, `device_id`. Encuesta: `id`, `tipo_documento`, `numero_documento`, `fecha_encuesta`, `device_id`, `accion`.

> `id_encuestador` **no se toma del payload**: se usa el del token, para que un cliente no pueda atribuir encuestas a otro encuestador.

**Respuesta `200`**

```json
{
  "success": true,
  "message": "Sincronización completada con 1 registro(s) rechazado(s).",
  "processed_encuestas": ["550e8400-e29b-41d4-a716-446655440000"],
  "rechazadas": [
    { "id": "otro-uuid", "motivo": "El número de documento debe tener al menos 6 caracteres" }
  ]
}
```

| Campo | Significado |
|---|---|
| `processed_encuestas` | Encuestas aceptadas. **Solo estas** deben marcarse como enviadas. |
| `rechazadas` | Descartadas por inválidas, con motivo. El cliente debe marcarlas **terminales**: reenviarlas daría siempre el mismo resultado. |

**Reglas**

1. Un registro inválido **no tumba el lote**: se descarta esa fila y el resto continúa. Un payload mal formado (sin los arreglos esperados) sí devuelve `400`, porque eso es fallo de cliente.
2. Si una persona se descarta, sus encuestas se rechazan también: la clave foránea apuntaría a una fila inexistente.
3. **LWW:** si la persona ya existe y el `updated_at` entrante no es mayor, se ignora sin error.
4. Máximo **500 registros** por arreglo (`413` si se excede).
5. Tipos admitidos: `CC · TI · RC · CE · PP · NIT · PE`. Documento de 6 a 20 caracteres, solo `[A-Za-z0-9-]`.
6. **Validación por fila** (rechazo con motivo, nunca `500`): nombres y apellidos sin dígitos; estrato de 1 a 6; correo con formato válido; fecha de nacimiento entre 1900 y hoy; `municipio_codigo` existente. Antes, un municipio desconocido violaba la clave foránea y tumbaba el lote entero.
7. **Relojes adelantados:** `updated_at` y `fecha_encuesta` se recortan a la hora del servidor + 5 minutos. Sin esto, un teléfono con la fecha mal puesta escribía registros que ninguna corrección posterior podía superar.
8. Cada rechazo se guarda en `sync_rechazos` y el celular queda registrado en `dispositivos`, para el monitor del panel.

Las reglas viven en [`api/personas/validacion.php`](../api/personas/validacion.php), compartido con la edición desde el panel.

---

## `GET /personas/cambios.php`

Descarga incremental de personas.

**Parámetros**

| Nombre | Tipo | Defecto | Descripción |
|---|---|---|---|
| `desde` | entero | `0` | Sello del cursor: `server_updated_at` de la última persona recibida |
| `tipo` | texto | — | Tipo de documento de la última persona recibida (desempate) |
| `numero` | texto | — | Número de documento de la última persona recibida (desempate) |
| `limite` | entero | `200` | Máximo por página (tope 500) |

```http
GET /api/personas/cambios.php?desde=1786387933257&tipo=CC&numero=1098765432&limite=200
Authorization: Bearer <token>
```

**Cursor compuesto.** El orden es `(server_updated_at, tipo_documento, numero_documento)`. `sync.php` sella todo un lote con el mismo milisegundo; con solo el sello como marca, una página que cortaba ese grupo dejaba el resto **sin descargar nunca** (se demostró con 3 personas en páginas de 2). Sin `tipo` y `numero` el comportamiento es el anterior, para los clientes ya instalados.

**Alcance por encuestador.** Si la cuenta tiene municipios asignados en el panel, solo recibe las personas de esos municipios y las que ella misma registró o actualizó. Sin asignación recibe todo. Los administradores siempre reciben todo.

**Respuesta `200`**

```json
{
  "success": true,
  "personas": [ { "…": "…", "server_updated_at": 1786469459500 } ],
  "marca": 1786469459500,
  "cursor": { "sello": 1786469459500, "tipo": "CC", "numero": "1098765432" },
  "hay_mas": false
}
```

| Campo | Significado |
|---|---|
| `personas` | Filas cambiadas, ordenadas por `server_updated_at` ascendente. **Incluye las borradas**, con `deleted_at` informado. |
| `marca` | Nueva marca de agua. Si no vino nada, se devuelve la que el cliente ya tenía, para que no retroceda. |
| `cursor` | Posición exacta para pedir la página siguiente (`desde`, `tipo`, `numero`). |
| `hay_mas` | La página venía llena: probablemente quedan más. |

**Por qué se filtra por `server_updated_at` y no por `updated_at`**

`updated_at` lo pone el dispositivo. Un teléfono con el reloj atrasado escribiría filas con fecha anterior a la marca que los demás ya superaron: **esas filas no se descargarían nunca y el dato se perdería en silencio**. El sello del servidor avanza siempre hacia adelante.

**Por qué se devuelven las borradas**

Un borrado es un cambio que los demás dispositivos deben conocer. Omitirlas haría reaparecer lo eliminado en la siguiente subida.

**Uso correcto en el cliente**

1. Subir primero, bajar después.
2. Paginar con el `cursor` mientras `hay_mas` sea verdadero (con tope, como cortafuegos).
3. Empezar cada sincronización **2 minutos antes** de la marca guardada: dos envíos pueden confirmarse en el servidor en orden distinto al de sus sellos. Recibir algo dos veces es inofensivo.
4. Guardar la marca **después** de mezclar: si la aplicación muere a mitad, se repite la página en vez de saltársela.
5. **Nunca sobrescribir un registro con cambios locales sin enviar.**

---

## `GET /health.php`

Para monitoreo externo. `200` si la API responde y la base acepta consultas; `503` si no. No revela detalles internos.

```json
{ "success": true, "api": "ok", "base_de_datos": "ok", "hora_servidor": 1786469459500 }
```

---

## `GET /municipios/index.php`

Catálogo DIVIPOLA/DANE. Sin autenticación: es información pública y los clientes la necesitan antes de iniciar sesión.

```json
[ { "codigo": "18001", "nombre": "Florencia", "departamento": "Caquetá" } ]
```

---

## Panel de administración

`GET/POST /api/admin/index.php` — Interfaz HTML, no API. Usa **sesión PHP** con cookie `HttpOnly; SameSite=Strict; Secure`, y token **CSRF** en cada formulario.

### Acceso

Documento + contraseña de una cuenta con rol `admin` **activa**. Mientras no exista ninguna, se acepta `ADMIN_PASSWORD` solo para crear la primera; después deja de aceptarse sola.

Mismo límite de intentos que la API: 5 fallos → 15 minutos. Estando bloqueado, **incluso la contraseña correcta se rechaza**. El documento debe cumplir el mismo formato que en `login.php` (letras, dígitos y guiones, hasta 20).

La sesión se **revalida en cada petición**: se cierra si la cuenta deja de ser administradora activa, si su contraseña cambió desde que entró, tras una hora sin actividad, y (en modo arranque) en cuanto existe un administrador. Las páginas se sirven con `Cache-Control: no-store`.

Las fechas se muestran y los días se agrupan en hora de Colombia (`America/Bogota`).

### Secciones

| Ruta | Contenido |
|---|---|
| `?seccion=resumen` | Totales, encuestas por día (14 días, los vacíos en cero), personas por municipio, encuestas por encuestador |
| `?seccion=personas&q=&p=` | Listado con búsqueda (nombre completo o documento) y paginación |
| `?seccion=personas&borradas=1` | Papelera, con restauración |
| `?seccion=cuentas` | Cuentas: crear, editar, rol, activar. `?seccion=encuestadores` sigue funcionando |
| `?seccion=personas&departamento=&municipio=&encuestador=&desde=&hasta=` | Filtros (las fechas, en hora de Colombia) |
| `?seccion=persona&tipo=&numero=` | Ficha: todos los campos, historial de encuestas y cambios hechos desde el panel. Con `&editar_persona=1`, formulario de edición |
| `?seccion=cuentas&editar=<id>` | Edición de una cuenta, con sus sesiones en celulares, desbloqueo y municipios asignados |
| `?seccion=sincronizacion` | Monitor: celulares, rechazos y equipos sin sincronizar hace más de 3 días |
| `?seccion=auditoria` | Registro de acciones de los administradores |
| `?exportar=personas` | CSV con BOM UTF-8, con la **misma búsqueda y filtros** que la tabla; las celdas que Excel ejecutaría como fórmula van precedidas de `'` |

### Acciones (POST)

Toda acción responde con una redirección `303` y un aviso de un solo uso guardado en la sesión, así que recargar no repite el envío.

| `action` | Efecto |
|---|---|
| `login` / `logout` | Sesión del panel |
| `save` | Crear o editar cuenta. Rechaza quitar el rol o desactivar al **único** admin activo. Cambiar la contraseña o desactivar la cuenta **revoca sus tokens de API**. |
| `borrar_persona` | Borrado suave; sella ambas marcas; registra `admin:<nombre>`. El aviso ofrece deshacer. |
| `restaurar_persona` | Deshace el borrado; sella igual, o no se propagaría |
| `editar_persona` | Edita una persona con las mismas reglas que `sync.php`; sella ambas marcas para que llegue a los celulares |
| `cerrar_sesiones` | Revoca los tokens de API de una cuenta (sus celulares deben volver a entrar) |
| `desbloquear_cuenta` | Borra los intentos fallidos de una cuenta |

Todas las acciones quedan en la tabla `auditoria_admin`, con antes y después cuando aplica. La contraseña nunca se registra. El panel envía `Content-Security-Policy` y solo carga recursos propios.

---

## Ejemplos con `curl`

```bash
BASE=https://encuestas.manuelcardenas.online/api

# 1 · Iniciar sesión
TOKEN=$(curl -s -X POST "$BASE/auth/login.php" \
  -H 'Content-Type: application/json' \
  -d '{"numero_documento":"1000000001","password":"Demo2026Salud"}' \
  | php -r 'echo json_decode(stream_get_contents(STDIN),true)["token"];')

# 2 · Descargar cambios
curl -s "$BASE/personas/cambios.php?desde=0&limite=50" \
  -H "Authorization: Bearer $TOKEN"

# 3 · Subir un lote vacío (comprueba conectividad y token)
curl -s -X POST "$BASE/personas/sync.php" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"personas":[],"encuestas":[]}'

# 4 · Cerrar sesión
curl -s -X POST "$BASE/auth/logout.php" -H "Authorization: Bearer $TOKEN"
```

> Estos comandos van en la **terminal**, no en la consola del navegador: `curl` no es JavaScript.

**Comprobación rápida de salud.** `cambios.php` sin token debe responder `401`. Si responde `500`, la API no logra conectar con la base: [`db.php`](../api/db.php) corta ahí antes de mirar el token.

---

## Documentos relacionados

- [Arquitectura](ARQUITECTURA.md) · [Historias de usuario](HISTORIAS-DE-USUARIO.md) · [Pendientes](PENDIENTES.md)
