# Reporte técnico de capacidades — TRIVIAX

**Fecha:** 2026-06-17
**Versión de referencia:** 6.x (commit `8b7d831` → "v6.1: tableros personalizados vinculados a la actividad"; `APP_VERSION` declarada `6.0.0` en `js/config.js` y `service-worker.js`)
**Alcance:** estado real del producto al día de hoy, según el código en el repositorio y el grafo de conocimiento (`graphify-out/`, 114 archivos · 2188 nodos · 217 comunidades, construido sobre el commit actual).

---

## 1. Resumen ejecutivo

TRIVIAX es una aplicación web educativa para el aula (estudiantes de ~10–16 años) construida como **SPA en HTML5 + CSS3 vanilla + JavaScript moderno (módulos ES)** sobre un **backend PHP** con **MySQL**. Su núcleo es un juego de mesa de tablero (estilo "Juego de la Oca") en el que los jugadores avanzan con un dado y resuelven desafíos para acumular puntos, pero ha evolucionado hacia una **plataforma multi-modalidad** con cuatro motores de actividad independientes (tablero, "Estudia y responde", Puzle/jigsaw, Etiquetar imagen y TRIVIAX Lotto), gestión docente, autenticación, persistencia en base de datos para las modalidades nuevas y capacidades PWA offline.

El producto convive con **dos modelos de datos en paralelo**: el clásico basado en **filesystem** (`proyectos/*/preguntas.txt`) que alimenta el tablero, y el moderno basado en **MySQL** que sostiene las modalidades recientes con validación server-side. Esta dualidad es la característica que más condiciona su evolución.

---

## 2. Arquitectura general

### 2.1 Cliente (SPA, JavaScript ES Modules)
- **Orquestación:** `js/main.js` (controlador principal), `js/gameState.js` (estado), `js/config.js` (configuración global, perfiles de tablero, puntuación, colores, versión).
- **Motores de juego** (`js/engines/`):
  - `gameEngine.js` — orquestador de partida y selección de perfil de tablero.
  - `boardEngine.js` — render y animación del tablero (`BoardEngine`, `Board`, `getSpaceCoords`, `animateTokenPath`).
  - `challengeEngine.js` — presentación y resolución de desafíos.
  - `scoringEngine.js` — cálculo de puntuación.
  - `feedbackEngine.js` — feedback visual/sonoro (`FeedbackEngine`).
  - `studyAnswerEngine.js`, `jigsawEngine.js`, `etiquetarEngine.js` — modalidades nuevas.
  - `lottoHostEngine.js` + `lottoStudentEngine.js` — modalidad Lotto (host/estudiante con polling de estado).
- **Renderizadores y paneles** (`js/activityRenderers/`, `js/panel/`): registro de renderers (`activityRendererRegistry.js`) y paneles docentes por modalidad (`etiquetarPanel`, `jigsawPanel`, `lottoPanel`, `studyAnswerPanel`).
- **Servicios** (`js/services/`): `apiClient.js` (`ApiClient`, god node con 28 conexiones), `mediaManager.js`, `storageService.js`, `diagnosticsService.js`.
- **Utilidades transversales:** `utils.js` (`qs`/`qsa`, `escapeHTML`, `shuffle`, `delay`), `dice.js` (`Dice`), `questionBank.js` (`QuestionBank`), `sound.js`, `board.js`, `brand.js` (wordmark dinámico), `validators/` (validación espejo en cliente).

### 2.2 Backend (PHP)
- **API central:** `api.php` (~60 KB) con helpers god-node `triviax_api_error()`, `triviax_api_json()`, `triviax_api_success()`, `triviax_api_require_post()`, `triviax_api_validate_player()`, `triviax_api_token_hash()`.
- **Núcleo:** `php/triviax_core.php`, `php/db.php` (`triviax_db()` — acceso PDO), `php/auth.php`.
- **APIs/motores/validadores por modalidad** (patrón repetido por actividad): `php/study_answer_api.php` + `_engine` + `_validator`; `php/jigsaw_*`; `php/etiquetar_*`; `php/lotto_*`. Cada uno con `*_api_handle()`, throttle propio y verificación de docente/BD.
- **Validación de desafíos:** `php/challenge_validator.php` (`triviax_validate_project`, `triviax_normalize_type`, `triviax_find_duplicate_ids`).
- **Servicios de soporte:** `php/image_upload.php` (subida de imágenes), `php/project_sync.php` (sincroniza proyectos filesystem→registro: `triviax_sync_all_projects`, `triviax_extract_project_meta`, `triviax_mark_project_trashed`), `php/guia_docente_lib.php` (generación/envío de guías docente en MD/PDF y verificación de tokens de email).
- **Páginas docentes:** `admin.php` (~196 KB, gestión integral), `estadisticas.php`, paneles bajo `panel/` (incluye `live_sessions.php` y `live_session.php` para monitoreo en vivo).
- **Páginas de modalidad host/estudiante:** `study.php`, `jigsaw.php`, `etiquetar.php`, `lotto.php`, `lotto_host.php`.

