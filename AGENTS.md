# AGENTS.md — Documento maestro de TRIVIAX
> **Propósito:** Este archivo es la memoria permanente del proyecto. Cada vez que se inicia una sesión de trabajo, se lee primero. Contiene el estado real del sistema, las reglas de diseño, el plan de acción y la hoja de ruta. **No eliminar. Actualizar con cada cambio relevante.**

---

## 1. ¿Qué es TRIVIAX?

TRIVIAX es una aplicación web educativa de juego de preguntas y respuestas con tablero, inspirada en la dinámica del Juego de la Oca. Está pensada para docentes que desean usarla en el aula con sus estudiantes (aproximadamente 12 años), aunque también permite partidas abiertas sin objetivos educativos formales (ej: torneos temáticos).

---

## 2. Versión actual: 5.x (en desarrollo activo — base estable)

### 2.1. Qué funciona y NO debe tocarse salvo causa directa con la migración

| Componente | Estado | Archivos clave |
|---|---|---|
| Juego con tablero de 50 casillas | ✅ Funcional | `index.html`, `js/main.js`, `js/board.js`, `js/dice.js` |
| Catálogo de actividades tras botón "Iniciar partida" | ✅ Funcional | `index.html`, `js/main.js`, `js/ui.js`, `css/styles.css` |
| Carga de preguntas desde `preguntas.txt` | ✅ Funcional | `js/questionBank.js`, `php/triviax_core.php` |
| Carga de desafíos desde `proyecto.json` | ✅ Funcional | `php/triviax_core.php`, `api.php` |
| Desafíos mixtos (multiple_choice, true_false, matching_pairs, sequence_order, drag_drop, fill_blank, media_choice, image_hotspot, code_challenge) | ✅ Funcional | `js/engines/challengeEngine.js`, `js/activityRenderers/` |
| Motor de puntuación y penalizaciones | ✅ Funcional | `js/engines/scoringEngine.js` |
| Temporizador por pregunta | ✅ Funcional | `js/engines/challengeEngine.js` |
| Backend PHP con `api.php` | ✅ Funcional | `api.php` |
| Panel docente (`admin.php`) protegido por `tkey` | ✅ Funcional | `admin.php`, `php/triviax_core.php` |
| Panel de estadísticas (`estadisticas.php`) protegido por `tkey` | ✅ Funcional | `estadisticas.php` |
| Estadísticas acumuladas en `stats.json` | ✅ Funcional | `api.php` → action=save_stat |
| Reportes de partida en JSON | ✅ Funcional | `proyectos/*/reportes/` |
| Envío de reporte por email | ✅ Funcional | `api.php` → action=send_report |
| Modalidad “Estudia y responde” | ✅ Backend + frontend estudiante + panel docente | `study.php`, `panel/study_answer.php`, `js/engines/studyAnswerEngine.js`, `js/panel/studyAnswerPanel.js`, `php/study_answer_*`, `NoSubir/triviax_db_study_answer.sql` |
| Modalidad "TRIVIAX Lotto" | ✅ Completa (Fase 6 host activa) | `lotto.php`, `lotto_host.php`, `panel/lotto.php`, `js/engines/lottoStudentEngine.js`, `js/engines/lottoHostEngine.js`, `js/panel/lottoPanel.js`, `php/lotto_*`, `NoSubir/triviax_db_lotto.sql` |
| Acceso docente por código `tkey` | ✅ Funcional (legado) | `php/triviax_core.php` |
| Proyectos en carpetas `/proyectos/` | ✅ Funcional (legado) | `proyectos/` |

### 2.2. Sistema de color del splash screen (REGLA AUTOMÁTICA)

El color del texto del *splash* ("TRIVIAX X.0" + subtítulo) se calcula automáticamente en cada cambio de versión.

**Rueda RYB — 12 posiciones:**
`0·rojo · 1·rojo-naranja · 2·naranja · 3·amarillo-naranja · 4·amarillo · 5·amarillo-verde · 6·verde · 7·verde-azulado · 8·azul · 9·azul-violeta · 10·violeta · 11·rojo-violeta`

**Fórmula:** `color = RYB_WHEEL[ (major % 12 + 7) % 12 ]`
(complementario exacto = +6, paso adicional = +1, total = **+7**)

**Implementación:** función `getSplashTextColor(version)` en `js/config.js`.
El script inline de `index.html` la llama al cargar la página y aplica el color vía `element.style.color`.
El CSS mantiene `color: transparent` como valor base; el fondo del texto es siempre transparente.
Duración actual del splash: **4000 ms** en total; el fade comienza a los 3300 ms y dura 700 ms. El fallback JS en `setupIntroSplash()` también cierra a los 4000 ms.

**Para cambiar el color por versión:** editar `APP_VERSION` en `js/config.js`; el color se recalcula solo.
**Para publicar una versión (REGLA OBLIGATORIA):** usar `php tools/bump_version.php X.Y.Z` — sincroniza `js/config.js`, `service-worker.js` (renueva cachés PWA) y los textos fallback de los pies de página de `index.html` de una sola vez. Con `--assets` además sube todos los querystrings `?v=` de `index.html`. **Nunca editar `APP_VERSION` a mano en un solo archivo.** Verificar consistencia con `php tools/bump_version.php --check`.

**Dos presentaciones de la versión (no confundir):**
- **Resumida** (`major.minor`, ej. "5.0"): splash, píldora de marca y todo elemento con `data-app-version`. Solo cambia visualmente en saltos de versión definitiva (6.0, 7.0…). Se deriva automáticamente de `APP_VERSION`.
- **Completa** (`X.Y.Z`, ej. "5.0.9"): pies de página "TRIVIAX Plus vX.Y.Z by Darwin Salina © 2026", elementos con `data-app-version-full`. Refleja siempre la versión real en curso. También se inyecta automáticamente desde `APP_VERSION`; el texto estático del HTML es solo fallback y lo mantiene al día `bump_version.php`.

| Versión | Base | Color splash | Hex |
|---|---|---|---|
| v3 | amarillo-naranja | violeta | `#7030A0` |
| v4 | amarillo | rojo-violeta | `#B13E97` |
| **v5** | **amarillo-verde** | **rojo** | **`#E31B23`** ← actual |
| v6 | verde | rojo-naranja | `#F15A24` |

### 2.3. Estética y diseño (INMUTABLE salvo mejoras explícitas)

- Paleta: gradiente violeta-púrpura (`#6366f1` → `#a855f7`) como color de marca.
- Fondo de pantalla de inicio: imagen `fondo.jpg` del proyecto activo.
- Homepage: portada breve con objetivos, botón "Iniciar partida", acceso docente y pantalla de manuales; no muestra lista visible de actividades.
- Manuales: pantalla intermedia con material para jugadores y docentes; preparada para sumar videos orientativos.
- Selección de actividades: pantalla catálogo posterior al inicio, con tarjetas, categorías por nivel, buscador, novedades y más jugadas.
- Tablero: superpuesto sobre el fondo, casillas con transparencia.
- Cards: estilo `glass-card` (fondo translúcido, blur, borde sutil).
- Tipografía: sans-serif limpia, legible para 12 años en pantalla de 15.6".
- Fichas: círculos de colores (rojo, azul, verde, amarillo).
- Botones: primario violeta, secundario neutro.
- Modales de preguntas: grandes, claros, opciones bien separadas.
- Idioma de interfaz: **español**.
- Sin dependencias externas por CDN. Sin llamadas a Internet.
- Compatible con WAMP/XAMPP local + phpMyAdmin.

