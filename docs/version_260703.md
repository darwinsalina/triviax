# TRIVIAX — Reporte de estado actual

**Fecha del reporte:** 3 de julio de 2026 (actualizado el 5 de julio de 2026)
**Versión de código:** 7.0.2 (`js/config.js` y `service-worker.js`, sincronizadas por `tools/bump_version.php`)
**Último commit:** `d6e25ff` — 4 de julio de 2026 — "chore(release): 7.0.2 — despliegue del movimiento fluido de «Camino al Gol»"

> Nota de consistencia (5 de julio de 2026): `js/config.js`, `service-worker.js` y `AGENTS.md` ya declaran de forma coherente la **7.0.2**. La fuente de verdad sigue siendo `js/config.js`, ya que `tools/bump_version.php` es el único mecanismo autorizado para sincronizar el número de versión entre ese archivo, `service-worker.js` y los footers de `index.html`. Verificar consistencia con `php tools/bump_version.php --check`.

## 1. Qué es TRIVIAX

TRIVIAX es una aplicación web educativa para el aula, pensada para estudiantes de nivel secundario (~12 años en adelante). Nació como un juego de mesa tipo "Juego de la Oca" de 50 casillas y evolucionó hacia una plataforma multi-modalidad con siete actividades jugables, panel docente, estadísticas y una capa de datos en MySQL que va reemplazando gradualmente al modelo original basado en archivos de texto.

## 2. Arquitectura técnica

El backend está escrito en PHP (7+, con referencias a PHP 8.2 en la suite de pruebas) y combina mysqli y PDO según el módulo. El frontend es JavaScript vanilla con módulos ES (sin frameworks), HTML5 y CSS3. La aplicación funciona como PWA: `manifest.json` define ícono, colores y modo standalone, y `service-worker.js` gestiona cachés versionados que se invalidan automáticamente al subir de versión.

Estructura de carpetas principal:

- `auth/` — registro, login, verificación de correo y recuperación de contraseña.
- `db/migraciones/` — migraciones SQL versionadas (6.0 a 6.3), historial ordenado de la evolución del esquema.
- `panel/` — dashboard docente, generador de tableros y monitores en vivo por modalidad.
- `php/` — núcleo (`triviax_core`, `db.php`) y, por cada modalidad, un trío de archivos `*_api.php`, `*_engine.php` y `*_validator.php`.
- `tools/` — utilidades de mantenimiento: `bump_version.php` (versión), `import_projects.php` (migración filesystem→BD), `build_guides_pdf.php`, `check_production_readiness.php`, git-hooks.
- `tests/` — suite de pruebas versionada (`run.php`, pruebas de evaluación de tablero, calificación, validadores de desafíos, importación de proyectos).
- `css/`, `js/` (con `engines/`, `validators/`, `activityRenderers/`, `services/apiClient.js`), `proyectos/` (contenido legado en archivos de texto), `experimental/` (prototipos aislados sin impacto en producción).

Autenticación y seguridad: contraseñas con `password_hash`/`password_verify` (bcrypt), protección CSRF doble (token en formularios vía `triviax_csrf_input()`, y verificación por cabecera `X-CSRF-Token` en llamadas AJAX), limitador de intentos con la tabla `rate_limits` (5 intentos por 10 minutos), cabeceras de seguridad y CSP en `api.php`, y una tabla de auditoría (`audit_log`). El respaldo de datos está documentado en `BACKUP_RESTORE.md` (mysqldump / phpMyAdmin).

Dos paneles administrativos concentran la gestión docente: `admin.php` (panel completo de docente: creación y edición de proyectos, tableros, sesiones) y `estadisticas.php` (reportes y métricas de desempeño de los estudiantes).

## 3. Base de datos

El esquema activo mezcla nomenclatura en español (núcleo histórico) con tablas nuevas por modalidad:

Núcleo: `usuarios`, `proyectos`, `desafios`, `sesiones`, `sesion_jugadores`, `sesion_turnos`, `resultados`, `intentos`, `stats_desafios`, `rate_limits`, `audit_log`.

Por modalidad, añadidas en migraciones 6.0–6.3: `tableros` (tableros personalizados), `jigsaw_projects` / `jigsaw_sessions`, `etiquetar_projects` / `etiquetar_labels` / `etiquetar_sessions`, `wordsearch_projects` (sopa de letras), `crossword_projects` (crucigramas). El módulo Lotto tiene su propio grupo de tablas (`lotto_activities`, `lotto_sections`, `lotto_students`, `lotto_assignments`, `lotto_timer_events`, `lotto_draws`, `lotto_evaluations`, `lotto_student_responses`, `lotto_events`), al igual que "Estudia y responde" (`study_decks`, `study_cards`, `study_sessions`, `study_session_cards`, `study_attempts`, `study_source_documents`).