### 2.3 Persistencia (MySQL)
Esquema relacional para autenticación, actividades y partidas:
- **Núcleo:** `users`, `activities`, `activity_settings`, `challenges`, `challenge_options`, `challenge_pairs`, `challenge_sequence_items` (+ tablas de partidas).
- **Lotto:** `lotto_activities`, `lotto_sections`, `lotto_students`, `lotto_assignments`, `lotto_timer_events`, `lotto_draws`, `lotto_evaluations`, `lotto_student_responses`.
- **Estudia y responde:** `study_decks`, `study_cards`, `study_sessions`, `study_session_cards`, `study_attempts`, `study_source_documents`.
- **Migraciones recientes:** `db/migraciones/6.0_actividades_imagenes.sql` y `6.1_tableros.sql`.

> **Nota de dualidad:** el tablero clásico sigue leyendo preguntas desde `proyectos/*/preguntas.txt` (filesystem); las modalidades nuevas viven en BD. Los tableros personalizados afloran vía `api.php?action=list_boards` leyendo `images/tableros/*.json` (filesystem), no la tabla `tableros`.

---

## 3. Capacidades funcionales actuales

### 3.1 Juego de tablero
- **Tres perfiles de tablero** (`js/config.js`):
  - **Oca tradicional** — 50 casillas, serpenteante, sin bucle; modos de victoria `race`, `exact`, `points`.
  - **Monopoly educativo** — 40 casillas rectangulares en bucle horario; victoria por puntos.
  - **Circular** — 36 casillas en bucle; victoria por puntos.
- **Tableros personalizados** vinculados a la actividad (v6.1), seleccionables desde *Configuración de partida* en `index.html`.
- **Parámetros de partida:** 2–4 jugadores, 8 colores de ficha, dado virtual, timeout de pregunta de 30 s, objetivos de puntaje configurables (50–250), bonus de vuelta (+20).
- **Puntuación:** +10 correcta, −5 incorrecta, −5 por tiempo agotado.

### 3.2 Tipos de desafío soportados
- Opción múltiple
- Verdadero / Falso
- Asociar pares
- Ordenar elementos / secuencias
- Clasificar por categorías
- Completar espacios (con desplegables)
- Elección con imágenes (multimedia)
- Desafío de código
- Zona activa / hotspot sobre imagen
- **Etiquetar imagen** (modalidad propia, v6.0)
- **Puzle / jigsaw** (modalidad propia con arrastre, recorte SVG y verificación, v6.0)

### 3.3 Modalidades independientes
- **Estudia y responde (`study_answer`):** mazos de estudio con fases lectura → evaluación → feedback, niveles cognitivos, dificultades y límites configurables; validación server-side y feedback idempotente.
- **TRIVIAX Lotto:** modalidad de sorteo/evaluación con pantalla docente, pantalla de estudiante, pantalla interactiva de salón, temporizadores, máquina de estados de sorteo (inicial/siguiente/posponer/ausente), modal de evaluación oral con rúbricas y respuestas por estudiante.
- **Puzle y Etiquetar:** actividades con imágenes (media en `media/` + metadatos en BD).

### 3.4 Sesiones en vivo y rol docente
- **Monitor docente en tiempo real:** `panel/live_sessions.php` y `panel/live_session.php`.
- **Unión por código de sesión** con enlace compartible; auto-unión gestionada en `main.js`.
- **Gestión de contenido:** creación de actividades, edición, papelera/restauración, sincronización de proyectos filesystem.
- **Generación con IA (andamiaje):** `triviax_decode_ai_project_json` / `triviax_build_project_from_ai_json` en `admin.php` permiten importar proyectos generados por IA (la IA como borrador, con revisión docente).
- **Estadísticas y reportes:** `estadisticas.php`.
- **Guías docente y estudiante:** en `docs/` (MD + PDF), regenerables con `python tools/build_manuals.py`.