---

## 3. Base funcional alcanzada: 4.0

### 3.1. Qué cambia en v4.0

| Área | v3.x | v4.0 |
|---|---|---|
| Almacenamiento | Archivos JSON/txt | MySQL vía phpMyAdmin |
| Autenticación docente | Código `tkey` | Login email+contraseña |
| Estudiantes | No existen | Se registran, inician sesión |
| Partidas | Anónimas, reporte JSON | Asociadas a docente, guardadas en BD |
| Acceso de estudiantes | Directos (sin cuenta) | Por código de sesión de 6 caracteres |
| Estadísticas | `stats.json` por proyecto | Tabla `stats_desafios` en BD |
| Reportes | Archivos JSON locales | Tabla `sesiones`+`intentos` en BD |
| Modo abierto | No existe | Sesiones tipo `abierta` (ej: torneos) |

### 3.2. Qué NO cambia en v4.0

- El juego en sí (tablero, dados, fichas, flujo de turno) no se modifica.
- Los tipos de desafío y sus renderizadores no se modifican.
- La estética visual no se modifica.
- Los proyectos en carpetas siguen funcionando (modo legado).
- El `tkey` se mantiene como mecanismo de respaldo temporal.
- `api.php` sigue respondiendo sus acciones actuales sin cambios.

---

## 4. Base de datos (ya creada en phpMyAdmin)

### 4.1. Tablas y propósito

| Tabla | Propósito |
|---|---|
| `usuarios` | Docentes y estudiantes. Campo `rol` los distingue. |
| `proyectos` | Metadatos de actividades con `docente_id`. |
| `desafios` | Preguntas individuales con `data_json` completo. |
| `sesiones` | Instancia de juego. Tiene `codigo_acceso` (6 chars), `tipo`, `estado`, `current_turn_player_id`, `current_turn_number`. |
| `sesion_jugadores` | Quién se unió a cada sesión. Tiene `player_token` (hash SHA-256), `posicion`, `puntaje`, `estado`, `last_seen_at`. |
| `sesion_turnos` | [v4.5] Estado de cada turno: `pending_roll → challenge_assigned → answered/skipped/expired → completed`. Source of truth para concurrencia. |
| `resultados` | Puntaje final por jugador en la sesión. |
| `intentos` | Cada respuesta individual durante la partida. Tiene `turno_id`, `answer_payload`, `points_delta`, `time_ms`, `idempotency_key`. |
| `stats_desafios` | Acumulado histórico de aciertos/fallos por desafío. |
| `rate_limits` | [v4.6] Control de intentos de login fallidos por IP. |
| `audit_log` | [v4.6] Registro de eventos de seguridad (login exitoso/fallido/rate-limited). |

### 4.2. Tipos de sesión

- **`educativa`**: el docente crea la sesión y comparte el código con sus alumnos. Tiene objetivos pedagógicos claros.
- **`abierta`**: cualquier usuario registrado puede unirse con el código público. Para torneos o juegos recreativos.

### 4.3. Flujo de acceso

```
Docente crea sesión → sistema genera código "ABC123"
      ↓ docente comparte el código
Estudiante ingresa código → se une a sesion_jugadores → juega → resultados guardados en BD

Partida abierta (ej: Mundial de fútbol):
→ tipo = 'abierta', código puede publicarse libremente
→ cualquier usuario registrado (o invitado) puede unirse
```

### 4.4. Archivo de conexión esperado

```
C:\wamp64\dbconn\triviax.env
```

Variables:
```
db_host=localhost
db_name=triviax
db_user=root
db_pass=
tkey=CODIGO_DOCENTE_LEGADO
# Anti-bot del registro (v5.1) — opcionales; si faltan, Turnstile se omite
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=
```

---

## 5. Arquitectura de archivos (actual + planificado)

