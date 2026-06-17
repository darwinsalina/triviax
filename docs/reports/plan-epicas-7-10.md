# Plan de épicas — #7 (importador filesystem→BD) y #10 (tiempo real)

**Fecha:** 2026-06-17
**Contexto:** áreas críticas restantes del [reporte260617.md](reporte260617.md) §7.
Son las únicas dos que **no** se completaron en la sesión de quick-wins porque
exceden una sesión y requieren un MySQL vivo (y, para #10, navegador) para
construirse y verificarse. Este documento las deja listas para aprobar.

---

## Épica #7 — Importador filesystem → BD (dualidad de datos)

### Por qué
Hoy el tablero clásico lee los desafíos de `proyectos/*/preguntas.txt` o
`proyecto.json` (filesystem); solo `php/project_sync.php` registra **metadata**
en la tabla `proyectos`, no los desafíos. Esa dualidad:
- Obliga al servidor a re-parsear archivos en cada `submit_answer` (ya resuelto
  parcialmente en #1 Etapa 1).
- Bloquea la **Etapa 2 de #1** (validación server-side de tipos estructurados).
- Bloquea reportes ricos, estadísticas por desafío en BD y modos competitivos.

### Estado del esquema
Las tablas destino existen en el respaldo local
`NoSubir/respaldos sql/triviax_*.sql` (Fase 2 del AGENTS.md): `activities`,
`activity_settings`, `challenges`, `challenge_options`, `challenge_pairs`,
`challenge_sequence_items`. **Acción previa:** confirmar contra la BD viva qué
tablas/columnas existen realmente y formalizarlas como migración versionada
`db/migraciones/6.2_actividades_desafios.sql` (hoy no hay DDL de estas tablas en
el repo).

### Pasos
1. **Reconciliar esquema.** Volcar el `CREATE TABLE` real de la BD y versionarlo
   como migración. Añadir índices por `proyecto_id`/`challenge_key`.
2. **Mapeo puro (testeable sin BD).** Función `triviax_map_challenge_to_rows($challenge)`
   que transforme cada desafío normalizado en filas de `challenges` (+ tablas
   hijas según tipo: `challenge_options` para choice/media, `challenge_pairs`
   para matching, `challenge_sequence_items` para sequence, JSON para
   classification/fill_blank/hotspot/code). **Unit test en `tests/`** con un
   proyecto de cada tipo (sin BD).
3. **Importador.** `triviax_import_project($pdo, $slug)` que parsea el proyecto
   del filesystem y hace upsert idempotente de actividad + desafíos, preservando
   `docente_id` (reusar la regla de propiedad de
   `triviax_docente_puede_gestionar_proyecto`). Transaccional.
4. **Disparadores.** Llamar al importador desde `admin.php` al crear/editar
   actividad (junto a `triviax_sync_single_project`) y un comando CLI
   `tools/import_projects.php` para la carga inicial masiva.
5. **Re-apuntar lecturas.** `api.php?action=get` y `submit_answer` leen de BD
   cuando la actividad está importada (fallback a filesystem si no).
6. **Cerrar #1 Etapa 2.** Con los desafíos en BD y el cliente enviando la
   respuesta cruda estructurada, validar server-side **todos** los tipos y dejar
   de exponer las respuestas correctas en `action=get`.

### Riesgos
- Doble fuente de verdad durante la transición: definir claramente cuál manda.
- Cobertura de los ~10 tipos en el mapeo (mitigar con unit tests por tipo).
- Requiere MySQL de pruebas para los pasos 3–6.

### Criterios de aceptación
- Migración versionada aplicable en limpio.
- Unit tests del mapeo (todos los tipos) en verde sin BD.
- Una actividad importada se juega leyendo de BD y `submit_answer` valida todos
  los tipos server-side; `action=get` no incluye `correct`.

---

## Épica #10 — Tiempo real (reemplazar el polling)

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
1. Tabla/columna de "versión de estado" por sesión (`updated_at`/`state_version`)
   para detectar cambios baratos.
2. Endpoint SSE que hace long-poll/stream del estado y emite solo deltas.
3. Cliente: `EventSource` con reconexión y *fallback* automático al polling
   actual si SSE no está disponible (degradación elegante).
4. Mantener el polling como fallback hasta validar SSE en producción.

### Riesgos
- Apache/PHP con `mod_php` mantiene un worker por conexión SSE abierta: limitar
  duración del stream y nº de conexiones; documentar requisitos del hosting.
- Requiere pruebas en navegador (no verificable en este entorno CLI).

### Criterios de aceptación
- El monitor docente refleja cambios en < 1 s sin polling de 5 s.
- Fallback a polling si el navegador/servidor no soporta SSE.

---

## Nota de método
Ambas épicas se construyen y prueban con MySQL (y, para #10, navegador), no
disponibles en el entorno CLI de esta sesión. Por eso se entregan como plan en
vez de código no verificable. La parte **pura** de #7 (mapeo) sí es unit-testeable
sin BD y debería ser el primer commit cuando se arranque.