Sigue existiendo una dualidad de datos entre el modelo filesystem original (carpetas en `proyectos/` con `preguntas.txt`) y el modelo MySQL nuevo; la Épica #7 ya implementó el importador (`php/project_import.php`, CLI `tools/import_projects.php`) para migrar contenido de uno a otro.

## 4. API

`api.php` es el endpoint central del tablero principal: expone acciones de lectura (`list_boards`, `list`, `get`, `session_state`) y de escritura (`grade`, `send_report`, `save_stat`, `reset_stats`, `unirse_sesion`, `start_turn`, `roll_dice`, `submit_answer`, `end_turn`, `guardar_intento`, `guardar_resultados`), con helpers reutilizables como `triviax_api_error()`, `triviax_api_json()`, `triviax_api_require_post()` y `triviax_api_throttle()`. Cada modalidad adicional tiene su propio endpoint especializado: `php/study_answer_api.php`, `php/jigsaw_api.php`, `php/lotto_api.php`, `php/etiquetar_api.php`, `php/crossword_api.php` y `php/wordsearch_api.php`. Los contratos JSON de cada endpoint están documentados con ejemplos en `API.md`.

Para las actividades en tiempo real, ya existe soporte de Server-Sent Events (`events.php`, `panel/live_session.php`) para el monitor en vivo del tablero principal; extenderlo a Lotto (host y estudiante) sigue pendiente (Épica #10).

## 5. Modos de juego / actividades

| Modalidad | Archivo(s) de entrada | Backend | Mecánica |
|---|---|---|---|
| Tablero principal | `index.html` | `api.php` | Juego de mesa clásico: tirar el dado, responder para avanzar, llegar a la meta. |
| Estudia y responde | `study.php` | `php/study_answer_api.php` + `study_answer_engine.php` | Repaso espaciado individual (estilo Leitner/SM-2), sin necesidad de sesión grupal. |
| TRIVIAX Lotto | `lotto.php` (estudiante), `lotto_host.php` (pizarra/proyector, requiere acceso docente) | `php/lotto_api.php` + `lotto_engine.php`/`lotto_validator.php`/`lotto_ai.php` | Lotería oral: el docente sortea preguntas frente al curso, los estudiantes responden por código y número de ficha, con temporizador de fase. |
| Crucigrama | `crucigrama.php` | `php/crossword_api.php` | Catálogo de crucigramas publicados por el docente, jugables sin sesión previa. |
| Sopa de letras | `sopa.php` | `php/wordsearch_api.php` | Mismo patrón de catálogo público que el crucigrama. |
| Rompecabezas (Jigsaw) | `jigsaw.php` | `php/jigsaw_api.php` + `jigsaw_engine.php`/`jigsaw_validator.php` | Arma un rompecabezas a partir de una imagen cargada por el docente. |
| Etiquetar | `etiquetar.php` | `php/etiquetar_api.php` + `etiquetar_engine.php` | Arrastrar etiquetas a la posición correcta sobre una imagen o diagrama. |

Nota: las guías existentes (`docs/GUIA_DOCENTE_TRIVIAX.md` y `docs/GUIA_JUGADORES_TRIVIAX.md`) todavía documentan solo Tablero, Estudia y Responde, y Lotto como modalidades A/B/C; crucigrama, sopa, jigsaw y etiquetar aún no están incorporadas como una "Modalidad D" formal en esas guías.

## 6. Tableros disponibles

El motor del tablero principal define tres perfiles en `js/config.js` (`BOARD_PROFILES`, función `getBoardProfile()`):

- **Oca**: serpentina, 50 casillas, condición de victoria por carrera/llegada exacta o por puntos.
- **Monopoly**: recorrido rectangular en bucle, 40 casillas, victoria solo por puntos.
- **Circular**: recorrido circular en bucle, 36 casillas, victoria solo por puntos.

Desde la versión 6.1 se suman tableros personalizados por actividad: el docente sube una imagen propia ya con las casillas pintadas y la ficha salta casilla a casilla sin que el motor dibuje el recorrido; el tablero queda fijado a una actividad específica mediante `board.json` (campo `lockedId`). Este generador está mayormente funcional, con un commit pendiente de aplicar y validaciones opcionales aún abiertas (proporción de imagen 16:9, casillas especiales).

Las casillas especiales (bono, penalización, colaborativa, evento narrativo) están descritas a nivel de catálogo/roadmap pero todavía no completamente implementadas en el generador de tableros personalizados (el arreglo `specialCells` figura vacío).

## 7. Tipos de desafío / pregunta

El validador de desafíos (`js/validators/challengeValidators.js`) reconoce como tipos base: opción múltiple, verdadero/falso, asociar parejas, ordenar secuencias, clasificación por categorías, arrastrar y soltar, y completar espacios (con variante de selección). La documentación técnica interna menciona alrededor de 11 tipos en total, sumando variantes como elección con imagen, código y "hotspot" (punto sobre imagen).

## 8. Estado de desarrollo y roadmap

El informe técnico de referencia (`docs/reports/reporte260617.md`, 17 de junio de 2026) describe a TRIVIAX como una plataforma que pasó de tablero único a multi-modalidad, señalando que la convivencia de dos modelos de datos (filesystem legado y MySQL nuevo) es lo que más condiciona su evolución futura.

Resuelto recientemente: aislamiento de datos entre docentes, limitador de tasa, ocultamiento de errores en producción, eliminación de credenciales de correo hardcodeadas, importador filesystem→BD (Épica #7), consistencia de versión centralizada, y el cierre de una fuga de seguridad en el saneo de respuestas del lado servidor (documentado en `retomar-6.3c.md`, con 56 pruebas pasando).

Pendiente: activación de claves de Turnstile (CAPTCHA) en producción, extensión de Server-Sent Events a Lotto (Épica #10, para reducir la latencia del polling), y ampliar la cobertura de pruebas de integración, hoy parcial.

El catálogo interno de actividades recomendadas para futuras versiones agrupa ideas en tres franjas: diferenciadores técnicos de corto-mediano plazo (trazado y depuración de algoritmos, diagramas de flujo, simulación de máquinas de estado), actividades para más adelante (laberinto/persecución, editor visual tipo Genially, arcade/plataformas), y casillas transversales de tablero (bono, penalización, colaborativa, evento narrativo) ya mencionadas arriba.

## 9. Zona experimental

La carpeta `experimental/` contiene prototipos aislados que no se cargan desde `index.html`, `js/` ni el service worker, por lo que no afectan la aplicación en producción: `splash-helice/` (efecto visual de pantalla de inicio), `jigsaw/` (versión standalone con backend PHP+GD, modos de bandeja y de piezas revueltas), `etiquetados/` (versión standalone con marcado de punto y línea guía), `sopa-letras/` (generador con estimador de capacidad y apoyo de IA en dos pasos) y `crucigramas/` (algoritmo greedy de encaje por intersecciones, sin garantía de simetría perfecta). Todo indica que estas carpetas fueron los prototipos previos a las versiones productivas actuales (`crucigrama.php`, `sopa.php`, `jigsaw.php`, `etiquetar.php`).

## 10. Testing

La carpeta `tests/` mantiene una suite versionada ejecutable con `tests/run.php`, que incluye pruebas de evaluación de tablero, calificación de tablero, validadores de desafíos e importación de proyectos. Existen además scripts de prueba más antiguos en `scratch/` (por ejemplo `test_fase_45.php`, `test_auditoria_seguridad.php`) que requieren un entorno WAMP/PHP CLI local para ejecutarse. `TESTING.md` documenta el enfoque general de pruebas del proyecto.

## 11. Documentación existente

- `README.md`: instalación en Wamp64/Apache, formato del archivo `preguntas.txt`, reglas del juego de tablero clásico.
- `docs/GUIA_DOCENTE_TRIVIAX.md` y `docs/GUIA_JUGADORES_TRIVIAX.md`: guías de uso para docentes y estudiantes (registro, panel, modalidades A/B/C, tipos de desafío, buenas prácticas, privacidad). Ambas exportadas también en PDF.
- `API.md`, `SECURITY.md`, `TESTING.md`, `BACKUP_RESTORE.md`: documentación técnica de referencia por área.
- `AGENTS.md`: documento maestro de instrucciones para agentes de IA que trabajan en el código (histórico de versiones, reglas inmutables de diseño y estética, épicas de desarrollo).
- `graphify-out/`: grafo de conocimiento del código (2293 nodos, 3198 aristas, 230 comunidades), útil para navegar relaciones entre archivos sin recorrer el código fuente completo.

## 12. Resumen ejecutivo

TRIVIAX es una plataforma educativa PHP/MySQL con siete modalidades jugables (tablero, estudia y responde, lotto, crucigrama, sopa de letras, jigsaw y etiquetar), panel docente completo, sistema de estadísticas, PWA instalable y una base de seguridad razonable (CSRF, rate limiting, bcrypt, auditoría). Está en desarrollo activo: la versión de código es 7.0.2, con trabajo reciente centrado en la capa de identidad/acceso/evaluación (v7.0), el modo «Camino al Gol» de fútbol con movimiento fluido, portabilidad, migración de datos filesystem→BD y tiempo real vía SSE. Los principales pendientes son la finalización de casillas especiales de tablero, la extensión de tiempo real a Lotto, la activación de Turnstile en producción y una mayor cobertura de pruebas de integración.