```
triviax/
├── index.html                  ← Juego principal (NO TOCAR estructura; pequeños ajustes de UI OK)
├── admin.php                   ← Panel docente (requiere sesión rol=docente)
├── estadisticas.php            ← Panel stats (requiere sesión rol=docente)
├── api.php                     ← API (NO TOCAR endpoints existentes)
├── AGENTS.md                   ← Este archivo
├── .htaccess                   ← [v4.4] Bloquea .sql/.bak/.log, Options -Indexes, cabeceras seguridad
├── study.php                   ← [v5.0.0] Ficha estudiante estudia y responde
├── lotto.php                   ← [v5.0.7] Ficha estudiante lotto
├── lotto_host.php              ← [v5.0.8] Pantalla host (pizarra) lotto
│
├── NoSubir/                    ← SQL + migraciones — bloqueado por .htaccess
│   ├── triviax_db.sql
│   ├── triviax_db_v4_1.sql … v4_4.sql
│   ├── triviax_db_v4_5.sql     ← [v4.5] sesion_turnos + columnas player_token, posicion, puntaje, etc.
│   ├── triviax_db_v4_6.sql     ← [v4.6] rate_limits, audit_log, intentos.nombre_jugador, índices
│   └── .htaccess               ← Deny all
│
├── tools/                      ← Scripts administrativos — protegidos por sesión PHP
│   ├── sync_projects.php       ← [v4.4] Sincroniza filesystem → BD
│   └── .htaccess               ← Options -Indexes
│
├── scratch/                    ← Scripts de diagnóstico — bloqueado por .htaccess
│   ├── test_fase_45.php        ← [v4.5] Script de pruebas de concurrencia (35 assertions, auto-cleanup)
│   └── .htaccess               ← Deny all
│
├── php/
│   ├── triviax_core.php        ← Utilidades compartidas (NO TOCAR lógica existente)
│   ├── db.php                  ← Conexión PDO a MySQL
│   ├── auth.php                ← Login/logout/sesión + helpers CSRF + lector .env
│   ├── challenge_validator.php ← [v4.4] Validador PHP de actividades JSON
│   ├── lotto_validator.php     ← [v5.0.7] Validador PHP lotto
│   ├── lotto_engine.php        ← [v5.0.7] Motor principal lotto
│   ├── lotto_api.php           ← [v5.0.7] Endpoints API lotto
│   └── lotto_ai.php            ← [v5.0.7] Adaptador IA lotto
│
├── auth/
│   ├── login.php               ← [v4.0] Página de login docente/estudiante
│   ├── registro.php            ← [v4.0] Registro de estudiantes
│   ├── registro_docente.php    ← [v5.1] Registro abierto de docentes (anti-bot: rate limit + honeypot + Turnstile)
│   ├── verificar.php           ← [v4.1] Verificación de cuenta por token de email
│   ├── mail_preview.php        ← [v4.1] Simulador visual de email (solo entorno local)
│   └── logout.php              ← [v4.0] Cierre de sesión
│
├── panel/
│   ├── dashboard.php           ← [v4.0] Dashboard docente con BD (enlace a lotto en v5.0.8)
│   ├── sesion_nueva.php        ← [v4.0] Crear sesión de juego
│   ├── sesion_detalle.php      ← [v4.0] Ver resultados de sesión
│   ├── live_sessions.php       ← [v5.0.10] Lista docente de partidas activas en vivo
│   ├── live_session.php        ← [v5.0.10] Monitor en vivo por pregunta, refresco cada 5 s
│   ├── study_answer.php        ← [v5.0.0] Panel mazo estudia y responde
│   ├── lotto.php               ← [v5.0.7] Panel de actividades lotto
│   └── super.php               ← [v4.0] Panel superadmin
│
├── js/                         ← NO TOCAR salvo cambios de BD directamente relacionados
│   ├── main.js
│   ├── ui.js
│   ├── gameState.js
│   ├── board.js
│   ├── dice.js
│   ├── questionBank.js
│   ├── sound.js
│   ├── utils.js
│   ├── engines/
│   │   ├── gameEngine.js
│   │   ├── boardEngine.js
│   │   ├── challengeEngine.js
│   │   ├── scoringEngine.js
│   │   ├── feedbackEngine.js
│   │   ├── studyAnswerEngine.js  ← [v5.0.0] Motor de cartas estudia y responde
│   │   ├── lottoStudentEngine.js ← [v5.0.7] Motor estudiante lotto
│   │   └── lottoHostEngine.js    ← [v5.0.8] Motor host lotto
│   ├── services/
│   │   ├── apiClient.js        ← [v4.5] Implementado: unirseASesion, guardarIntento, guardarResultados + startTurn, rollDice, submitAnswer, endTurn, sessionState
│   │   ├── mediaManager.js
│   │   ├── storageService.js
│   │   └── diagnosticsService.js
│   ├── validators/
│   │   └── challengeValidators.js
│   └── activityRenderers/
│       └── activityRendererRegistry.js
│
├── docs/
│   ├── GUIA_DOCENTE_TRIVIAX.md / .pdf       ← Guía docente canónica sin versión en el nombre
│   ├── GUIA_JUGADORES_TRIVIAX.md / .pdf     ← Guía de jugadores canónica sin versión en el nombre
│   ├── AUDITORIA_IMPLEMENTACION_2026-06-09.md  ← Informe técnico de la auditoría v4.5/v4.6
│   └── INSTRUCTIVO_DOCENTE_ESTUDIA_Y_RESPONDE.md  ← Guía paso a paso para docentes
│
├── css/
│   └── styles.css              ← NO TOCAR salvo ajustes de nuevas pantallas
│
└── proyectos/                  ← Modo legado, se mantiene
    ├── informatica_7mo/
    ├── informatica_8vo/
    ├── informatica101/
    └── ...
```

---

## 6. Plan de acción v4.0 — Fases

### ✅ Fase 4.4 — Seguridad, consistencia y jugabilidad (COMPLETADA — 2026-06-08)

> Implementación del plan de revisión y mejoras fundamentales.

#### Etapa 1 — Seguridad crítica ✅
- [x] **Eliminada visualización de contraseñas** en `panel/super.php`: columna "Contraseña" quitada de la tabla, `password_encrypted` removido del SELECT, `set_password` ya no escribe en esa columna.
- [x] **`triviax_encrypt_password` / `triviax_decrypt_password`** marcadas `@deprecated v4.4`. Columna `password_encrypted` marcada obsoleta en migración SQL. Se conserva en BD para no perder datos, pero nunca se lee ni escribe desde la aplicación.
- [x] **Separación de claves en `triviax.env`**: añadidas variables `APP_SECRET`, `CSRF_SECRET`, `TEACHER_INVITE_CODE`, `MAIL_FROM`, `MAIL_REPLY_TO`, `ADMIN_EMAIL`. `tkey` ya no se usa como clave de cifrado.
- [x] **`php/auth.php` actualizado**: nuevo parser `_triviax_env_all()` / `_triviax_env()` para leer todas las variables del `.env`. Funciones `triviax_admin_email()` y `triviax_from_email()` para que email sea configurable sin tocar código.
- [x] **Nuevos helpers CSRF**: `triviax_csrf_input()` (genera `<input hidden>`), `triviax_verify_csrf_or_fail()` (alias semántico), `triviax_verify_csrf_json()` (para endpoints JSON, acepta header `X-CSRF-Token`).
- [x] **Archivos sensibles protegidos con `.htaccess`**:
  - `NoSubir/.htaccess` — bloquea acceso a SQL/migraciones
  - `scratch/.htaccess` — bloquea acceso a scripts de diagnóstico
  - `.htaccess` raíz — bloquea extensiones `.sql`, `.bak`, `.zip`, `.log`, `.env`, etc.; `Options -Indexes`; cabeceras de seguridad HTTP.
  - `tools/.htaccess` — bloquea listado de directorio (la protección real es por sesión PHP)
- [x] **Migración `NoSubir/triviax_db_v4_4.sql`**: marca `password_encrypted` como obsoleto y garantiza que las columnas de v4.3 existan.

#### Etapa 2 — Sincronización filesystem → BD ✅
- [x] **`tools/sync_projects.php`** creado: recorre `proyectos/` y `trash/`, detecta formato (json/txt), lee metadata, hace INSERT o UPDATE en tabla `proyectos`. Accesible solo para superadmin (sesión PHP) o desde CLI. Genera resumen con altas, actualizaciones y errores.

#### Etapa 4 — Validador de actividades fortalecido ✅
- [x] **`js/validators/challengeValidators.js`** ampliado:
  - Validaciones para `media_choice`, `image_hotspot`, `code_challenge` (antes sin implementar)
  - `KNOWN_TYPES` con todos los tipos reconocidos
  - Detección de **IDs duplicados** (`findDuplicateIds`)
  - **Validación de estructura raíz** (`validateProjectStructure`): metadata.title, board.type, array challenges
  - `validateProjectData()` rediseñado: estructura raíz + dupes + cada desafío
  - `isProjectValid()` helper booleano
  - `CHALLENGE_INSTRUCTIONS` y `getChallengeInstruction(type)` para mostrar consigna al estudiante
  - Mensajes de error en español, con número de elementos y contexto accionable
- [x] **`php/challenge_validator.php`** creado: espejo PHP completo del validador JS. Funciones: `triviax_validate_project()`, `triviax_is_project_valid()`, `triviax_validate_challenge()`, `triviax_validate_project_structure()`. Integrado en el endpoint `save_activity` de `admin.php` (responde HTTP 422 con lista de errores si el JSON no es válido).

#### Etapa 6 — Jugabilidad: Drag & Drop y layout ✅
- [x] **`drag_drop` renderer** reescrito en `activityRendererRegistry.js`:
  - Arrastre nativo HTML5 (`draggable="true"`, `dragstart`, `dragend`, `dragover`, `dragleave`, `drop`)
  - Click-to-place como alternativa (accesible en toque/mobile)
  - Función central `placeItem(itemId, zoneId)` compartida por ambos métodos
  - Se puede mover un elemento de una zona a otra antes de confirmar
  - Botón × para devolver un elemento a la bandeja
  - Highlight visual en zona cuando un elemento está seleccionado (hover) o siendo arrastrado
