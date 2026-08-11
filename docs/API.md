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

---

## `GET /personas/cambios.php`

Descarga incremental de personas.

**Parámetros**

| Nombre | Tipo | Defecto | Descripción |
|---|---|---|---|
| `desde` | entero | `0` | Marca de agua del cliente: mayor `server_updated_at` ya recibido |
| `limite` | entero | `200` | Máximo por página (tope 500) |

```http
GET /api/personas/cambios.php?desde=1786387933257&limite=200
Authorization: Bearer <token>
```

**Respuesta `200`**

```json
{
  "success": true,
  "personas": [ { "…": "…", "server_updated_at": 1786469459500 } ],
  "marca": 1786469459500,
  "hay_mas": false
}
```

| Campo | Significado |
|---|---|
| `personas` | Filas cambiadas, ordenadas por `server_updated_at` ascendente. **Incluye las borradas**, con `deleted_at` informado. |
| `marca` | Nueva marca de agua. Si no vino nada, se devuelve la que el cliente ya tenía, para que no retroceda. |
| `hay_mas` | La página venía llena: probablemente quedan más. |

**Por qué se filtra por `server_updated_at` y no por `updated_at`**

`updated_at` lo pone el dispositivo. Un teléfono con el reloj atrasado escribiría filas con fecha anterior a la marca que los demás ya superaron: **esas filas no se descargarían nunca y el dato se perdería en silencio**. El sello del servidor avanza siempre hacia adelante.

**Por qué se devuelven las borradas**

Un borrado es un cambio que los demás dispositivos deben conocer. Omitirlas haría reaparecer lo eliminado en la siguiente subida.

**Uso correcto en el cliente**

1. Subir primero, bajar después.
2. Paginar mientras `hay_mas` sea verdadero (con tope, como cortafuegos).
3. Guardar la marca **después** de mezclar: si la aplicación muere a mitad, se repite la página en vez de saltársela.
4. **Nunca sobrescribir un registro con cambios locales sin enviar.**

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

Mismo límite de intentos que la API: 5 fallos → 15 minutos. Estando bloqueado, **incluso la contraseña correcta se rechaza**.

### Secciones

| Ruta | Contenido |
|---|---|
| `?seccion=resumen` | Totales, encuestas por día, personas por municipio, encuestas por encuestador |
| `?seccion=personas` | Listado con búsqueda y paginación |
| `?seccion=personas&borradas=1` | Papelera, con restauración |
| `?seccion=encuestadores` | Cuentas: crear, editar, rol, activar |
| `?exportar=personas` | Descarga CSV con BOM UTF-8 |

### Acciones (POST)

| `action` | Efecto |
|---|---|
| `login` / `logout` | Sesión del panel |
| `save` | Crear o editar cuenta. Rechaza quitar el rol o desactivar al **único** admin activo. |
| `borrar_persona` | Borrado suave; sella ambas marcas; registra `admin:<nombre>` |
| `restaurar_persona` | Deshace el borrado; sella igual, o no se propagaría |

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
