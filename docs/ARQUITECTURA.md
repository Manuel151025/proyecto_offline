# Arquitectura

Documento de referencia técnica del sistema de encuestas *offline-first* del Ministerio de Salud.

> Los diagramas están en Mermaid. GitHub los renderiza directamente; en VS Code hace falta la extensión *Markdown Preview Mermaid Support*.

## Índice

1. [Panorama general](#1-panorama-general)
2. [Diagrama de componentes](#2-diagrama-de-componentes)
3. [Diagrama de despliegue](#3-diagrama-de-despliegue)
4. [Modelo de datos](#4-modelo-de-datos)
5. [Flujos principales](#5-flujos-principales)
6. [Decisiones de arquitectura](#6-decisiones-de-arquitectura)
7. [Estructura de carpetas](#7-estructura-de-carpetas)
8. [Estrategia de pruebas](#8-estrategia-de-pruebas)

---

## 1. Panorama general

El sistema resuelve un problema concreto: **encuestadores del Ministerio de Salud recogen datos demográficos en zonas rurales donde no hay señal**. La conectividad no es una condición para trabajar, es un evento que ocurre a veces.

De ahí se derivan las tres propiedades que gobiernan todo el diseño:

| Propiedad | Qué implica |
|---|---|
| **El dispositivo es la fuente de verdad temporal** | Se guarda y se sigue trabajando sin preguntarle nada a la red. |
| **Nada se pierde ante un cierre inesperado** | Todo lo pendiente vive en disco, no en memoria (patrón Outbox). |
| **Los conflictos se resuelven solos** | Dos encuestadores pueden tocar a la misma persona; el sistema decide sin intervención humana (Last-Write-Wins). |

Hay **dos clientes independientes** —una app Android nativa y una PWA— que escriben contra la **misma API**. No comparten código: cada uno tiene su propia base local, su propia cola y su propia implementación de la sincronización. Lo que sí comparten es el **contrato** (la API), las **reglas de resolución de conflictos**, las **reglas de validación** y la **paleta de diseño**: las reglas están replicadas deliberadamente en cada uno y una prueba de paridad exige que coincidan; la paleta sale de un único archivo (`design/tokens.json`).

---

## 2. Diagrama de componentes

```mermaid
graph TB
    subgraph Android["📱 App Android · Kotlin + Compose"]
        direction TB
        AP["<b>Presentation</b><br/>4 pantallas Compose<br/>ViewModels · StateFlow"]
        AD["<b>Domain</b><br/>casos de uso<br/>DecisionMezcla · Validaciones<br/><i>Kotlin puro, sin framework</i>"]
        ADA["<b>Data</b><br/>Repositorios · Mappers · DTOs"]
        ARoom[("<b>Room v4</b><br/>personas · encuestas<br/>cola_sincronizacion · municipios")]
        APrefs[["SharedPreferences<br/><i>token · marca de descarga</i>"]]
        AW["<b>SyncWorker</b><br/>WorkManager + backoff"]
        AP --> AD
        ADA -. implementa .-> AD
        ADA --> ARoom
        ADA --> APrefs
        AW --> AD
    end

    subgraph PWA["🌐 PWA · JavaScript sin framework"]
        direction TB
        PS["<b>Pantallas</b><br/>login · inicio · formulario · envío"]
        PV["<b>validacion.js</b><br/>limpiarCampo · validarPersona"]
        PR["router.js<br/><i>hash routing</i>"]
        PSync["<b>sync.js</b><br/>subida por lotes + descarga<br/>repartirRespuesta"]
        PDB["<b>db.js</b><br/>decidirMezcla"]
        PIDB[("<b>IndexedDB</b><br/>personas · sync_queue<br/>credenciales · municipios")]
        PSW["<b>Service Worker</b> v20<br/><i>HTML red-primero</i><br/><i>estáticos caché-primero</i><br/><i>subida en segundo plano</i>"]
        PR --> PS
        PS --> PV
        PS --> PSync
        PS --> PDB
        PSync --> PDB
        PDB --> PIDB
    end

    subgraph API["⚙️ API REST · PHP 8 sin framework"]
        direction TB
        CORS["<b>cors.php</b><br/>lista blanca · cabeceras"]
        AUTH["<b>auth_token.php</b><br/>emite y valida tokens<br/>devuelve rol"]
        RL["<b>rate_limit.php</b><br/>5 fallos → 15 min"]
        ESQ["<b>esquema.php</b><br/>automigración"]
        LOGIN["auth/login.php<br/><i>bcrypt → token</i>"]
        LOGOUT["auth/logout.php<br/><i>revoca</i>"]
        VAL["<b>personas/validacion.php</b><br/>reglas por campo · DatoInvalido"]
        SYNC["personas/sync.php<br/><i>SUBIDA · LWW · transacción</i>"]
        CAMB["personas/cambios.php<br/><i>BAJADA · marca de agua</i>"]
        MUNI["municipios/index.php"]
        ADMIN["admin/index.php<br/><i>panel · CSRF · sesión PHP</i><br/><i>vistas/: resumen · personas ·</i><br/><i>cuentas · sincronización · auditoría</i>"]
        ADMINQ["admin/consultas.php<br/><i>SQL del panel</i>"]
        LOGIN --> AUTH
        LOGIN --> RL
        LOGOUT --> AUTH
        SYNC --> AUTH
        CAMB --> AUTH
        SYNC --> ESQ
        CAMB --> ESQ
        SYNC --> VAL
        ADMIN --> VAL
        ADMIN --> RL
        ADMIN --> ADMINQ
    end

    DB[("🗄️ <b>MySQL 8.4</b><br/>municipios · encuestadores<br/>personas · encuestas<br/>sesiones · intentos_login<br/>dispositivos · sync_rechazos<br/>auditoria_admin · encuestador_municipios")]

    AW ==>|"POST · Bearer"| SYNC
    AW ==>|"GET · Bearer"| CAMB
    ADA -->|login / logout| LOGIN
    ADA --> MUNI
    PSync ==>|"POST · Bearer"| SYNC
    PSync ==>|"GET · Bearer"| CAMB
    PS -->|login / logout| LOGIN

    LOGIN --> DB
    LOGOUT --> DB
    SYNC --> DB
    CAMB --> DB
    MUNI --> DB
    ADMINQ --> DB
    RL --> DB
    ESQ --> DB

    style AD fill:#1B7A4B,color:#fff
    style AUTH fill:#B3261E,color:#fff
    style RL fill:#B3261E,color:#fff
    style SYNC fill:#12467E,color:#fff
    style CAMB fill:#12467E,color:#fff
    style DB fill:#12467E,color:#fff
    style PSync fill:#1B7A4B,color:#fff
    style PDB fill:#1B7A4B,color:#fff
    style VAL fill:#B4532A,color:#fff
    style PV fill:#B4532A,color:#fff
```

### Responsabilidades y fronteras

Lo que un componente **no** hace suele importar más que lo que hace: es la frontera lo que mantiene el diseño en pie.

| Componente | Responsabilidad | Qué NO hace |
|---|---|---|
| `Domain` (Android) | Reglas de negocio, validaciones, resolución de mezcla | No conoce Room, Retrofit ni Android. Por eso se puede probar en la JVM sin emulador. |
| `Data` (Android) | Persistencia y red; implementa las interfaces del dominio | No decide reglas de negocio |
| `Presentation` (Android) | Pintar estado y recoger eventos | No contiene lógica de negocio |
| `SyncWorker` | Reintentar con backoff cuando hay red | No transforma datos |
| `cors.php` | Lista blanca de orígenes y cabeceras de seguridad | No autentica |
| `auth_token.php` | Emitir, validar y revocar tokens; resolver el rol | No decide qué puede hacer cada rol |
| `rate_limit.php` | Contar fallos y calcular bloqueo | No responde por sí mismo (devuelve segundos) |
| `esquema.php` | Crear columnas que falten | No mueve datos entre tablas |
| `validacion.php` | Reglas de cada campo, compartidas por la sincronización y el panel | No decide qué hacer con la fila rechazada (eso es de `sync.php`) |
| `sync.php` | Validar el lote, resolver LWW, transacción atómica | No confía en el `id_encuestador` del cliente |
| `cambios.php` | Entregar lo cambiado desde una marca | No decide qué conserva el cliente |
| Service Worker | Servir la app sin conexión | No cachea `/api/` |

### Las reglas duplicadas a propósito

Tres piezas están **replicadas**, y eso es intencional:

| Regla | Android | PWA | Servidor | Qué las mantiene iguales |
|---|---|---|---|---|
| Qué hacer con una persona descargada | [`DecisionMezcla.kt`](../app/src/main/java/com/minsalud/encuestas/domain/sync/DecisionMezcla.kt) | `decidirMezcla` en [`db.js`](../pwa/js/db.js) | — | `DecisionMezclaTest.kt` y `mezcla.test.mjs` cubren los mismos casos |
| Qué se reintenta tras un envío parcial | `SyncRepositoryImpl` | `repartirRespuesta` en [`sync.js`](../pwa/js/sync.js) | — | `SyncRepositoryImplTest` y `reparto.test.mjs` |
| Validación de cada campo | [`Validaciones.kt`](../app/src/main/java/com/minsalud/encuestas/domain/validation/Validaciones.kt) | [`validacion.js`](../pwa/js/validacion.js) | [`validacion.php`](../api/personas/validacion.php) | [`paridad.test.mjs`](../pwa/tests/paridad.test.mjs) lee las tres fuentes y falla si difieren |

No se comparte código porque las plataformas no lo permiten sin añadir un runtime intermedio que costaría más de lo que ahorra. Lo que sí se comparte es **la especificación y las pruebas**. Si un cliente cambia de criterio, otra suite lo delata.

---

## 3. Diagrama de despliegue

```mermaid
graph TB
    subgraph Campo["🏔️ Terreno · sin conectividad"]
        AND["📱 APK Android<br/><i>Room local</i>"]
        NAV["🌐 Navegador<br/><i>PWA instalada · IndexedDB</i>"]
    end

    subgraph VPS["☁️ VPS Ubuntu · srv1757722"]
        NGINX["<b>nginx del sistema</b><br/>:80 · :443<br/><i>TLS con acme.sh</i>"]
        subgraph Swarm["Docker Swarm · orquestado por Dokploy"]
            APP["<b>offline-offlineapp</b><br/>PHP 8 + Apache<br/><i>api/ + pwa/</i>"]
            MYSQL["<b>offline-offlinedb</b><br/>MySQL 8.4.10"]
            VOL[("volumen<br/><i>persiste entre despliegues</i>")]
            APP --> MYSQL
            MYSQL --- VOL
        end
        NGINX --> APP
    end

    GH["🐙 GitHub<br/><i>main</i>"]
    CI["⚙️ GitHub Actions<br/><i>android · php · pwa · e2e</i>"]

    AND -.->|"HTTPS<br/>cuando hay señal"| NGINX
    NAV -.->|HTTPS| NGINX
    GH --> CI
    CI -->|"si pasa"| GH
    GH ==>|"despliegue"| Swarm

    style NGINX fill:#12467E,color:#fff
    style APP fill:#1B7A4B,color:#fff
    style MYSQL fill:#12467E,color:#fff
    style CI fill:#5B6878,color:#fff
```

**URL de producción:** `https://encuestas.manuelcardenas.online`
· PWA en `/pwa/` · API en `/api/` · Panel en `/api/admin/`

### Detalles operativos que no son evidentes

- **Los nombres de contenedor cambian en cada despliegue.** Swarm añade un sufijo de tarea (`offline-offlinedb-hkxh38.1.<taskid>`). Hay que obtenerlos con `docker ps`, no fijarlos en un script.
- **El `docker-compose.yml` del repositorio no es lo que corre.** Declara `container_name` e `image: mysql:8.0`; en producción hay nombres de Swarm y MySQL 8.4. Editarlo no cambia producción.
- **El volumen de MySQL sobrevive a los despliegues.** Por eso `MYSQL_PASSWORD` del compose se ignora en cada redespliegue: solo lo aplica el arranque inicial sobre un volumen vacío. Ver [rotación de credenciales](#rotación-de-credenciales-de-base-de-datos) más abajo.
- **No hay acceso a consola de base de datos en el flujo normal.** Por eso los cambios de esquema se aplican solos (`api/esquema.php`).

---

## 4. Modelo de datos

### Servidor (MySQL)

```mermaid
erDiagram
    MUNICIPIOS ||--o{ PERSONAS : "ubica"
    ENCUESTADORES ||--o{ ENCUESTAS : "realiza"
    ENCUESTADORES ||--o{ SESIONES : "posee"
    PERSONAS ||--o{ ENCUESTAS : "es objeto de"
    ENCUESTADORES ||--o{ ENCUESTADOR_MUNICIPIOS : "descarga"
    MUNICIPIOS ||--o{ ENCUESTADOR_MUNICIPIOS : "asignado a"
    ENCUESTADORES ||--o{ DISPOSITIVOS : "usa"
    ENCUESTADORES ||--o{ AUDITORIA_ADMIN : "hace"

    MUNICIPIOS {
        varchar codigo PK "DIVIPOLA/DANE"
        varchar nombre
        varchar departamento
    }

    ENCUESTADORES {
        int id PK
        varchar nombre
        varchar numero_documento UK
        varchar password_hash "bcrypt"
        tinyint activo
        enum rol "encuestador | admin"
    }

    PERSONAS {
        varchar tipo_documento PK "CC TI RC CE PP NIT PE"
        varchar numero_documento PK
        varchar nombres
        varchar apellidos
        bigint fecha_nacimiento "ms UTC"
        varchar telefono
        varchar email
        varchar direccion
        varchar vereda
        varchar eps
        varchar ocupacion
        int estrato
        varchar municipio_codigo FK
        bigint updated_at "reloj del DISPOSITIVO · LWW"
        bigint server_updated_at "reloj del SERVIDOR · marca de agua"
        varchar device_id "autor del último cambio"
        bigint deleted_at "borrado suave"
    }

    ENCUESTAS {
        varchar id PK "UUID del dispositivo"
        varchar tipo_documento FK
        varchar numero_documento FK
        int id_encuestador FK "del TOKEN, no del payload"
        bigint fecha_encuesta
        varchar device_id
        varchar accion "CREACION | ACTUALIZACION"
        bigint server_sync_time
    }

    SESIONES {
        int id PK
        char token_hash UK "SHA-256, nunca el token"
        int id_encuestador FK
        bigint creado_en
        bigint expira_en "30 días"
        bigint ultimo_uso
    }

    INTENTOS_LOGIN {
        int id PK
        varchar documento "clave del contador"
        bigint creado_en
    }

    DISPOSITIVOS {
        varchar device_id PK
        int id_encuestador
        varchar plataforma "android | pwa"
        varchar version_app
        bigint ultima_subida
        bigint ultima_descarga
        bigint ultima_actividad
    }

    SYNC_RECHAZOS {
        int id PK
        varchar id_encuesta
        varchar tipo_documento
        varchar numero_documento
        varchar motivo "texto de DatoInvalido"
        varchar device_id
        int id_encuestador
        bigint creado_en
    }

    AUDITORIA_ADMIN {
        int id PK
        int id_admin
        varchar nombre_admin
        varchar accion "entrar · borrar · crear cuenta…"
        varchar objeto
        text detalle
        bigint creado_en
    }

    ENCUESTADOR_MUNICIPIOS {
        int id_encuestador PK
        varchar municipio_codigo PK
    }
```

Las cuatro últimas tablas las crea `api/esquema.php` la primera vez que se necesitan (ver [ADR-05](#adr-05--automigración-de-esquema)): no hace falta tocar la base a mano.

### Las dos marcas de tiempo

Es la decisión de diseño más importante del sistema y la más fácil de malinterpretar:

| Campo | Lo pone | Para qué sirve | Por qué no sirve para lo otro |
|---|---|---|---|
| `updated_at` | El **dispositivo** | Resolver conflictos (LWW) | No sirve como marca de agua: un teléfono con el reloj atrasado escribiría filas con fecha vieja que los demás ya superaron, y **esas filas no se descargarían nunca**. |
| `server_updated_at` | El **servidor** | Marca de agua de la descarga incremental | No sirve para LWW: todos los cambios de un mismo lote comparten sello, así que no distingue cuál es más reciente en origen. |

El fallo que evita el segundo campo **no produce ningún error**: los datos simplemente no llegan, y nadie se entera.

### Cliente Android (Room v4)

Espeja las tablas del servidor y añade la cola:

| Tabla | Notas |
|---|---|
| `personas` | Clave compuesta. Índice `(deleted_at, updated_at)` para el listado. |
| `encuestas` | Historial local. |
| `cola_sincronizacion` | Outbox. Estados `PENDING · SENT · ERROR · RECHAZADO`. |
| `municipios` | Catálogo, sembrado localmente. |

**Migraciones:** v1→v2 añade `vereda`; v2→v3 crea el índice de listado; v3→v4 añade `server_updated_at`. Ninguna destructiva. El esquema exportado vive en [`app/schemas/`](../app/schemas/) y CI lo valida en cada compilación.

### Cliente PWA (IndexedDB)

| Almacén | Contenido |
|---|---|
| `personas` | Clave compuesta `[tipo_documento, numero_documento]`, más el campo local `_pendingSync`. |
| `sync_queue` | Outbox con `status` y el motivo del rechazo. |
| `credenciales` | Documento y hash para el inicio de sesión sin red. |
| `municipios` | Catálogo. |

---

## 5. Flujos principales

### 5.1 Registro sin conexión (patrón Outbox)

```mermaid
sequenceDiagram
    autonumber
    actor U as Encuestador
    participant A as App (local)
    participant C as Cola (outbox)
    participant W as SyncWorker
    participant S as sync.php
    participant D as MySQL

    U->>A: Guarda el formulario
    rect rgb(232, 245, 238)
        Note over A,C: Una sola transacción atómica
        A->>A: Persiste persona + encuesta
        A->>C: Encola evento PENDING
    end
    A-->>U: Guardado ✓ (sin esperar red)

    Note over C,W: Más tarde, al recuperar señal
    W->>C: Lee pendientes (lotes de 100)
    W->>S: POST · Authorization: Bearer

    alt Token ausente o inválido
        S-->>W: 401 → no se reintenta solo
    else Token válido
        S->>S: Valida fila por fila
        S->>D: BEGIN
        loop Cada persona del lote
            alt updated_at entrante es mayor
                S->>D: UPDATE (gana el más nuevo)
            else El servidor tiene una versión más nueva
                S->>D: Ignora el entrante
            end
        end
        S->>D: INSERT IGNORE encuestas
        S->>D: COMMIT
        S-->>W: 200 · processed_encuestas + rechazadas
        W->>C: SENT lo confirmado
        W->>C: RECHAZADO lo inválido (terminal)
    end
```

**Lo que protege el paso 3–4:** si la app muere entre guardar y enviar, la cola sobrevive en disco. El registro nunca queda solo en memoria.

**Lo que protege el último paso:** solo se marca `SENT` lo que el servidor confirma. Y lo que rechaza se marca **terminal**, para que un registro irreparable no se reintente en cada sincronización arrastrando a su lote.

### 5.2 Sincronización bidireccional

```mermaid
sequenceDiagram
    autonumber
    participant C as Cliente
    participant S as Servidor

    Note over C,S: 1 · SUBIR primero
    C->>S: POST sync.php {personas, encuestas}
    S-->>C: processed_encuestas · rechazadas

    Note over C,S: 2 · BAJAR después
    loop Hasta hay_mas = false (máx. 10 páginas)
        C->>S: GET cambios.php?desde=marca&limite=200
        S-->>C: personas · marca nueva · hay_mas
        C->>C: Mezclar según decidirMezcla
        C->>C: Guardar la marca nueva
    end
```

**El orden importa.** Subiendo primero, el servidor ya conoce los cambios locales cuando se le pregunta, y se evita un conflicto que no hacía falta tener.

### 5.3 Regla de mezcla al descargar

```mermaid
flowchart TD
    A[Llega una persona del servidor] --> B{¿Existe en local?}
    B -->|No| G[GUARDAR]
    B -->|Sí| C{¿Tiene cambios<br/>sin enviar?}
    C -->|Sí| K["CONSERVAR<br/><i>nunca se pisa el trabajo de campo</i>"]
    C -->|No| D{"¿updated_at remoto ><br/>updated_at local?"}
    D -->|Sí| G
    D -->|No| K

    style G fill:#1B7A4B,color:#fff
    style K fill:#12467E,color:#fff
```

La rama del medio es la que evita perder datos: un registro pendiente todavía no llegó al servidor, así que lo que vuelve es por fuerza anterior. Sobrescribirlo borraría una encuesta recién hecha **sin producir ningún error**.

### 5.4 Autenticación y roles

```mermaid
sequenceDiagram
    autonumber
    actor E as Usuario
    participant CL as Cliente
    participant L as login.php
    participant RL as rate_limit.php
    participant D as MySQL

    E->>CL: documento + contraseña
    CL->>L: POST /api/auth/login.php
    L->>L: Valida formato del documento
    L->>RL: ¿bloqueado?
    alt 5 fallos en 15 min
        RL-->>CL: 429 · Retry-After
    else
        L->>D: SELECT ... WHERE numero_documento
        alt Credenciales incorrectas
            L->>RL: Registra el fallo
            L-->>CL: 401 (mensaje genérico)
        else Correctas
            L->>D: INSERT sesiones (solo el HASH del token)
            L-->>CL: token · expira_en · rol
            CL->>CL: Guarda token y credenciales locales
        end
    end

    Note over CL,D: Después, en cada escritura
    CL->>D: Authorization: Bearer <token>
```

El token se guarda en el servidor **solo como hash SHA-256**: una filtración de la tabla `sesiones` no permite suplantar a nadie. La vigencia es de 30 días porque el token se emite con conectividad y viaja con el dispositivo al terreno.

### 5.5 Arranque del panel de administración

```mermaid
stateDiagram-v2
    [*] --> SinAdmins: primer despliegue
    SinAdmins --> ConClaveCompartida: entra con ADMIN_PASSWORD
    ConClaveCompartida --> PrimerAdminCreado: crea cuenta con rol admin
    PrimerAdminCreado --> ConCuenta: sesión cerrada, entra con documento
    ConCuenta --> ConCuenta: ADMIN_PASSWORD ya no se acepta

    note right of SinAdmins
        contarAdminsActivos() == 0
    end note
    note right of ConCuenta
        La variable se puede
        borrar del entorno
    end note
```

El secreto compartido **se apaga solo**. No hay que acordarse de retirar nada para que deje de valer.

### 5.6 Validación de un registro, en tres capas

```mermaid
flowchart TD
    T["El encuestador escribe en un campo"] --> F{"limpiarCampo<br/><i>¿el carácter cabe en el campo?</i>"}
    F -->|No| X["No entra<br/><i>letras en el teléfono, números en el nombre,<br/>más largo que el máximo</i>"]
    F -->|Sí| G["Toca Guardar"]
    G --> V{"validarPersona<br/><i>largos mínimos, formato por tipo de documento,<br/>teléfono 3… o 60…, fecha ≤ hoy</i>"}
    V -->|Falla| E["Error debajo de cada campo<br/><i>no se guarda nada</i>"]
    E --> T
    V -->|Pasa| L["Se guarda en el teléfono<br/>+ evento PENDING en la cola"]
    L --> S{"Servidor: validacion.php<br/><i>mismas reglas</i>"}
    S -->|Pasa| OK["Persona guardada · SENT"]
    S -->|Falla| R["Fila rechazada con su motivo<br/>RECHAZADO (terminal) · sync_rechazos"]
    R --> C["El encuestador la ve en «Envío de datos»<br/>y la corrige con «Corregir»"]

    style X fill:#B3261E,color:#fff
    style E fill:#B3261E,color:#fff
    style R fill:#B3261E,color:#fff
    style OK fill:#1E6B44,color:#fff
    style L fill:#12467E,color:#fff
```

Las dos primeras capas existen para que la tercera casi nunca actúe: un rechazo del servidor llega cuando el encuestador ya no está frente a la persona. La tercera existe porque la validación del cliente no protege nada frente a quien llama a la API con `curl`.

| Campo | Regla |
|---|---|
| Documento | Solo dígitos según el tipo: CC 6–10 · TI y RC 10–11 · CE 6–10 · NIT 9–10 · PE 6–15. Solo el pasaporte (PP, 6–12) admite letras. |
| Nombres y apellidos | Obligatorios, 2 a 60 caracteres: letras (con tildes y ñ), espacios, guion o apóstrofo. |
| Teléfono | Opcional. Celular de 10 dígitos que empieza por 3, o fijo de 10 que empieza por 60. |
| Correo | Opcional. Forma `algo@dominio.tld`, hasta 100. |
| Dirección · vereda · EPS · ocupación | Opcionales. Largo mínimo (5 · 3 · 3 · 3) y máximo (150 · 100 · 50 · 60), caracteres permitidos y al menos una letra. |
| Fecha de nacimiento | Opcional. Entre el 1 de enero de 1900 y hoy. |
| Estrato | Opcional. Entero de 1 a 6. |
| Municipio | Opcional. Debe existir en el catálogo. |

### 5.7 Descarga limitada por municipios

```mermaid
flowchart LR
    A["GET cambios.php<br/>con el token del encuestador"] --> B{"¿Tiene municipios<br/>asignados?"}
    B -->|No| T["Recibe todas las personas"]
    B -->|Sí| M["Recibe solo las de sus municipios<br/>+ las que él mismo registró"]

    style M fill:#12467E,color:#fff
```

Un encuestador no necesita en su teléfono los datos de salud de todo el país. El administrador asigna municipios desde *Cuentas*; sin asignación, la cuenta descarga todo (comportamiento anterior, para no romper a nadie).

### 5.8 Sistema de diseño: una paleta, tres superficies

```mermaid
flowchart LR
    T[("design/tokens.json<br/><i>única fuente de la paleta</i>")] --> S["node scripts/tokens.mjs"]
    S --> P["pwa/css/base.css<br/><i>--primary, --accent…</i>"]
    S --> A["api/admin/admin.css<br/><i>--primary, --acento…</i>"]
    S --> K["Theme.kt<br/><i>BrandPrimary, BrandAccent…</i>"]
    CI["CI: tokens.mjs --verificar"] -.->|falla si alguien<br/>edita un color a mano| P & A & K

    style T fill:#B4532A,color:#fff
```

La dirección visual es **«Cálida de territorio»**: azul institucional `#12467E`, fondo cálido `#F6F4EF` y un acento terracota `#B4532A` reservado para la acción de registrar y el día de hoy en los gráficos. La fuente **Figtree** va empaquetada en las tres superficies (`pwa/fonts/`, `res/font/`): no se descarga nada de terceros, así que la app se ve igual sin señal y no se filtra la IP de nadie a Google Fonts.

### 5.9 Catálogos de municipios y EPS

```mermaid
flowchart LR
    F[("database/catalogos/<br/>municipios.json · eps.json<br/><i>única fuente</i>")] --> G["node scripts/catalogos.mjs"]
    G --> P["pwa/data/*.json<br/><i>cacheados por el service worker</i>"]
    G --> A["assets/catalogos/*.json<br/><i>dentro del APK</i>"]
    G --> S["api/municipios/catalogo.php"]
    G --> Q["database/schema.sql<br/><i>bases nuevas</i>"]
    S --> E["asegurarCatalogoMunicipios()<br/><i>completa y corrige la tabla<br/>cuando cambia la versión</i>"]
    CI["CI: catalogos.mjs --verificar"] -.-> P & A & S & Q

    style F fill:#B4532A,color:#fff
```

**El problema que resuelve.** La PWA pedía los municipios a la API una sola vez y los guardaba para siempre; Android sembraba unos 200 escritos a mano solo si la tabla estaba vacía; el servidor tenía 162. Un teléfono podía quedarse con 3 departamentos y tildes dañadas sin forma de corregirlo.

**Ahora.** Una sola fuente con los **1.122 municipios** del DANE (DIVIPOLA) y las **EPS** que operan en Colombia más los regímenes especiales. Cada copia lleva una versión (huella del contenido): la PWA la recibe con cada versión de la app; Android reemplaza su tabla cuando la versión cambia; el servidor completa la suya en la primera petición.

**Búsqueda.** En los dos clientes el municipio y la EPS se eligen escribiendo, no bajando por una lista: «popa» → Popayán, «cauca» → los municipios del Cauca con la capital primero, «cali» → Santiago de Cali. Sin tildes ni mayúsculas. El algoritmo está en `catalogos.js` (PWA) y `BuscadorCatalogo.kt` (Android), con los mismos casos de prueba. Sin escribir nada se ofrecen las 90 ciudades principales (capitales y las más pobladas). La EPS admite texto libre si no está en la lista.

---

## 6. Decisiones de arquitectura

### ADR-01 · Last-Write-Wins en lugar de CRDT o resolución manual

**Contexto.** Dos encuestadores pueden visitar a la misma persona el mismo día, sin señal, y ambos editarla.

**Decisión.** El servidor compara `updated_at` y conserva el más reciente. Sin intervención humana.

**Alternativas descartadas.** Un CRDT resolvería a nivel de campo sin perder nada, pero exige estructuras de datos que el equipo tendría que mantener en Kotlin, JavaScript y PHP a la vez. La resolución manual pondría a un funcionario a arbitrar diferencias entre encuestas, que es exactamente el trabajo que el sistema debe evitar.

**Consecuencia aceptada.** Si dos personas editan campos distintos del mismo registro, se pierde la edición más antigua **completa**, no solo el campo en conflicto. Se asume porque en el terreno real dos encuestadores rara vez cubren la misma vereda el mismo día.

### ADR-02 · Dos marcas de tiempo separadas

**Contexto.** La descarga incremental necesita preguntar "dame lo cambiado desde X".

**Decisión.** `updated_at` (dispositivo) para LWW; `server_updated_at` (servidor) como marca de agua.

**Por qué no basta una.** Con solo `updated_at`, un teléfono con el reloj atrasado escribe filas fechadas por debajo de la marca que otros dispositivos ya tienen. Esas filas **jamás se descargarían**, y el fallo no genera error: el dato simplemente no aparece.

### ADR-03 · Rechazo por fila, no por lote

**Contexto.** `sync.php` validaba con una función que hace `exit`. Una fila inválida devolvía 400 y tumbaba el envío entero, de hasta 500 registros. El cliente reintentaba el mismo lote indefinidamente.

**Decisión.** Las validaciones por fila lanzan `DatoInvalido`, la fila se descarta y el lote continúa. La respuesta incluye `rechazadas` con el motivo, y el cliente las marca **terminales**.

**Consecuencia.** Un registro irreparable ya no bloquea la cola de un encuestador para siempre.

### ADR-04 · Límite de intentos por documento, no por IP

**Contexto.** La aplicación corre detrás de un proxy inverso, así que `REMOTE_ADDR` es la IP del proxy y es la misma para todos.

**Decisión.** Contar por documento. Para el panel, que no pide usuario, un contador global bajo la clave `#admin`.

**Por qué no por IP.** Bloquearía a todos los encuestadores a la vez: una denegación de servicio autoinfligida. `X-Forwarded-For` no sirve sin conocer la cadena exacta de proxies, porque un cliente puede falsificarla.

**Consecuencia aceptada.** Con contador global, alguien puede mantener el panel bloqueado a base de intentos fallidos. Se prefiere a permitir fuerza bruta ilimitada sobre un panel que puede borrar datos.

### ADR-05 · Automigración de esquema

**Contexto.** El despliegue no tiene acceso SSH ni consola de base de datos. Una migración manual dejaría una ventana con el código nuevo desplegado y la tabla vieja.

**Decisión.** `api/esquema.php` comprueba `information_schema` y crea la columna que falte en la primera petición que la necesite.

**Por qué `information_schema` y no `try/catch`.** En MySQL un `ALTER TABLE` provoca *commit* implícito. Ejecutarlo dentro de la transacción de sincronización partiría el lote a la mitad.

### ADR-06 · Borrado suave, siempre

**Contexto.** El panel necesita retirar registros que ningún cliente puede tocar.

**Decisión.** Marcar `deleted_at` y sellar `updated_at` + `server_updated_at`.

**Por qué no `DELETE`.** `cambios.php` entrega las filas borradas a propósito, para que los dispositivos se enteren. Un `DELETE` real quita la fila y no queda nada que enviar: **cada teléfono que ya la descargó se la queda para siempre**, sin forma de corregirlo desde el servidor.

### ADR-07 · Cuentas con rol en lugar de contraseña compartida

**Contexto.** El panel autenticaba contra una variable de entorno. No sabía quién entraba, así que ninguna acción administrativa tenía autor.

**Decisión.** Columna `rol` en `encuestadores`. `ADMIN_PASSWORD` solo se acepta mientras no exista ninguna cuenta admin activa.

**Consecuencia.** Al borrar una persona queda `admin:<nombre>` en `device_id`, no `panel-admin`.

### ADR-08 · La PWA sin framework

**Contexto.** La PWA debe cargar y funcionar en gama baja con conexión intermitente.

**Decisión.** JavaScript con módulos ES nativos, sin build step ni dependencias en tiempo de ejecución.

**Consecuencia.** No hay `node_modules` que servir ni bundle que invalidar; el Service Worker cachea archivos reales. El coste es que no hay reactividad automática: las pantallas se repintan a mano.

### ADR-09 · Validación replicada en tres lugares, con prueba de paridad

**Contexto.** El teléfono debe rechazar un dato malo mientras el encuestador sigue frente a la persona; el servidor no puede confiar en ningún cliente.

**Decisión.** Las reglas viven en `validacion.js`, `Validaciones.kt` y `validacion.php`, con la misma tabla de formatos. `paridad.test.mjs` lee las tres y falla si difieren.

**Alternativa descartada.** Un esquema JSON compartido exigiría un intérprete en cada plataforma y no cubre el filtro tecla a tecla.

**Consecuencia aceptada.** Cambiar una regla obliga a tocar tres archivos; la prueba de paridad hace imposible olvidarse de uno.

### ADR-10 · Paleta en un archivo y fuente empaquetada

**Contexto.** La paleta estaba copiada a mano en la PWA, el panel y Android, y se desincronizaba.

**Decisión.** `design/tokens.json` es la única fuente; `scripts/tokens.mjs` reescribe las tres y CI verifica que estén al día. Figtree se sirve desde el propio servidor y dentro del APK.

**Consecuencia.** Un cambio de marca es un cambio en un archivo. La app no depende de ninguna red de terceros para verse bien.

### ADR-11 · Pruebas de punta a punta sin Playwright

**Contexto.** Faltaba una prueba que recorriera lo que hace una persona: panel → app → sin señal → con señal → panel.

**Decisión.** `tests/e2e/` controla Chrome o Edge directamente por el protocolo DevTools con el `WebSocket` nativo de Node 22, sobre la misma base desechable de PHPUnit.

**Por qué no Playwright.** Sumaría cientos de megas de dependencias a un proyecto que hoy no tiene `node_modules`, para usar una fracción mínima.

### Rotación de credenciales de base de datos

Cambiar `DB_PASS` en el entorno **no cambia la contraseña de MySQL**: el `MYSQL_PASSWORD` del compose solo lo aplica el arranque sobre un volumen vacío. Secuencia sin corte de servicio (MySQL 8.0.14+):

```sql
-- 1 · como root, dentro del contenedor de la base
ALTER USER 'encuestas_user'@'%' IDENTIFIED BY 'nueva' RETAIN CURRENT PASSWORD;
-- 2 · cambiar DB_PASS en el entorno y redesplegar
-- 3 · verificar que la API responde 401 (no 500) en cambios.php
ALTER USER 'encuestas_user'@'%' DISCARD OLD PASSWORD;
```

`RETAIN CURRENT PASSWORD` exige el privilegio `APPLICATION_PASSWORD_ADMIN`: un usuario no puede hacérselo a sí mismo. Y entrando como root, `USER()` apunta a **root** — hay que nombrar la cuenta literal o se le cambia la contraseña a quien no es.

---

## 7. Estructura de carpetas

```
proyecto_offline/
├── api/                         # API REST en PHP, sin framework
│   ├── admin/
│   │   ├── index.php            # Panel: acceso, acciones y estructura
│   │   ├── vistas/              # resumen · personas · persona · cuentas · sincronización · auditoría
│   │   ├── vista.php            # ayudantes de HTML (iconos, avisos, fechas)
│   │   ├── admin.css admin.js   # estilos y mejoras progresivas
│   │   └── consultas.php        # Todo el SQL del panel
│   ├── auth/
│   │   ├── login.php            # bcrypt → token
│   │   └── logout.php           # revoca el token
│   ├── personas/
│   │   ├── sync.php             # SUBIDA · LWW · transacción
│   │   ├── cambios.php          # BAJADA · marca de agua · por municipios
│   │   └── validacion.php       # reglas por campo (sync y panel)
│   ├── municipios/index.php
│   ├── auth_token.php           # emitir / validar / rol
│   ├── cors.php                 # lista blanca + cabeceras
│   ├── db.php                   # conectarBD()
│   ├── esquema.php              # automigración
│   └── rate_limit.php           # anti fuerza bruta
│
├── app/                         # Android nativo · Clean Architecture
│   └── src/main/java/com/minsalud/encuestas/
│       ├── domain/              # Kotlin puro
│       │   ├── model/ usecase/ repository/
│       │   ├── sync/            # DecisionMezcla
│       │   └── validation/
│       ├── data/
│       │   ├── local/           # Room · DAOs · entidades · prefs
│       │   ├── remote/          # Retrofit · DTOs
│       │   ├── repository/      # implementaciones
│       │   └── mapper/
│       ├── presentation/        # Compose · ViewModels · theme
│       ├── di/                  # Hilt
│       └── worker/              # SyncWorker
│
├── pwa/                         # PWA sin framework
│   ├── css/                     # 7 hojas por responsabilidad
│   ├── fonts/                   # Figtree empaquetada
│   ├── js/
│   │   ├── screens/             # login · inicio · formulario · envío
│   │   ├── db.js                # IndexedDB + decidirMezcla + resumen del día
│   │   ├── sync.js              # subida/bajada + repartirRespuesta
│   │   ├── validacion.js        # limpiarCampo + validarPersona
│   │   └── api.js router.js session.js utils.js
│   ├── tests/                   # node --test, sin dependencias (incluye paridad)
│   └── sw.js                    # Service Worker
│
├── tests/
│   ├── php/                     # PHPUnit: integración (API y panel) + unitarias de validación
│   └── e2e/                     # navegador real: panel → app → sin señal → panel
│
├── design/tokens.json           # paleta: única fuente
├── database/catalogos/          # municipios (DIVIPOLA) y EPS: única fuente
├── scripts/                     # tokens.mjs · catalogos.mjs · check-pwa-assets.mjs
├── database/
│   ├── schema.sql               # esquema completo + municipios DANE
│   └── migrations/              # histórico versionado
│
├── docs/                        # esta documentación (+ capturas/)
└── .github/workflows/ci.yml     # android · php · pwa · e2e
```

---

## 8. Estrategia de pruebas

```mermaid
flowchart BT
    U["<b>Unitarias</b><br/>Android JVM · PWA (node --test) · PHP (validación)<br/><i>reglas, mezcla, reparto, fechas, filtros</i>"]
    I["<b>Integración</b><br/>PHPUnit + MySQL real + servidor embebido<br/><i>sync, cursor, LWW, rechazos, panel, CSV, cuentas</i>"]
    E["<b>Punta a punta</b><br/>tests/e2e · Chrome/Edge real<br/><i>panel → app → sin señal → con señal → panel</i>"]
    G["<b>Guardas de CI</b><br/><i>fallos ya corregidos no pueden volver</i>"]
    C["<b>Pruebas de campo</b><br/>celular real · docs/PRUEBAS-DE-CAMPO.md"]
    U --> I --> E --> C
    G -.-> U & I & E
```

| Capa | Dónde | Qué asegura |
|---|---|---|
| Unitarias | `app/src/test`, `pwa/tests`, `tests/php/ValidacionTest.php` | Cada regla por separado, con casos límite y en dos husos horarios |
| Paridad | `pwa/tests/paridad.test.mjs` | Que las reglas de validación sean idénticas en PWA, servidor y Android |
| Integración | `tests/php/*Test.php` | La API y el panel contra MySQL real, en modo estricto como producción |
| Punta a punta | `tests/e2e/e2e.test.mjs` | El recorrido completo de una persona, en un navegador real |
| Estáticas | PHPStan nivel 8, Android Lint, `check-pwa-assets`, `tokens --verificar` | Tipos, caché offline completa y paleta sin desviaciones |
| Campo | [PRUEBAS-DE-CAMPO.md](PRUEBAS-DE-CAMPO.md) | Lo que solo un celular real revela: señal intermitente, batería, WorkManager |

El inventario completo y cómo correr cada suite está en [PRUEBAS.md](PRUEBAS.md).

---

## Documentos relacionados

- [Historias de usuario](HISTORIAS-DE-USUARIO.md) — qué hace el sistema, con criterios de aceptación
- [Referencia de la API](API.md) — endpoints, parámetros y respuestas
- [Pruebas](PRUEBAS.md) — inventario de pruebas y cómo correrlas
- [Manual de uso](MANUAL.md) — guía para encuestadores y administradores
- [Pendientes y hoja de ruta](PENDIENTES.md) — qué falta y por qué
- [README](../README.md) — puesta en marcha
