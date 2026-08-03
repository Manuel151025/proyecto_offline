# Sistema de Encuestas - Offline First 📡

## Descripción
App Android nativa para el Ministerio de Salud, diseñada específicamente para funcionar en entornos rurales sin conectividad. Permite a los encuestadores recopilar y actualizar datos demográficos sin conexión a internet y sincronizarlos automáticamente mediante procesos en background cuando el dispositivo recupera la red.

## Objetivo
Garantizar la recolección íntegra de datos sobre el terreno y prevenir la pérdida o duplicación de información frente a concurrencia, resolviendo conflictos de manera autónoma.

## Arquitectura
El proyecto respeta rigurosamente **Clean Architecture**:
- **Presentation**: Jetpack Compose, ViewModels (StateFlow) e inyección con Hilt. No contiene lógica de negocio.
- **Domain**: Kotlin puro. Contiene los Casos de Uso (Use Cases), Modelos (Entities de negocio puras), abstracciones transaccionales y envolturas `Result<T>`. Totalmente agnóstico del framework.
- **Data**: Implementa los Repositorios de Dominio, manejando la persistencia local (Room) y la red (Retrofit). Utiliza Mappers para traducir hacia y desde el Dominio.

## Tecnologías Utilizadas (App Android)
- **Kotlin & Coroutines/Flow**: Asincronía y reactividad.
- **Jetpack Compose**: UI declarativa (Material Design 3).
- **Navigation Compose**: Enrutamiento sin Fragments.
- **Room Database**: Persistencia SQLite local sin destrucciones no controladas.
- **Retrofit & OkHttp**: Consumo de API REST.
- **Dagger Hilt**: Inyección de dependencias estricta.
- **WorkManager**: Background processing para sincronización transparente.

## Algoritmo Offline-First y Last-Write-Wins (LWW)
Todo registro generado offline asume validez local y se empaqueta en la **Cola de Sincronización**.
El algoritmo **Last-Write-Wins** está soportado por un timestamp universal (`updatedAt`). Si dos encuestadores alteran al mismo ciudadano, el backend utiliza el valor de `updatedAt` para determinar de forma determinista y consistente cuál versión del dato sobreescribe a la otra.

## Patrón Outbox
Para garantizar que nunca se pierdan transacciones de red frente a fallas de energía o cierres de app:
1. El usuario guarda un formulario.
2. Una única transacción de negocio atómica almacena la Entidad Local y simultáneamente introduce un evento PENDING en la `cola_sincronizacion`.
3. El `SyncWorker` lee la cola y negocia con el backend en ciclos de backoff exponencial hasta asegurar la entrega (marcando como SENT).

## Tecnologías del Backend
- **PHP Puro**: API REST minimalista y directa.
- **MySQL**: Motor de bases de datos central. Resolutor de conflictos Last-Write-Wins a través de queries y transacciones ACID.

## Seguridad

### Autenticación por token
Los endpoints que **escriben** en la base de datos exigen un token. El flujo es:

1. `POST /api/auth/login.php` valida las credenciales con `password_verify` (bcrypt) y emite un token opaco de 32 bytes con 30 días de vigencia.
2. En la tabla `sesiones` solo se guarda el **hash SHA-256** del token: una filtración de la base de datos no permite suplantar a ningún encuestador.
3. `POST /api/personas/sync.php` exige la cabecera `Authorization: Bearer <token>` y atribuye las encuestas al encuestador del token, no al `id_encuestador` que envíe el cliente.

La vigencia es larga a propósito: el token se emite con conectividad y viaja con el dispositivo a terreno. Tanto la PWA (IndexedDB) como la app Android (SharedPreferences) lo conservan para que un inicio de sesión sin red pueda seguir sincronizando cuando vuelva la señal. **El primer inicio de sesión de cada dispositivo debe hacerse con conexión.**

### CORS
No se usa `Access-Control-Allow-Origin: *`. `api/cors.php` centraliza la política y solo refleja orígenes presentes en la variable de entorno `ALLOWED_ORIGINS`:

```
ALLOWED_ORIGINS=https://encuestas.manuelcardenas.online,http://localhost:8898
```

> CORS es una política del navegador y **no sustituye a la autenticación**: clientes como `curl` o la app Android la ignoran. La protección real de los endpoints es el token.

### Otras medidas
- Consultas con **PDO preparado** y `EMULATE_PREPARES => false`.
- Los mensajes de excepción van al log del servidor, nunca al cliente.
- El payload de sincronización se valida y normaliza campo por campo antes de abrir la transacción, con un tope de 500 registros por lote.
- El panel `/api/admin` usa token **CSRF** y contraseña por variable de entorno.
- En builds de release no se registran los cuerpos HTTP y la cabecera `Authorization` va redactada.