### 3.5 Autenticación y cuentas
- **Registro docente abierto** (sin código de invitación desde 2026-06-12), con anti-bot en capas y Turnstile preparado (a la espera de claves en producción).
- **Flujo completo:** `auth/registro.php`, `registro_docente.php`, `verificar.php` (verificación de email), `login.php`, `logout.php`, `recuperar.php`, `reset_password.php`, `mail_preview.php`.
- **Acceso docente** a páginas sensibles protegido por llave `tkey` del `.env`.

### 3.6 PWA, multimedia y experiencia
- **Service Worker** (`service-worker.js`) con cachés versionados separados (static / dynamic / projects / media) → soporte offline-first del núcleo.
- **`manifest.json`** para instalación como app.
- **Identidad visual:** splash con hélice y color de texto calculado automáticamente por versión (rueda RYB en `config.js`); wordmark dinámico (`brand.js`).
- **Feedback de audio** y microinteracciones; render responsive (retrato/apaisado/pantalla completa en móviles).
- **Subida y gestión de imágenes** para actividades multimedia.

---

## 4. Seguridad (estado actual)

**Implementado:**
- Hashing de contraseñas con **bcrypt** (deprecación del cifrado reversible).
- **Protección CSRF** en formularios HTML y endpoints AJAX/JSON.
- **Rate limiting** y auditoría en endpoints; throttle por modalidad (`_study_api_throttle`, `_jigsaw_throttle`, `_etiquetar_throttle`).
- **Validación server-side** en las modalidades nuevas (study_answer, jigsaw, etiquetar, lotto): no confían en el cliente y protegen las respuestas esperadas.
- Control de concurrencia y sesiones multijugador; verificación de tokens de email.
- **Validación server-side del tablero clásico** (cerrada el 2026-06-18): `submit_answer` y `grade` recalculan veredicto/puntaje en servidor para todos los tipos; el cliente envía respuesta cruda y no decide el resultado competitivo.
- `api.php?action=get` entrega desafíos saneados con `triviax_board_sanitize_challenge_for_client()`, sin respuestas correctas expuestas al navegador.
- `guardar_resultados` exige token de jugador de la sesión y valida pertenencia a la sesión.

**Caveat operativo:**
- En PWA verdaderamente offline, sin PHP disponible, no hay corrección competitiva fiable porque las respuestas correctas ya no viajan al cliente. El modo online/pantalla única con WAMP/XAMPP sí queda cubierto.

---

## 5. Calidad, pruebas y tooling

- **Documentación abundante:** `README.md`, `API.md`, `SECURITY.md`, `TESTING.md`, `BACKUP_RESTORE.md`, `AGENTS.md` (≈50 KB, hoja de ruta por fases), manuales docente/estudiante.
- **Pruebas:** scripts en `scratch/` (`test_fase_45.php` integración/concurrencia; `test_auditoria_seguridad.php` seguridad/rate-limit/reportes), ejecutables por consola.
- **Versionado disciplinado:** `php tools/bump_version.php X.Y.Z` mantiene sincronizadas `config.js` y `service-worker.js`; `--check` verifica consistencia.
- **Grafo de conocimiento** (`graphify-out/`) conectado a la asistencia de desarrollo; reconstruible con `graphify update .`.
- **Sin ciclos de importación detectados**; extracción del grafo 95% determinista.

---

## 6. Proyección de futuros desarrollos potenciales

