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

## Estructura del Proyecto
```
proyecto_offline/
├── app/                  # Aplicación Android Nativa
│   ├── src/main/java/... # Clean Architecture (data, domain, presentation, di, worker)
├── api/                  # Backend PHP (API REST)
├── database/             # Scripts SQL (Tablas y Relaciones MySQL)
└── README.md
```

## Instrucciones de Compilación
1. Abrir la carpeta raíz con **Android Studio** (Koala o superior recomendado).
2. Sincronizar Gradle usando el wrapper embebido (`./gradlew assembleDebug`).
3. Asegurar Java JDK 21 configurado (`JAVA_HOME`).
4. Para el Backend: Levantar Apache/MySQL mediante XAMPP u otro stack LAMP apuntando a la carpeta `/api`.

## Estado Actual del Proyecto
- **Android**: Scaffolding, Data, Domain, UseCases, Repositorios, ViewModels, UI Compose, WorkManager Sync completados.
- **Backend/DB**: Completados Scripts DDL y Endpoints de resolución de conflictos.