- [x] **Layout 2 columnas** (`options-grid--2col`) aplicado:
  - `multiple_choice`: automático cuando hay exactamente 4 opciones
  - `true_false`: siempre 2 columnas (Verdadero | Falso)
- [x] **CSS** en `styles.css`: `.drag-selected` y `.drag-drop-zone` para estados visuales del nuevo renderer

#### Etapa 7 parcial — Instrucciones contextuales ✅
- [x] `#challenge-type-instruction` añadido al modal de pregunta en `index.html`
- [x] `ui.js` importa `getChallengeInstruction` y lo muestra antes de las opciones
- [x] Instrucciones definidas para todos los tipos en `CHALLENGE_INSTRUCTIONS`

---

### ✅ Fase 0 — Preparación (COMPLETADA)
- [x] BD diseñada y creada en phpMyAdmin
- [x] Tablas: usuarios, proyectos, desafios, sesiones, sesion_jugadores, resultados, intentos, stats_desafios
- [x] `triviax_db.sql` generado y documentado
- [x] AGENTS.md reescrito como documento maestro

### ✅ Fase 1 — Conexión BD + Autenticación (COMPLETADA)
> **Regla:** No romper nada existente. Todo nuevo va en archivos nuevos.

- [x] `php/db.php` — Conexión PDO a MySQL leyendo `dbkey_triviax.php` (path: `../../../dbconn/`)
- [x] `php/auth.php` — Funciones: login, logout, sesión activa, registro, CSRF, verificación de email
- [x] `auth/login.php` — Página de login con estética TRIVIAX
- [x] `auth/registro.php` — Registro de estudiantes (con flujo de verificación por email)
- [x] `auth/registro_docente.php` — Registro de docentes con código de habilitación
- [x] `auth/verificar.php` — Verificación de cuenta por token de email
- [x] `auth/logout.php` — Cierre de sesión limpio
- [x] Protección de nuevos paneles por sesión PHP (no tkey)
- [x] `tkey` repurposeado: SOLO como código de habilitación para crear cuentas docente

### ✅ Fase 1.5 — Verificación de email (COMPLETADA)
- [x] Migración `triviax_db_v4_1.sql`: columnas `email_verificado`, `token_verificacion`, `token_expira`
- [x] `triviax_registrar_usuario()`: genera token, envía email al usuario, notifica admin
- [x] `triviax_verificar_token_email()`: valida token, activa cuenta, auto-login
- [x] Email de verificación con enlace HTML a `auth/verificar.php?token=...`
- [x] Notificación al admin `saltmine.development@gmail.com` en cada nuevo registro
- [x] **Modo local**: en vez de `mail()`, redirige a `auth/mail_preview.php` (simulador visual de email con enlace clicable)
- [x] `auth/mail_preview.php`: inaccesible en producción; muestra el email de verificación + resumen de notificación admin
- [x] **Modo remoto**: `mail()` real; si falla, fallback a `mail_pending.log` fuera del webroot
- [x] Login bloquea cuentas no verificadas con mensaje claro

### ✅ Fase 2 — Panel docente con BD (COMPLETADA)
- [x] `panel/dashboard.php` — Lista sesiones, proyectos y stats del docente
- [x] `panel/sesion_nueva.php` — Crear sesión con código auto-generado (6 chars)
- [x] `panel/sesion_detalle.php` — Ver jugadores, ranking, intentos y cambiar estado
- [x] `api.php` — Nuevas acciones: `unirse_sesion`, `guardar_intento` (implementado en Fase 3)

### ✅ Fase 3 — Juego conectado a BD (COMPLETADA)
- [x] Campo de código de sesión (opcional) en pantalla de configuración de jugadores
- [x] `api.php` → `unirse_sesion`: valida código, registra jugadores en `sesion_jugadores`, activa sesión
- [x] `api.php` → `guardar_intento`: guarda cada respuesta en `intentos` + actualiza `stats_desafios`
- [x] `api.php` → `guardar_resultados`: guarda puntajes finales en `resultados`, cierra sesión
- [x] `ApiClient` extendido con `unirseASesion`, `guardarIntento`, `guardarResultados`
- [x] `main.js`: ciclo de turno completo integrado (`start_turn` → `roll_dice` → `submit_answer` → `end_turn`); fallback silencioso a `guardar_intento` si cualquier paso falla; BroadcastChannel para multi-pestaña; polling `session_state` cada 5 s
- [x] Si el código es inválido o la BD está caída, el juego sigue en modo local sin interrupciones

### ✅ Fase 3.5 — Registro docente abierto con anti-abuso (resuelto 2026-06-12)

**Decisión final:** el registro docente se liberó (modelo Kahoot/Quizizz): cualquiera crea su cuenta y la activa verificando su email. El código de habilitación compartido (`tkey`/`TEACHER_INVITE_CODE`) ya no se pide en `registro_docente.php`. Contra el abuso automatizado, ambos formularios de registro usan `triviax_registro_antibot_check()` (php/auth.php): rate limit por IP (8/hora, tabla `rate_limits`), honeypot, tiempo mínimo de llenado firmado con HMAC y Cloudflare Turnstile (gratuito; se activa definiendo `TURNSTILE_SITE_KEY` y `TURNSTILE_SECRET_KEY` en `triviax.env`, en local se omite). Además, los registros nunca verificados con token vencido se purgan automáticamente, liberando el email para reintentar.

**Problema histórico (ya resuelto):** el sistema usaba un código compartido (`tkey`) para habilitar el registro de docentes. Funcionaba para entornos pequeños y controlados, pero no escalaba: cualquiera que conociera el código podía registrarse como docente.

**Opciones evaluadas:**

| Opción | Complejidad | Requiere | Cuándo usar |
|---|---|---|---|
| A) Validar dominio de email institucional | Baja | Que la institución tenga dominio propio | Entorno de una sola institución |
| B) Aprobación manual por admin | Media | Botones en panel + estado `pendiente` | Escala moderada, control total |
| C) Invitaciones de un solo uso | Media-Alta | Generador de tokens + UI en panel | Escalas mayores, multi-institución |

**Decisión anterior (superada por la decisión final de arriba):** mantener `tkey` hasta que el sistema escale. Cuando fuera necesario, implementar **Opción B** (aprobación manual), ya que:
- El campo `activo` en `usuarios` ya existe
- La notificación por email al admin ya está implementada
- Solo falta: estado `pendiente` en el flujo de registro + botones aprobar/rechazar en `panel/dashboard.php`

### ⬜ Fase 4 — Modo estudiante
- [ ] Estudiante ve su historial de partidas
- [ ] Estudiante puede unirse a sesiones abiertas
- [ ] Ranking visible al final de la sesión

### 🔄 Fase 4.5 — Concurrencia, sesiones multijugador e integridad de partidas (EN PROGRESO)

