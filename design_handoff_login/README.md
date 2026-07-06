# Handoff: Login ColOffline (PWA) — Versión verde 4b

## Overview
Pantalla de inicio de sesión para la PWA **ColOffline** (`pwa/` en el repo `Manuel151025/proyecto_offline`). Autenticación por **número de documento + contraseña**, con **login offline usando credenciales en caché**: la primera autenticación exitosa debe ser online; luego el usuario puede ingresar sin conexión validando contra un hash guardado localmente (IndexedDB, igual que el resto de datos de la app).

Diseño elegido: **opción "4b" — fondo verde completo animado + tarjeta vidrio** (ver `Login ColOffline.dc.html` incluido; la sección superior "Variantes de banner y fondo" contiene 4b, que es la definitiva; el resto son exploraciones descartadas).

## About the Design Files
El archivo HTML incluido es una **referencia de diseño** (prototipo), NO código de producción. La tarea es **recrear esta pantalla dentro del entorno existente de la PWA**: vanilla JS con módulos ES, router por hash (`pwa/js/router.js`), pantallas como funciones `render(container)` en `pwa/js/screens/`, y estilos en `pwa/css/styles.css`. No introducir frameworks.

Nota: este login usa una **paleta verde nueva** (ver Design Tokens) distinta del azul `--primary` actual. Agregar los nuevos tokens a `:root` en `styles.css` (p. ej. `--login-green`, `--login-green-dark`) sin alterar los existentes; el resto de la app sigue en azul salvo que el usuario decida lo contrario.

## Fidelity
**High-fidelity.** Recrear pixel-perfect, incluidas las animaciones.

## Integración sugerida en el codebase
- Nueva pantalla: `pwa/js/screens/login.js` exportando `render(container)`.
- Nueva ruta `/login` en `pwa/js/app.js` (`onRoute('/login', ...)`).
- Guard de sesión: si no hay sesión activa, navegar a `/login`. En `/login` ocultar `.bottom-nav` y `.app-header` (el diseño es a pantalla completa).
- Credenciales en caché: al primer login online, guardar `{ documento, passwordHash (SHA-256 + salt), updated_at }` en IndexedDB (`pwa/js/db.js`, nuevo store `credenciales`). Si `navigator.onLine === false`, validar contra ese hash y marcar la sesión `offline: true` para re-validar al reconectar (patrón outbox como `pwa/js/sync.js`).
- Logout: limpiar sesión (conservar credenciales en caché si "Recordar sesión" está activo) y navegar a `/login`.

## Screen: Login 4b (fondo completo animado + tarjeta vidrio)

### Fondo (toda la pantalla)
- `background: linear-gradient(200deg, #116B3F, #2BB673, #0A5C31); background-size: 300% 300%;`
- Animación `background-position` 0%→100%→0% 50%, **14s ease infinite** (keyframe "gradShift").
- Dos blobs decorativos (posición absoluta, detrás del contenido):
  - Superior-izq: círculo 220px, `rgba(255,255,255,0.10)`, `filter: blur(4px)`, animación drift 8s ease-in-out infinite (translate(22px,-16px) scale(1.1) en el 50%).
  - Inferior-der: círculo 240px, `rgba(255,255,255,0.08)`, `blur(6px)`, drift inverso 10s (translate(-18px,14px) scale(0.92)).

