# Brief de rediseño · ColOffline

Para usar con Claude Design: ejecuta `/design` y pídele que use este archivo.

**Fecha:** 8 de octubre de 2026 · **Fase del plan:** F3 ([plan de trabajo](PLAN-DE-TRABAJO.md))

> **Ejecutado el 9 de octubre de 2026.** De las direcciones del lienzo se eligió la **B · «Cálida de territorio»**: azul institucional `#12467E`, fondo cálido `#F6F4EF`, acento terracota `#B4532A` solo para registrar y para el día de hoy, y la fuente Figtree empaquetada. Está aplicada en la PWA, el panel y Android; la paleta vive en [`design/tokens.json`](../design/tokens.json). Ver [ARQUITECTURA §5.8](ARQUITECTURA.md#58-sistema-de-diseño-una-paleta-tres-superficies).

---

## 1 · El producto

ColOffline es un sistema para registrar encuestas demográficas en Colombia en zonas rurales **sin conectividad**. Tiene dos superficies que deben verse como **un solo producto**:

| Superficie | Quién la usa | Dónde | Tecnología |
|---|---|---|---|
| **App de campo** (PWA y Android, misma experiencia) | Encuestadores | Veredas y caseríos: al sol, de pie, con una mano, en celulares de gama baja, días sin señal | HTML/CSS sin framework · Jetpack Compose Material 3 |
| **Panel de administración** | Funcionarios de la secretaría | Oficina, escritorio, pantalla ancha | PHP + CSS |

El corazón del producto es la **confianza offline**: el encuestador debe saber en todo momento qué está guardado solo en su teléfono, qué ya llegó al servidor y qué necesita su atención. Ningún diseño puede hacerle dudar de si perdió una encuesta.

## 2 · Objetivo

Un rediseño **moderno, agradable y profesional**, que dé orgullo mostrar a la secretaría de salud y que el encuestador disfrute usar todo el día. Más pulido, cálido y con más personalidad que el estado actual, sin perder la sobriedad institucional.

## 3 · Punto de partida (estado actual)

- **Marca:** azul institucional `#12467E` (oscuro `#0C325C`, tinte `#EEF3F9`). Logo: círculo azul con una cruz blanca (`pwa/icons/icon.svg`).
- **Estados:** éxito `#1B7A4B` · advertencia `#A15C00` · error `#B3261E`. Fondo `#F2F5F9`, texto `#16202C` / `#5B6878`.
- **Estilo actual:** plano y sobrio, fuente del sistema, bordes finos, sombras mínimas. Funciona, pero se ve genérico.
- **Exploración anterior descartada:** un login verde con fondo animado y tarjeta de vidrio (antes en `design_handoff_login/`, ya borrado). Se descartó porque creaba **dos identidades** (verde en el login, azul en el resto). No repetir ese error: una sola identidad en todas las pantallas.

El azul institucional se conserva como ancla. Todo lo demás se puede evolucionar: tipografía, acento secundario, iconografía, ilustración, ritmo y profundidad.

## 4 · Lo que pido en el lienzo

### Fila 1 · Dos direcciones visuales

Muestra **dos direcciones** aplicadas a dos pantallas clave (Inicio de la app y Resumen del panel), para elegir una:

- **A · Institucional moderna:** azul profundo, superficies limpias, tipografía con carácter, acentos precisos, datos protagonistas.
- **B · Cálida de territorio:** el mismo azul con un acento cálido inspirado en el campo colombiano (ámbar, terracota o verde montaña), iconografía lineal amable y algún detalle ilustrado discreto.

Desarrolla el resto del lienzo en la dirección que recomiendes, y explica por qué en una nota.

### Fila 2 · App de campo (móvil 390 × 844)

1. **Acceso:** con conexión; sin conexión con credenciales guardadas; sin conexión y sin credenciales, con la explicación de que hace falta entrar una vez con internet.
2. **Inicio / Personas:**
   - resumen del día (registradas hoy, en total, sin enviar) y mini gráfico de 7 días;
   - indicador de conexión y de última sincronización;
   - buscador y lista de personas con su estado (Sincronizada · Pendiente · Rechazada);
   - botón de registrar bien visible.
3. **Registrar / editar persona:** formulario largo por secciones (Documento · Datos personales · Contacto · Ubicación: departamento → municipio → vereda · Socioeconómico: EPS, ocupación, estrato 1–6). Incluye:
   - validación en línea con errores claros por campo;
   - al guardar, confirmar «Guardado en el teléfono. Se enviará cuando haya señal».
4. **Ficha de persona** (nueva): todos sus datos e historial de encuestas (quién, cuándo, qué acción).
5. **Sincronización**, con todos sus estados:
   - sin conexión;
   - enviando por lotes («Enviando 120 de 340»);
   - todo al día;
   - **sesión vencida**: «Conéctate e inicia sesión para enviar 37 registros. No se borra nada»;
   - **rechazados**, con su motivo y una acción para corregirlos.
6. **Modo oscuro** de Inicio y Formulario (Android ya tiene tema oscuro).

### Fila 3 · Panel de administración (escritorio 1440 × 900)

1. **Acceso:** con cuenta, y el modo de arranque (primera configuración).
2. **Resumen:**
   - cifras: personas activas, encuestas, encuestadores activos, dispositivos;
   - encuestas por día (14 días, los días vacíos visibles);
   - personas por municipio y encuestas por encuestador;
   - alertas: rechazos recientes y celulares sin sincronizar desde hace más de 3 días.
3. **Personas:**
   - tabla con búsqueda;
   - filtros (departamento/municipio, encuestador, rango de fechas, estado);
   - pestañas Activas / Papelera;
   - exportar lo filtrado, y borrar con «Deshacer».
4. **Ficha de persona:** todos los campos, historial de encuestas como línea de tiempo, auditoría de cambios hechos desde el panel, y acciones.
5. **Cuentas:**
   - tabla de cuentas (rol, estado, encuestas, última actividad) y formulario lateral;
   - detalle de cuenta: sesiones abiertas en celulares (con botón para cerrarlas) y desbloqueo por intentos fallidos.
6. **Monitor de sincronización** (nuevo): celulares (encuestador, plataforma, versión, última sincronización), registros rechazados con su motivo, y errores por día.
7. **Auditoría** (nueva): acciones de los administradores.
8. Una vista del **Resumen a 390 px**: el panel también se consulta desde el celular.

### Fila 4 · Sistema de diseño

- paleta con roles (no con nombres de color) y su versión oscura;
- escala tipográfica, espaciado, radios y sombras;
- componentes: botones, campos con error, insignias de estado, tarjetas, tablas, avisos, pestañas y gráfico de barras de una serie.

Los tokens deben poder traducirse a CSS (PWA y panel) y a Compose (Android) sin reinterpretaciones.

## 5 · Datos de ejemplo

Usa contenido realista, nunca «Lorem ipsum» ni «Usuario 1»:

- **Personas:** María Fernanda Rojas Díaz (CC 1061702334), Luis Alberto Gómez Mosquera, Yesenia Palacios Rentería, José Daniel Quiñones Castillo.
- **Lugares:** Popayán · Cauca, Tumaco · Nariño, Quibdó · Chocó, Leticia · Amazonas. Veredas: La Esperanza, El Carmen, Alto Bonito.
- **EPS:** Nueva EPS, Asmet Salud, Emssanar, Coosalud.
- **Cifras del panel:** 1.284 personas activas, 3.902 encuestas, 18 encuestadores activos, 21 dispositivos, 12 en la papelera.
- **Motivos de rechazo reales:**
  - «El número de documento debe tener al menos 6 caracteres»;
  - «Municipio no reconocido».

## 6 · Restricciones

- **Idioma:** español de Colombia, tuteo («Conéctate», «Tu sesión»), como ya usa la app.
- **Legibilidad al sol:** contraste AA como mínimo, alto en la información clave; nada importante en gris claro. En la app, texto de 15–16 px como base.
- **Una mano:** objetivos táctiles de 48 px o más; acciones principales al alcance del pulgar.
- **El estado nunca solo por color:** siempre icono + palabra (Pendiente, Rechazada…).
- **Funciona sin internet:** cualquier fuente va empaquetada con la app, nada cargado desde terceros. Google Fonts está prohibido en el panel por privacidad.
- **Gama baja:** sin imágenes pesadas, iconos SVG lineales, animaciones sutiles que respeten «reducir movimiento».
- **Implementable sin frameworks** en la PWA: nada que solo un componente de React resuelva.

## 7 · Qué evitar

- Degradados llamativos, vidrio esmerilado, fondos animados.
- Dos identidades distintas entre pantallas o plataformas.
- Tableros recargados o gráficos de muchos colores para una sola serie.
- Texto gris sobre gris; iconos sin etiqueta en acciones importantes.
- Cualquier diseño que esconda el estado de sincronización.