> **Prioridad:** Alta. Implementar antes de Fase 5 (importador) y Fase 6+ (funcionalidades avanzadas).
> **Prerequisito:** Fases 0–3 completas. Migración `triviax_db_v4_5.sql` aplicada.

**Objetivo:** Garantizar que muchos jugadores participen en la misma partida o en partidas distintas simultáneamente sin interferencias, colisiones, duplicación de acciones ni mezcla de estados.

#### Gap analysis — schema (todas las columnas aplicadas vía migraciones v4.5 y v4.6)

| Tabla | Estado | Columnas clave añadidas |
|---|---|---|
| `sesiones` | ✅ v4.5 aplicada | `current_turn_player_id`, `current_turn_number`, `started_at`, `updated_at` |
| `sesion_jugadores` | ✅ v4.5 aplicada | `player_token` (CHAR 64, hash SHA-256), `posicion`, `puntaje`, `estado`, `last_seen_at` |
| `sesion_turnos` | ✅ v4.5 creada | Tabla completa con máquina de estados `pending_roll → challenge_assigned → answered/skipped/expired → completed` |
| `intentos` | ✅ v4.5 + v4.6 | `turno_id`, `answer_payload`, `points_delta`, `time_ms`, `idempotency_key`, `nombre_jugador` |
| `rate_limits` | ✅ v4.6 creada | Control de intentos fallidos por IP |
| `audit_log` | ✅ v4.6 creada | Eventos de seguridad (login exitoso/fallido/rate-limited) |

#### Tareas de implementación

**1. Migración SQL `NoSubir/triviax_db_v4_5.sql`** ✅ Ejecutada
- [x] `ALTER TABLE sesiones`: `current_turn_player_id INT NULL`, `current_turn_number SMALLINT DEFAULT 0`, `started_at DATETIME NULL`, `updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP`
- [x] `ALTER TABLE sesion_jugadores`: `player_token CHAR(64) UNIQUE NOT NULL DEFAULT ''`, `posicion TINYINT DEFAULT 0`, `puntaje SMALLINT DEFAULT 0`, `estado ENUM('esperando','activo','inactivo','desconectado') DEFAULT 'esperando'`, `last_seen_at DATETIME NULL`
- [x] `CREATE TABLE sesion_turnos` con: `id`, `sesion_id`, `jugador_id`, `turn_number`, `dice_value`, `board_position_before`, `board_position_after`, `challenge_id`, `estado ENUM('pending_roll','challenge_assigned','answered','skipped','completed','expired')`, `created_at`, `answered_at`
- [x] `ALTER TABLE intentos`: `turno_id INT NULL FK sesion_turnos`, `answer_payload JSON NULL`, `points_delta SMALLINT DEFAULT 0`, `time_ms INT UNSIGNED NULL`, `idempotency_key CHAR(64) NULL UNIQUE`

**2. Estados explícitos**

```
sesiones.estado:
  waiting → active → paused → finished → cancelled

sesion_turnos.estado:
  pending_roll → challenge_assigned → answered | skipped | expired → completed
```

Reglas de transición — nunca saltar estados ni volver atrás excepto `paused → active`.

**3. Endpoints a crear/ampliar en `api.php`**

| Endpoint | Método | Validaciones obligatorias |
|---|---|---|
| `session_state` | GET | sesion_id + player_token válidos; sesión activa |
| `join_session` | POST | codigo_sesion existe; estado=waiting; cupo; jugador no duplicado; proyecto válido |
| `start_turn` | POST | sesion_id + jugador_id + player_token; es el turno del jugador; sesión activa |
| `roll_dice` | POST | turno en `pending_roll`; bloqueo transaccional |
| `submit_answer` | POST | turno en `challenge_assigned`; jugador correcto; idempotency_key no usada; desafío corresponde al turno |
| `end_turn` | POST | turno en `answered\|skipped`; avanza turno en sesión |
| `finish_session` | POST | solo docente o superadmin; guarda snapshot de resultados |

**4. Reglas de integridad — TODAS las consultas de juego deben incluir `sesion_id`**

```php
// ❌ PROHIBIDO
UPDATE sesion_jugadores SET puntaje = ? WHERE usuario_id = ?

// ✅ CORRECTO
UPDATE sesion_jugadores SET puntaje = ? WHERE sesion_id = ? AND id = ? AND player_token = ?
```

**5. Transacciones en acciones críticas (PHP PDO)**

```php
$pdo->beginTransaction();
try {
    // SELECT ... FOR UPDATE en el turno
    // UPDATE estado
    // INSERT resultado si corresponde
    $pdo->commit();
} catch (\Exception $e) {
    $pdo->rollBack();
    throw $e;
}
```

**6. player_token** ✅ IMPLEMENTADO
- Generado al hacer `unirse_sesion`: `bin2hex(random_bytes(32))` → guardado como `hash('sha256', token)` en BD
- Token raw devuelto al cliente en la respuesta; guardado en `localStorage` como `triviax_session_{sesionId}_player_{jugadorId}_token`
- Todo endpoint v4.5 lo requiere; sin él = FORBIDDEN
- Permite distinguir jugadores con mismo nombre visible

**7. Idempotency key** ✅ IMPLEMENTADO
- Generada en frontend: `Date.now() + '_' + Math.random().toString(36)`
- Enviada en cada `submit_answer` y en `guardar_intento`
- Backend: verifica `SELECT id FROM intentos WHERE idempotency_key = ?` antes de insertar
- Si existe: devuelve resultado previo sin duplicar (`cached: true`)
- Si no existe: procesa normalmente

**8. localStorage — claves con namespace** ✅ IMPLEMENTADO
```js
// ✅ Implementado en main.js
localStorage.setItem(`triviax_session_${sesionId}_player_${jugadorId}_token`, playerToken)
localStorage.setItem(`triviax_tab_${sesionId}`, Date.now().toString())  // heartbeat multi-pestaña
```

**9. Polling de estado** ✅ IMPLEMENTADO
- `_startSessionPoll(sesionId)`: intervalo cada 5 s, llama `session_state`
- Se inicia en `startGameFlow()` tras unirse; se detiene en `endGameFlow()` o cuando `estado === 'finished'`
- `_stopSessionPoll()` cancela el intervalo activo; llamado también al iniciar uno nuevo (previene acumulación)

**10. Detección de múltiples pestañas** ✅ IMPLEMENTADO
- `_openSessionChannel(sesionId)`: detecta pestaña activa por `localStorage.getItem('triviax_tab_${sesionId}')` (stale > 12 s)
- Heartbeat cada 5 s actualiza timestamp; al cerrar: `localStorage.removeItem` + mensaje `tab_released`
- Pestaña secundaria recibe `tab_claimed` → `_sessionTabBlocked = true` → solo lectura; `handleDiceRoll` retorna sin acción
- Al cerrar la pestaña activa, la secundaria recibe `tab_released` y toma el control automáticamente

**11. Permisos por rol**