### Encabezado (sobre el fondo)
- Columna centrada, padding `52px 24px 26px`, gap 10px:
  - Logo `pwa/icons/icon.svg` invertido (círculo blanco, cruz **#0E7A41**), 66×66, con animación de flote: translateY 0 → -7px → 0, 4s ease-in-out infinite.
  - "ColOffline": 1.5rem, 700, blanco, letter-spacing 0.3px.
  - "Ministerio de Salud · Encuestas demográficas": 0.8rem, `rgba(255,255,255,0.85)`.

### Tarjeta vidrio
- `background: rgba(255,255,255,0.9); backdrop-filter: blur(14px)` (+ prefijo -webkit-), `border: 1px solid rgba(255,255,255,0.65); border-radius: 20px; box-shadow: 0 10px 32px rgba(6,60,32,0.3); margin: 8px 20px 24px; padding: 26px 22px;`
- Entrada animada: opacity 0 + translateY(26px) scale(0.97) → normal, 0.55s `cubic-bezier(0.22,1,0.36,1)`.

### Píldora de estado de conexión (dentro de la tarjeta, arriba)
- `inline-flex`, gap 7px, padding `5px 12px`, radius 99px, 0.72rem/600, margin-bottom 20px.
- Online: bg #E8F5E9, texto/punto #2E7D32, "En línea". Offline: bg #FFF3E0, texto/punto #E65100, "Sin conexión".
- Punto 8px con pulso (opacity 1→0.35→1, 2s infinite). Reacciona a eventos `online`/`offline` de `window`.
- Cuando offline, aviso debajo: bg #FFF3E0, texto #E65100, 0.78rem, padding `9px 12px`, radius 10px: "Puedes ingresar con tus credenciales guardadas en este dispositivo."

### Campos con label flotante
Contenedor `position: relative`, margin-bottom 20px (doc) / 16px (pass).
- Input: padding `13px 14px` (contraseña: padding-right 56px), borde `1.5px solid #d5e3da`, radius 12px, 0.95rem. Focus: `border-color: #19A45B; box-shadow: 0 0 0 3px rgba(25,164,91,0.15)`; transición 0.2s.
- Label: `position: absolute; left: 12px; background: #fff; padding: 0 6px; font-weight: 600; pointer-events: none; transition: all 0.18s ease;`
  - Reposo (vacío, sin foco): top 14px, 0.95rem, color #8a94a3.
  - Activo (foco o con valor): top -9px, 0.68rem; color #0E7A41 con foco, #8a94a3 sin foco.
- Campo 1 **Número de documento**: `inputmode="numeric"`, maxlength 12, filtrar no-dígitos.
- Campo 2 **Contraseña**: type password; botón "Ver"/"Ocultar" absolute derecha (color #0E7A41, 0.75rem/600) alterna visibilidad.

### Fila recordar / olvidé
- Flex space-between, margin `2px 0 18px`.
- **Recordar sesión**: switch — track 36×20px radius 99px (ON: #1565C0 → cambiar a **#19A45B** para coherencia con la paleta verde, OFF: #c3cad4), knob blanco 16px, `transition: left 0.2s` (2px ↔ 18px), sombra `0 1px 3px rgba(0,0,0,0.3)`. Label 0.82rem #5a6070. Default ON.
- **"¿Olvidaste tu contraseña?"**: 0.82rem, 600, #0E7A41 (placeholder o toast "Disponible próximamente").

### Botón Ingresar
- Full width, padding 14px, radius 12px, `background: linear-gradient(135deg, #2BB673, #128A4C)`, texto blanco 0.95rem/600, `box-shadow: 0 6px 18px rgba(18,138,76,0.35)`.
- Hover: `translateY(-2px)` + sombra `0 10px 24px rgba(18,138,76,0.45)`. Active: `scale(0.97)` + sombra reducida. Transición transform 0.15s / shadow 0.2s.
- Texto: online "Ingresar", offline "Ingresar sin conexión", cargando "Verificando…" + spinner 16px (borde 2.5px `rgba(255,255,255,0.35)`, tope blanco, rotación 0.7s linear infinite), gap 8px. Disabled durante verificación.

### Validación
- Documento `/^[0-9]{6,12}$/` → "Ingresa un número de documento válido (6–12 dígitos)".
- Contraseña ≥ 4 caracteres → "La contraseña debe tener al menos 4 caracteres".
- Credenciales inválidas → "Documento o contraseña incorrectos".
- Bloque de error: bg #FFEBEE, texto #C62828, 0.8rem, padding `9px 12px`, radius 10px, entrada fadeUp 0.25s. Limpiar error al teclear.

### Estado de éxito
- Reemplaza el contenido de la tarjeta con fadeUp (translateY 14px→0, 0.4s):
  - Círculo 76px bg #E8F5E9 con check SVG **#0E7A41** (stroke 3, dibujo stroke-dashoffset 48→0, 0.6s delay 0.2s) y entrada pop (scale 0→1.12→1, 0.5s, cubic-bezier(0.68,-0.55,0.27,1.55)).
  - "¡Bienvenido!" 1.15rem/700.
  - Subtexto 0.85rem #5a6070: online → "Sincronizando datos con el servidor…"; offline → "Sesión iniciada sin conexión. Se sincronizará al recuperar la red."
- Tras ~1s, `navigate('/personas')` y disparar `autoSync()` si online.

## State Management
Estado local: `{ doc, pass, showPass, remember, error, loading, done, focusDoc, focusPass }`. Sesión: `{ documento, offline, createdAt }`. Credenciales en caché: `{ documento, passwordHash, salt, updated_at }` en IndexedDB.

## Design Tokens
Nuevos (paleta verde del login):
- Verde principal #19A45B · oscuro #0E7A41 · brillante #2BB673 · profundo #0A5C31 / #116B3F · gradiente botón #2BB673→#128A4C
- Borde inputs #d5e3da · label reposo #8a94a3 · focus ring `rgba(25,164,91,0.15)`
Reutilizados de `pwa/css/styles.css`:
- #E8F5E9/#2E7D32 (success) · #FFF3E0/#E65100 (warning) · #FFEBEE/#C62828 (error) · texto #1a1a2e / #5a6070
- Radius: 12px inputs/botón, 20px tarjeta, 99px píldoras/switch · Fuente: stack del sistema ya definido

## Animaciones (resumen de keyframes)
- gradShift: background-position 0%→100%→0%, 14s ease infinite (fondo)
- drift1/drift2: translate+scale suaves, 8-10s ease-in-out infinite (blobs)
- float: translateY 0→-7px→0, 4s ease-in-out infinite (logo)
- cardIn: opacity+translateY(26px)+scale(0.97)→normal, 0.55s cubic-bezier(0.22,1,0.36,1) (tarjeta)
- pulse: opacity 1→0.35→1, 2s infinite (punto de conexión)
- spin: rotate 360°, 0.7s linear infinite (spinner)
- pop / draw / fadeUp: éxito (círculo, check, textos)
- Respetar `prefers-reduced-motion: reduce` desactivando gradShift, drift y float.

## Assets
- Logo: `pwa/icons/icon.svg` (ya en el repo). Aquí se usa invertido: círculo blanco + cruz #0E7A41.

## Files
- `Login ColOffline.dc.html` — prototipo interactivo. La opción **4b** (sección superior, tarjeta vidrio sobre fondo verde animado) es el diseño a implementar; abrir en navegador para ver estados (offline, error, loading, éxito).