**Habilitador maestro ya cerrado:**
- **Importador filesystem → BD del tablero clásico** (Épica #7) implementado el 2026-06-18: permite leer desafíos desde BD, validar server-side todos los tipos y sanear `action=get`.

**Capacidades de mayor ventaja (según auditoría estratégica):**
1. **Repetición espaciada (algoritmo SM-2)** sobre "Estudia y responde" — gran impacto pedagógico y baja complejidad relativa.
2. **Generación de contenido con IA** consolidada (ya hay andamiaje en `admin.php`) con revisión docente obligatoria y control de costos.
3. **Gamificación profunda:** progresión persistente (XP, niveles, rachas), insignias/logros, avatares con economía de monedas, tablas de clasificación y temporadas.
4. **Tiempo real con SSE/Mercure** para reemplazar el polling de ~5 s de Lotto y sesiones en vivo.
5. **Accesibilidad WCAG 2.1 AA + lectura en voz alta** y perfiles de accesibilidad.

**Nuevas modalidades de alto valor (catálogo):** flashcards/tarjetas de memoria, respuesta corta escrita con corrección, palabra/frase desordenada, detectar el intruso, completar tabla, pista progresiva, sopa de letras, crucigrama, memoria de pares, video interactivo, mapa interactivo, encuesta/votación, nube de palabras, escape room ligero, camino de decisiones; más adelante: minijuegos arcade y editor visual libre tipo Genially.

**Formatos e interoperabilidad:** import/export de bancos de preguntas (GIFT, Aiken, CSV), plantillas curriculares, banco compartido entre docentes.

---

## 7. Áreas críticas a corregir o atender

> **Estado actualizado al 2026-06-18:** #1 Etapa 2 y #7 quedaron cerradas. La
> gran deuda técnica viva es #10 (tiempo real/SSE), además de pendientes
> operativos y cobertura de integración.

- ✅ **Confianza en el cliente (tablero clásico):** resuelta — el servidor recalcula veredicto y puntaje para todos los tipos (`php/board_eval.php`), `grade`/`submit_answer` son autoritativos y `action=get` entrega desafíos saneados.
- ✅ **Autorización ausente en `guardar_resultados`:** resuelto — exige token de jugador de la sesión (identidad) y `jugador_id` perteneciente a la sesión (propiedad) + rate limiting.
- ✅ **`display_errors = 1` fijo en paneles:** resuelto — `0` por defecto y gate `APP_DEBUG` en `admin.php` y `estadisticas.php`.
- ✅ **Aislamiento entre docentes:** resuelto — `triviax_docente_puede_gestionar_proyecto()` en editar/borrar; las modalidades con imágenes ya filtraban por dueño.
- ✅ **Endpoints `save_stat` y `unirse_sesion` sin límite de tasa:** resuelto — `triviax_api_throttle()` (60/5min y 180/min, fallo abierto sin BD).
- ✅ **Destinatario de correo de notificación hardcodeado:** resuelto — `triviax_admin_email()` (lee `ADMIN_EMAIL` del `.env`).
- ✅ **Deuda de dualidad de datos (importador filesystem→BD):** resuelta como Épica #7 — migración `6.2_desafios.sql`, importador, CLI, disparadores en admin y fallback filesystem.
- ✅ **Consistencia de versión:** resuelto — alineado a `6.1.0` en `config.js`/`service-worker.js`/footers.
- ✅/🟡 **Turnstile sin claves en producción:** implementación verificada (ya completa); se añadió `triviax.env.example`. Resta **operativo**: cargar las claves en el `.env` de producción.
- 🟡 **Latencia por polling (tiempo real):** **planificada** como Épica #10 (SSE recomendado) — ver [plan-epicas-7-10.md](plan-epicas-7-10.md).
- ✅/🟡 **Cobertura de pruebas automatizadas:** Etapa 1 — suite versionada `tests/` con runner y el unit-test de #1; resta portar regresiones de los fixes de seguridad a pruebas de integración con un MySQL de pruebas.

---

## 8. Resumen general (bullets)

- **Qué es:** plataforma educativa web (SPA JS + PHP/MySQL) centrada en un juego de tablero de desafíos, hoy multi-modalidad, en versión 6.x.
- **Arquitectura:** cliente modular con motores por modalidad + `ApiClient`; backend `api.php` con APIs/motores/validadores por actividad; PWA offline con SW versionado.
- **Tableros:** Oca (50), Monopoly (40) y Circular (36), más tableros personalizados por actividad (v6.1); victoria por carrera, exacta o puntos.
- **Desafíos:** ~11 tipos (opción múltiple, V/F, asociar, ordenar, clasificar, completar, elección con imagen, código, hotspot, etiquetar, puzle).
- **Modalidades propias:** Estudia y responde, Puzle/jigsaw, Etiquetar imagen y TRIVIAX Lotto (con evaluación oral y rúbricas).
- **Docente:** registro abierto + verificación, gestión de actividades, papelera, sesiones en vivo con monitor, estadísticas, guías MD/PDF, andamiaje de generación con IA.
- **Persistencia:** MySQL para modalidades nuevas y para desafíos importados del tablero clásico, con fallback filesystem legado.
- **Seguridad:** bcrypt, CSRF, rate limiting y validación server-side también en el tablero clásico online.
- **Habilitador clave del roadmap:** importador filesystem→BD del tablero cerrado; el próximo salto técnico grande es tiempo real/SSE.
- **Próximos saltos recomendados:** SM-2 (repetición espaciada), IA consolidada, gamificación persistente, tiempo real (SSE/Mercure), accesibilidad WCAG.
- **A atender ya:** tiempo real/SSE, claves Turnstile en producción y ampliar cobertura de integración reproducible.

---

*Reporte generado a partir del código del repositorio y del grafo de conocimiento `graphify-out/` (commit `8b7d831`). Las cifras de esquema y módulos reflejan el estado del árbol al 2026-06-17.*