| Acción | Docente | Estudiante | Superadmin |
|---|---|---|---|
| Crear sesión | ✅ | ❌ | ✅ |
| Iniciar/pausar/finalizar | ✅ | ❌ | ✅ |
| Unirse a sesión | ❌ | ✅ | ✅ |
| Jugar (cuando es su turno) | ❌ | ✅ | ✅ |
| Ver estado de sesión | ✅ | ✅ (su sesión) | ✅ |
| Ver reportes | ✅ (sus sesiones) | ❌ | ✅ |
| Auditar y administrar | ❌ | ❌ | ✅ |

**12. Pruebas de concurrencia** — `scratch/test_fase_45.php` (35 assertions, 35/35 PASSED 2026-06-09)
- [ ] Dos estudiantes se unen a la misma sesión al mismo tiempo
- [ ] Dos estudiantes con el mismo nombre visible
- [ ] Un estudiante recarga durante su turno (debe recuperar estado)
- [x] Un estudiante envía la misma respuesta dos veces (idempotencia) — T6 ✅
- [ ] Dos pestañas del mismo jugador envían respuesta al mismo tiempo
- [ ] Docente finaliza sesión mientras un estudiante responde
- [ ] Dos sesiones distintas usan el mismo proyecto simultáneamente
- [ ] Dos docentes crean sesiones al mismo tiempo
- [ ] Diez o más jugadores consultan estado a la vez
- [x] Partida terminada no acepta más respuestas — T2 (SESSION_CLOSED) ✅
- [x] Ciclo de turno completo: start_turn → roll_dice → submit_answer → end_turn — T4 ✅
- [x] Turnos huérfanos `pending_roll` se limpian al iniciar turno nuevo — T3 ✅
- [x] `board_position_after` registrado correctamente tras animaciones — T5 ✅
- [x] Token inválido rechazado con FORBIDDEN — T0 ✅

**Criterio de aceptación:**
- No hay respuestas duplicadas ni puntajes duplicados
- No hay mezcla de jugadores entre sesiones distintas
- No hay mezcla de partidas entre pestañas del mismo navegador
- El turno no se "pisa" bajo carga
- El estado final de sesión es consistente con lo ocurrido
- Los reportes reflejan exactamente lo que pasó

---

### ✅ Fase 5 / Épica #7 — Importador legado filesystem → BD (COMPLETADA — 2026-06-18)
- [x] Migración `db/migraciones/6.2_desafios.sql` para persistir desafíos del tablero en BD.
- [x] `php/project_import.php`: mapeo/importación idempotente de `preguntas.txt` y `proyecto.json`.
- [x] `tools/import_projects.php`: carga masiva por CLI.
- [x] `admin.php`: disparadores para importar/sincronizar al guardar actividades.
- [x] `api.php?action=get` y `submit_answer`: leen desde BD cuando la actividad está importada; fallback a carpeta legado si no.
- [x] #1 Etapa 2: `php/board_eval.php` evalúa server-side todos los tipos, el cliente envía `raw`, `action=grade` devuelve veredicto autoritativo y `action=get` sanea respuestas correctas con `triviax_board_sanitize_challenge_for_client()`.
- [x] Verificación: `tests/run.php` 3 suites / 81 OK / 0 FAIL; HTTP local `demo_mixto` sin claves sensibles en `action=get`.

**Consecuencia:** el tablero online ya no confía en el veredicto del cliente. En PWA verdaderamente offline no hay corrección competitiva fiable porque las respuestas correctas no viajan al navegador.

### 🔄 Fase 6+ — Funcionalidades avanzadas
- [ ] Generación de actividades con IA (desde backend)
- [ ] Respuestas abiertas evaluadas con IA
- [ ] Exportación de reportes a PDF/Excel
- [ ] Soporte multiinstitución
- [x] Épica #10 parcial: SSE en monitor docente de partida (`events.php?stream=live_session_summary`) con `EventSource`, heartbeat, streams cortos y fallback automático al polling de 5 s.
- [ ] Épica #10 restante: extender SSE a Lotto host/estudiante y otros monitores con cuidado de workers Apache/PHP.
- [x] Chequeo operativo de producción: `tools/check_production_readiness.php` verifica secretos, Turnstile y BD sin imprimir claves.

---

## 7. Reglas de implementación (SIEMPRE)

1. **No modificar** `index.html`, `js/`, `css/styles.css`, `api.php` (acciones existentes), `admin.php`, `estadisticas.php` salvo que el cambio sea **directamente necesario** para la integración con BD.
2. **No cambiar** la estética visual establecida. Nuevas pantallas usan la misma paleta, glass-card y tipografía.
3. **Código nuevo en archivos nuevos.** Preferir extensión sobre modificación.
4. **Cada feature debe ser reversible.** Si la BD no está disponible, el juego legado sigue funcionando.
5. **Sin frameworks pesados.** PHP vanilla + JS vanilla + MySQL.
6. **Sin CDN.** Todo offline.
7. **Español** en todos los textos de interfaz.
8. **UTF-8** en todos los archivos.
9. **Contraseñas** siempre con `password_hash()` / `password_verify()`.
10. **CSRF** en todos los formularios POST.
11. **Nunca** confiar en datos del cliente sin validar en servidor.
12. **Actualizar este archivo** cuando se complete una fase o cambie una decisión de arquitectura.

---

## 8. Decisiones tomadas (registro)

