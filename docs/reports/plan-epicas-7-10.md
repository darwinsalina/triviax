# Estado de épicas — #7 completada y #10 pendiente

**Fecha:** 2026-06-18
**Contexto:** actualización posterior a la sesión que cerró la Épica #7 y la
Etapa 2 de #1. Este documento queda como registro de lo construido y como plan
vigente para la Épica #10.

---

## Épica #7 — Importador filesystem → BD (COMPLETADA)

### Qué quedó implementado
- Migraciones versionadas: `db/migraciones/6.0_actividades_imagenes.sql`,
  `db/migraciones/6.1_tableros.sql` y `db/migraciones/6.2_desafios.sql`.
- Mapeo/importación de desafíos desde filesystem a BD en `php/project_import.php`.
- CLI de carga masiva en `tools/import_projects.php`.
- Disparadores desde `admin.php` para importar/sincronizar al guardar actividades.
- `api.php?action=get` y `submit_answer` leen desde BD cuando hay actividad
  importada, con fallback al filesystem para compatibilidad.
- Grader server-side para todos los tipos del tablero en `php/board_eval.php`.
- Endpoint `action=grade` y delegación del veredicto desde el cliente.
- Saneador `triviax_board_sanitize_challenge_for_client()` para que `action=get`
  no exponga respuestas correctas al navegador.

### Criterios de aceptación
- `tests/run.php`: 3 suites, 81 OK, 0 FAIL.
- `tests/project_import_test.php`: mapeo puro en verde.
- `tests/board_grade_test.php`: grader + saneador en verde.
- Verificación HTTP local contra Apache/WAMP:
  `api.php?action=get&project=demo_mixto` devuelve 6 preguntas y 0 claves
  sensibles (`correct`, `correctOptionId`, `value`, `order`, `hotspot`,
  `categoryId`, `lines`) fuera de textos de feedback.
- `api.php?action=grade` con CSRF corrige `demo_mixto/mc_001` con HTTP 200 y
  `correct:true` para la opción correcta.

### Consecuencia de diseño
El tablero online ya no necesita confiar en el veredicto del cliente. En PWA
verdaderamente offline, sin PHP disponible, no hay corrección competitiva fiable
porque las respuestas correctas ya no viajan al navegador.

---

## Épica #10 — Tiempo real (EN PROGRESO)

### Por qué
El monitor docente y las modalidades en vivo usan *polling* (~5 s):
`js/engines/lottoStudentEngine.js` y `lottoHostEngine.js` (`pollState`),
`session_state` del tablero y `panel/live_sessions.php`. Genera latencia y carga
innecesaria de BD/HTTP.

### Opciones
- **A — SSE (Server-Sent Events):** un endpoint `events.php` que emite cambios de
  estado por `text/event-stream`. Simple, unidireccional (servidor→cliente), que
  es justo lo que necesitan el monitor y el estudiante. Sin dependencias nuevas.
  **Recomendada** para empezar.
- **B — Mercure/WebSocket:** bidireccional y escalable, pero añade un servicio a
  desplegar (hub Mercure o servidor WS). Mayor coste operativo.

### Pasos (opción A)
1. ✅ Endpoint SSE `events.php?stream=live_session_summary&id=...` para el
   monitor docente de una partida.
2. ✅ Helper compartido `php/live_session_summary.php`, usado tanto por AJAX
   legado como por SSE.
3. ✅ Cliente `panel/live_session.php` con `EventSource`, heartbeat,
   reconexión y fallback automático al polling de 5 s si SSE no entrega eventos.
4. ✅ Streams cortos (25 s) para no retener workers Apache/PHP indefinidamente.
5. ⬜ Extender el patrón a Lotto host/estudiante y otros monitores.
6. ⬜ Evaluar una columna `state_version` si el volumen de sesiones exige evitar
   consultas periódicas dentro del stream.

### Riesgos
- Apache/PHP con `mod_php` mantiene un worker por conexión SSE abierta: limitar
  duración del stream y nº de conexiones; documentar requisitos del hosting.
- Requiere pruebas en navegador (no verificable en este entorno CLI).

### Criterios de aceptación
- Monitor docente del tablero: implementado con SSE + fallback a polling.
- `scratch/test_live_session_summary.php`: 10 OK, 0 FAIL.
- Pendiente de aceptación visual: abrir una partida real en el navegador y
  confirmar actualización < 1 s en el monitor.
- Pendiente para cerrar toda la épica: Lotto host/estudiante con SSE.

---

## Próximo bloque real
La siguiente iteración de #10 es llevar el mismo patrón SSE a Lotto
host/estudiante. Requiere navegador para validar reconexión, latencia y consumo
de workers en Apache/PHP.