## Variables de Entorno
Copiar `.env.example` a `.env` y completar:

| Variable | Descripción |
|---|---|
| `DB_PASS` | Contraseña del usuario de la base de datos |
| `MYSQL_ROOT_PASS` | Contraseña root de MySQL |
| `ADMIN_PASSWORD` | Acceso al panel `/api/admin` |
| `ALLOWED_ORIGINS` | Orígenes autorizados para CORS, separados por comas y sin barra final |

## Pruebas
```bash
./gradlew testDebugUnitTest       # 29 pruebas unitarias JVM
node scripts/check-pwa-assets.mjs # integridad del caché offline de la PWA
```

Las pruebas cubren autenticación (`AuthRepositoryImplTest`), reglas de negocio y persistencia (`GuardarRegistroCompletoUseCaseTest`, `EliminarPersonaUseCaseTest`), validaciones (`GuardarPersonaUseCaseTest`, `RegistrarEncuestaUseCaseTest`) y manejo de errores (`SincronizarPendientesUseCaseTest`).

`check-pwa-assets.mjs` verifica que todo archivo listado en `pwa/sw.js` exista y que los recursos de `index.html` estén cacheados. Sin esa comprobación, dividir o renombrar un archivo rompe la app **sin conexión** — un fallo invisible al probar en línea.

## Integración Continua
`.github/workflows/ci.yml` se ejecuta en cada push y pull request a `main`:

- **android**: pruebas unitarias + `assembleDebug` sobre JDK 17, publicando el reporte de pruebas.
- **php**: valida la sintaxis de todos los archivos de `api/` y falla si reaparece un CORS permisivo o si `sync.php` deja de exigir autenticación.
- **pwa**: verifica la integridad del caché offline.

## Estructura del Proyecto
```
proyecto_offline/
├── app/                  # Aplicación Android Nativa
│   ├── src/main/java/... # Clean Architecture (data, domain, presentation, di, worker)
│   └── src/test/java/... # Pruebas unitarias JVM
├── api/                  # Backend PHP (API REST)
│   ├── cors.php          # Política CORS centralizada (lista blanca)
│   └── auth_token.php    # Emisión y validación de tokens
├── pwa/                  # Progressive Web App (offline-first)
├── database/             # Scripts SQL (esquema y migraciones)
├── scripts/              # Verificaciones usadas por CI
├── .github/workflows/    # Integración continua
└── README.md
```

## Instrucciones de Compilación
1. Abrir la carpeta raíz con **Android Studio** (Koala o superior recomendado).
2. Sincronizar Gradle usando el wrapper embebido (`./gradlew assembleDebug`).
3. **JDK 17**: AGP 8.2 lo requiere. El proyecto declara un *toolchain* de Java 17, así que Gradle lo descarga automáticamente aunque el equipo tenga otra versión instalada (con JDK 21 y sin toolchain, la compilación falla en `JdkImageTransform`).
4. Para el Backend: Levantar Apache/MySQL mediante XAMPP u otro stack LAMP apuntando a la carpeta `/api`.

### Base de datos ya desplegada
Las instalaciones nuevas obtienen todo desde `database/schema.sql`, que solo se ejecuta al crear una base vacía. Para una base existente hay que aplicar las migraciones pendientes una sola vez.

**Con acceso a la base de datos:**
```bash
docker exec -i encuestas_offline_db mysql -u root -p minsalud_encuestas < database/migrations/003_sesiones.sql
```

**Sin acceso SSH (despliegue por Dokploy):** abrir `https://TU_DOMINIO/api/setup/migrate.php` en el navegador e ingresar la contraseña de `ADMIN_PASSWORD` en el formulario. El endpoint es idempotente y aplica las migraciones 002 y 003 más la cuenta de prueba.

> El SQL va embebido en `migrate.php` porque `.dockerignore` excluye `database/` de la imagen — si no lo hiciera, los `.sql` quedarían descargables por HTTP desde el docroot.
>
> **Borrar `api/setup/migrate.php` y volver a desplegar** una vez aplicada la migración. Es un endpoint administrativo; no debe quedar expuesto de forma permanente.

## Estado Actual del Proyecto
- **Android**: Scaffolding, Data, Domain, UseCases, Repositorios, ViewModels, UI Compose, WorkManager Sync completados.
- **Backend/DB**: Completados Scripts DDL y Endpoints de resolución de conflictos.
- **Calidad**: 29 pruebas unitarias, integración continua en GitHub Actions y autenticación por token en los endpoints de escritura.

## Licencia
Distribuido bajo licencia MIT. Ver [LICENSE](LICENSE).