| Fecha | Decisión |
|---|---|
| 2026-06 | Motor de BD: MySQL/MariaDB vía phpMyAdmin |
| 2026-06 | Sin frameworks: PHP vanilla + JS vanilla |
| 2026-06 | Dos roles: `docente` y `estudiante` |
| 2026-06 | Código de acceso a sesión: 6 caracteres alfanuméricos en mayúsculas |
| 2026-06 | Sesiones de tipo `educativa` y `abierta` |
| 2026-06 | El `tkey` se mantiene como mecanismo legado temporal |
| 2026-06 | Los proyectos en carpeta se mantienen como modo legado |
| 2026-06 | Credenciales de BD en `../../dbconn/dbkey_triviax.php` (path real desde `php/`: `../../../dbconn/`) |
| 2026-06 | `tkey` de `triviax.env` se usa SOLO como código de habilitación para registrar docentes; no da acceso a paneles |
| 2026-06 | `admin.php` y `estadisticas.php` ahora requieren sesión PHP con rol `docente` |
| 2026-06 | Jugadores invitados permitidos (sin cuenta) en sesiones abiertas |
| 2026-06 | Verificación de email obligatoria antes de primer login (token 64-char, expira 24h) |
| 2026-06 | Notificación automática a `saltmine.development@gmail.com` en cada nuevo registro |
| 2026-06 | Fallback de email: si `mail()` falla guarda en `dbconn/mail_pending.log` (fuera del webroot) |
| 2026-06-08 | `password_encrypted` marcado obsoleto v4.4 — nunca se lee ni escribe, pendiente de DROP en v5.0 |
| 2026-06-08 | `triviax.env` expandido: `APP_SECRET`, `CSRF_SECRET`, `TEACHER_INVITE_CODE`, `MAIL_FROM`, `ADMIN_EMAIL` |
| 2026-06-08 | Validador PHP `php/challenge_validator.php` creado como espejo de `challengeValidators.js` |
| 2026-06-08 | Sync script en `tools/sync_projects.php` — solo superadmin o CLI |
| 2026-06-08 | Drag & Drop nativo implementado en renderer `drag_drop`; `placeItem()` compartido con click-to-place |
| 2026-06-08 | `CHALLENGE_INSTRUCTIONS` centralizado en `challengeValidators.js` para uso en UI y validación |
| 2026-06-08 | Fase 4.5 especificada: concurrencia, player_token, sesion_turnos, idempotency_key — implementación pendiente |
| 2026-06-08 | Migración `triviax_db_v4_5.sql` creada: estructura completa para control de concurrencia |
| 2026-06-11 | Duración del splash reducida a 2500 ms; CSS y fallback JS sincronizados |
| 2026-06-11 | Creado instructivo docente de “Estudia y Responde”: creación/publicación de mazos y acceso estudiante |
| 2026-06-11 | Duración del splash ajustada a 4000 ms para dar más tiempo de lectura |
| 2026-06-12 | Registro docente liberado: sin código de habilitación; anti-bot en ambos registros (`triviax_registro_antibot_check()`: rate limit IP 8/h, honeypot, tiempo mínimo HMAC, Turnstile opcional vía `TURNSTILE_SITE_KEY`/`TURNSTILE_SECRET_KEY`); purga automática de registros no verificados con token vencido |
| 2026-06-09 | Auditoría contra `Prompt_TRIVIAX_auditoria_implementacion.md`: `php/auth.php` ya no escribe `password_encrypted`; sesión PHP endurecida con cookie `HttpOnly`, `SameSite=Lax`, strict mode e inactividad por rol |
| 2026-06-09 | `api.php` apaga `display_errors` salvo `APP_DEBUG=true`, respeta `MAINTENANCE_MODE`, genera `player_token` por jugador al unirse y guarda solo hash SHA-256 |
| 2026-06-09 | Endpoints v4.5 añadidos: `session_state`, `start_turn`, `roll_dice`, `submit_answer`, `end_turn`; `guardar_intento` ahora acepta `player_token`, `idempotency_key`, `answer_payload`, `points_delta`, `time_ms` |
| 2026-06-09 | `js/main.js` guarda tokens por namespace `triviax_session_{sesionId}_player_{jugadorId}_token` y envía `idempotency_key` en cada intento; `ApiClient` expone métodos v4.5 |
| 2026-06-09 | Migración `NoSubir/triviax_db_v4_6.sql` creada: `rate_limits`, `audit_log`, ajuste de índice `uq_sesion_player_token` e índice `idx_sj_last_seen`; pendiente de ejecutar en phpMyAdmin |
| 2026-06-09 | Informe técnico creado en `docs/AUDITORIA_IMPLEMENTACION_2026-06-09.md`; quedan pendientes CSRF total en API JSON, BroadcastChannel/múltiples pestañas y uso completo de endpoints de turno por el motor visual |
| 2026-06-09 | Dump `NoSubir/darwinuy_triviax.sql` revisado: confirma v4.5 aplicada, pero no incluye `rate_limits`/`audit_log` y le falta `intentos.nombre_jugador`; `triviax_db_v4_6.sql` fue actualizado para agregar esa columna condicionalmente |
| 2026-06-09 | Migración v4.6 ejecutada en BD por el usuario (rate_limits, audit_log, intentos.nombre_jugador, índices). Confirmado. |
| 2026-06-09 | Eliminado bloque muerto `if (false &&...)` en `unirse_sesion` de api.php (código residual sin efecto; $idempotencyKey no existía en ese scope). La idempotencia en `guardar_intento` ya estaba activa y correcta. |
| 2026-06-09 | BroadcastChannel implementado en main.js: `_openSessionChannel` / `_closeSessionChannel`. Detecta pestañas duplicadas con heartbeat de 5 s y stale-timeout de 12 s. La pestaña secundaria entra en modo solo lectura; al cerrar la activa, la secundaria puede tomar el control. Guard en `handleDiceRoll` bloquea acciones si `_sessionTabBlocked`. |
| 2026-06-09 | Node.js v26.3.0 instalado en el sistema (winget). Disponible para linting JS con `node --check`. |
| 2026-06-09 | Fase 4.5 C implementada: ciclo de turno integrado en main.js. `handleDiceRoll` llama `start_turn` → `roll_dice` (ambos awaited con fallback silencioso). `resolveTurn` llama `submit_answer` + `end_turn` encadenados al final (después de todas las animaciones y casillas especiales), con `points_delta` y `board_position_after` correctos. Fallback a `guardar_intento` si algún paso del turno falla. `api.php`: `start_turn` limpia turnos huérfanos pending_roll; `submit_answer` acepta `board_position_after` del payload. Polling `session_state` cada 5 s activo mientras sesión BD esté abierta. |
| 2026-06-10 | Homepage de estudiantes simplificada: ya no muestra lista visible de actividades. El botón "Iniciar partida" abre un catálogo de actividades con tarjetas, categorías por nivel, buscador, novedades y más jugadas localmente. `api.php?action=list` devuelve `date` y `updated_at` para ordenar novedades. |
| 2026-06-10 | Acceso a ayuda rediseñado: el ícono de manual en la homepage abre `screen-manuals`, con PDF para jugadores, PDF docente y tarjetas de videos futuros. |
| 2026-06-10 | Panel de estadísticas: el desglose de rendimiento por pregunta se ordena por relevancia pedagógica, mostrando primero preguntas respondidas con menor porcentaje de acierto; las no respondidas quedan al final. |
| 2026-06-10 | Panel de estadísticas: agregado botón "Actualizar datos" y hora de última carga para que el docente pueda refrescar el reporte mientras una partida está en curso. |
| 2026-06-10 | Salto de versión a TRIVIAX Plus v5.0. `APP_VERSION` actualizado a `5.0.0`; el splash recalcula automáticamente su color a rojo `#E31B23` según la rueda RYB. |
| 2026-06-10 | Configuración de partida v5.0: tiempo base predeterminado cambiado a 30 segundos; el selector de tablero elige aleatoriamente un perfil inicial entre los tableros disponibles al cargar la app. |
| 2026-06-10 | Modalidad `study_answer` (“Estudia y responde”) cerró Fase 3 con suite `scratch/test_study_answer.php` en 46/46 PASS. |
| 2026-06-10 | Fase 4 de `study_answer` implementada: `study.php`, `js/engines/studyAnswerEngine.js`, métodos `study*` en `ApiClient`, CSS aditivo y enlace desde dashboard docente. Verificación HTTP end-to-end: carta pública sin `answer`, corrección y puntaje server-side. |
| 2026-06-10 | Fase 5 de `study_answer` implementada: `panel/study_answer.php` + `js/panel/studyAnswerPanel.js`. Permite listar mazos, crear/importar JSON, validar, guardar, publicar/archivar, previsualizar cartas y consultar reporte básico. Dashboard docente enlaza al panel. |
| 2026-06-10 | Splash e identidad visual de marca actualizados con fuente local `fonts/LuckiestGuy-Regular.ttf`: toda aparición visible de `TRIVIAX` usa wordmark con el estilo de la homepage, `TRIVIA` en azul/celeste, borde grueso blanco y `X` sólida `#fcd360` mediante `js/brand.js`. El splash entra letra por letra con rebote leve; `5.0` usa el mismo tratamiento blanco/azul del logo y `EL CAMINO DEL CONOCIMIENTO` queda en blanco con tamaño equivalente al `5.0`. Mantiene la imagen de homepage como fondo. Cache/querystrings subidos a `5.0.6`. |
| 2026-06-11 | Fase 6 de TRIVIAX Lotto implementada: creados lotto_host.php y js/engines/lottoHostEngine.js, y enlazados en el dashboard docente. Sintaxis y suite test_lotto 72/72 PASS en local. |
| 2026-06-11 | Creados manuales de usuario actualizados para la versión 5.0 (TRIVIAX Plus) en formato Markdown. Cubren las nuevas modalidades de Lotto y Estudia y responde, el diseño de marca de v5.0, concurrencia y seguridad. |
| 2026-06-11 | Gestión de versión unificada: creado `tools/bump_version.php` (bump y `--check` de consistencia entre `js/config.js`, `service-worker.js` y footers de `index.html`). `config.js` sincronizado de 5.0.8 a **5.0.9** (estaba desfasado del SW). Los pies de página ahora muestran la versión completa real ("TRIVIAX Plus v5.0.9") vía `data-app-version-full`, mientras splash y píldora de marca conservan la resumida ("5.0") vía `data-app-version`. |
| 2026-06-11 | Corrección de filtros de categorías (limpieza de espacios y comparación en minúsculas) y del input de búsqueda (se añadió el evento Enter y el botón lupa 🔍 con estilo glassmorphism). Además, se restringió el acceso a "Ver partidas en vivo" únicamente para docentes agregando el enlace en el dashboard y procesando el parámetro `?live=1` en el juego para abrir directamente la configuración de jugadores. |

---

| 2026-06-11 | Ver partidas en vivo rediseñado como monitor docente: `panel/live_sessions.php` lista solo sesiones activas del docente autenticado y `panel/live_session.php` muestra preguntas ya respondidas, ordenadas por aciertos, en hasta 5 columnas con refresco cada 5 s y colores verde/rojo según aciertos vs errores. El enlace del dashboard apunta al monitor docente. El acceso estudiante por código se reforzó: `sesion_nueva.php` genera códigos alfanuméricos de 6 caracteres y `js/main.js` detecta enlaces con `?CODIGO`, `?codigo=CODIGO` o token ofuscado con el código en posición 13. |
| 2026-06-11 | Configuracion de jugadores ampliada de 4 a 8 participantes para sesiones por equipos: `index.html` agrega botones/filas 5-8, `js/config.js` define cuatro colores nuevos y `css/styles.css` permite que el selector envuelva botones sin romper layout. Querystrings de assets sincronizados a 5.0.12 con `tools/bump_version.php 5.0.12 --assets`. |
| 2026-06-11 | Graphify integrado para Codex/TRIVIAX: instalado `graphifyy==0.8.38` con `uv`, skill global actualizada, skill/hook de proyecto en `.codex/`, `multi_agent = true` activado en `C:\Users\Usuario\.codex\config.toml`, y grafo local regenerado con `graphify update .` (1881 nodos, 2626 relaciones, 182 comunidades). `graphify-out/` permanece ignorado por git y se regenera localmente. |
| 2026-06-12 | Manuales consolidados: quedan como fuentes editables `docs/GUIA_DOCENTE_TRIVIAX.md` y `docs/GUIA_JUGADORES_TRIVIAX.md`, y como archivos públicos enlazados `docs/GUIA_DOCENTE_TRIVIAX.pdf` y `docs/GUIA_JUGADORES_TRIVIAX.pdf`. Se retiraron duplicados activos del root y guías versionadas de `docs/`; los enlaces de `index.html`, `admin.php`, visor docente y email de bienvenida apuntan a nombres estables sin versión. Exportador local: `python tools/build_guides_pdf.py`. |
| 2026-06-18 | Épica #7 / #1 Etapa 2 cerrada: importador filesystem→BD del tablero (`php/project_import.php`, `tools/import_projects.php`, migración `6.2_desafios.sql`), lectura desde BD con fallback, grader server-side para todos los tipos, endpoint `grade` y saneo de `action=get` para no exponer respuestas. Verificado con `tests/run.php` (81 OK) y HTTP local `demo_mixto` sin claves sensibles. |
| 2026-06-18 | Épica #10 iniciada: monitor docente `panel/live_session.php` usa SSE mediante `events.php` y helper compartido `php/live_session_summary.php`; mantiene fallback a polling. Agregado `scratch/test_live_session_summary.php` (10 OK) y `tools/check_production_readiness.php` para validar producción/Turnstile/BD. |

## 9. Glosario

| Término | Significado en TRIVIAX |
|---|---|
| **Proyecto** | Conjunto de desafíos + metadatos + fondo creado por un docente |
| **Sesión** | Instancia de juego activa, con código de acceso y estado |
| **Desafío** | Una pregunta/actividad individual (de cualquier tipo) |
| **Intento** | La respuesta de un jugador a un desafío en una sesión |
| **Resultado** | Resumen de puntaje final de un jugador en una sesión |
| **tkey** | Código de acceso docente del sistema legado v3.x |
| **Modo legado** | Funcionamiento con archivos (`preguntas.txt`, `stats.json`) sin BD |

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

When the user types `/graphify`, invoke the `skill` tool with `skill: "graphify"` before doing anything else.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- Dirty graphify-out/ files are expected after hooks or incremental updates; dirty graph files are not a reason to skip graphify. Only skip graphify if the task is about stale or incorrect graph output, or the user explicitly says not to use it.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).

### Uso operativo en TRIVIAX

- En Windows, si `graphify` no esta en PATH, usar el ejecutable instalado:
  `C:\Users\Usuario\.local\bin\graphify.exe`.
- Antes de modificar flujos compartidos de TRIVIAX (sesiones, `api.php`, panel docente, motores JS, validadores, modalidades `study_answer` o `lotto`), consultar primero el grafo con una pregunta concreta. Ejemplos:
  - `graphify query "TRIVIAX sesiones en vivo intentos dashboard docente api.php"`
  - `graphify query "flujo unirse_sesion player_token start_turn roll_dice submit_answer"`
  - `graphify path "api.php" "js/main.js"`
  - `graphify explain "panel/live_session.php"`
- Usar `graphify-out/GRAPH_REPORT.md` solo para orientacion amplia; no copiarlo completo al contexto si una consulta focalizada alcanza.
- Luego de cambios relevantes de arquitectura o archivos nuevos, ejecutar `graphify update .` y anotar en esta bitacora si el grafo cambia de forma significativa.
- Si Graphify devuelve una respuesta pobre o demasiado general, reformular con nombres reales de archivos, endpoints, tablas o funciones antes de caer en busquedas amplias con `rg`.
