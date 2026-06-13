# TRIVIAX Lotto — Estado de implementación

**Fecha:** 2026-06-11
**Especificación de referencia:** `C:\Users\Usuario\Downloads\TRIVIAX_LOTTO_PROMPT_IMPLEMENTACION.md`
**Sesión interrumpida por límite de uso.** Este documento registra el avance exacto para retomar.

---

## Decisiones tomadas con el usuario

1. **Generación con IA:** se usa el patrón del asistente de "Estudia y responde" — el panel genera un
   prompt completo (incluye documento fuente, lista de estudiantes y configuración) para que el docente
   lo pegue en su chatbot y luego pegue el JSON resultante. Queda PREVISTO el acceso futuro a un LLM
   propio vía API: `php/lotto_ai.php` es el adaptador desacoplado (hoy stub; se activa definiendo
   `LLM_PROVIDER`, `LLM_API_KEY`, `LLM_MODEL` en `triviax.env` e implementando `_lotto_ai_call_provider()`).
2. **Migración SQL:** aplicada ya en la BD local `triviax` (las 9 tablas existen). El archivo
   `NoSubir/triviax_db_lotto.sql` queda para aplicar en el servidor remoto (phpMyAdmin). Es idempotente
   (CREATE TABLE IF NOT EXISTS) y reversible con DROP de las tablas `lotto_*`.

---

## ✅ COMPLETADO

### Fase 1 — Base de datos
- `NoSubir/triviax_db_lotto.sql` creado y **aplicado en BD local** (verificado: 9 tablas
  `lotto_activities, lotto_sections, lotto_students, lotto_assignments, lotto_timer_events,
  lotto_draws, lotto_evaluations, lotto_student_responses, lotto_events`).

### Fase 2/3 — Backend (lint OK con php -l)
- `php/lotto_validator.php` — validador autoritativo (espejo pendiente en JS). Valida metadata.mode,
  settings, rúbrica, 4-5 secciones, estudiantes (números únicos, máx 60), asignaciones completas,
  límites (studyText 2000, doc 12000, preguntas 1-5). Mensajes en español.
- `php/lotto_engine.php` — motor completo: generación de código 6 chars, guardado transaccional
  (solo draft editable), máquina de estados (draft→published→login_open→study→response→oral→finished
  →archived; activo→cancelled), deadlines de fase calculados con base+extensiones desde
  `lotto_timer_events`, login de estudiante con token sha256 + rechazo `STUDENT_ALREADY_LOGGED`
  (clase `LottoConflictException`), ficha del estudiante SIN respuestas esperadas, respuesta escrita
  (borrador/envío → estado ready), sorteo (`draw_initial` 5 visibles/1 current, `advance_draw` con
  resoluciones evaluated/postponed/absent/skipped, pool excluye pending/absent y evaluated salvo
  random_simple), evaluaciones (upsert con rúbrica/quick_result/puntaje/comentario), `host_state`
  (estados efectivos con desconexión >30 s sin heartbeat), `student_state`, reporte completo con resumen.
- `php/lotto_api.php` — todos los endpoints `lotto_*` (docente con sesión+CSRF+propiedad, estudiante
  con token+CSRF+rate limits según spec §11). `lotto_evaluate_student` acepta `advance_draw:true`
  para avanzar el sorteo en la misma llamada. `lotto_release_student_login` implementado.
- `php/lotto_ai.php` — adaptador IA stub preparado para proveedor futuro.
- `api.php` — dispatcher `lotto_*` agregado (después del de `study_`).

### Fase 9 (parcial) — Pruebas backend
- `scratch/test_lotto.php` — **72/72 PASS** (ejecutar:
  `C:/wamp64/bin/php/php8.2.0/php.exe scratch/test_lotto.php`). Cubre: validación (12 casos),
  guardado/propiedad, máquina de estados, login/suplantación/token inválido, ficha sin respuestas
  esperadas, temporizadores + extensión, respuesta escrita, sorteo completo (evaluar/posponer/ausente/
  no_repeat), host_state/student_state sin fugas, reporte y cierre. Auto-cleanup.

### Fase 4 — Panel docente
- `panel/lotto.php` — página completa (estética TRIVIAX, topbar con Dashboard).
- `js/panel/lottoPanel.js` — asistente de 7 pasos (datos → documento → estudiantes → config →
  prompt IA → pegar JSON → creada), genera prompt con documento+estudiantes+config embebidos,
  normaliza el JSON pegado (la config del asistente manda), valida vía API, guarda y **publica
  automáticamente**, muestra código de 6 caracteres y link a pantalla del salón. Listado con acciones
  por estado (Publicar / Abrir ingreso / Pantalla salón / Reporte / Archivar) y reporte renderizado.

### Fase 5 — Pantalla estudiante
- `lotto.php` (raíz) — login código+número, ficha (tema, texto de estudio, ideas clave, preguntas guía,
  pregunta oral), barra de fase con timer, aviso "¡Es tu turno!", respuesta breve con guardar/enviar
  y botón "Estoy preparado/a". Acepta `?code=ABC123` para pre-llenar.
- `js/engines/lottoStudentEngine.js` — login, restauración de sesión por localStorage
  (`triviax_lotto_{activityId}_student_{studentId}_token`), polling cada 4 s (sirve de heartbeat),
  timer sincronizado con reloj del servidor, manejo de número liberado por el docente (403 → volver
  al login), envío de respuesta con idempotency_key, sin innerHTML para contenido (textContent).

---

## ⏳ PENDIENTE (en orden sugerido para retomar)

### 1. Fase 6 — Pantalla host (lo más importante que falta)
- **`lotto_host.php`** (raíz) — requiere sesión docente (`triviax_requerir_auth(TRIVIAX_ROL_DOCENTE)`),
  recibe `?code=ABC123`, resuelve la actividad (verificar propiedad server-side) y embebe
  `activity_id` + CSRF. Layout pizarra (spec §13.3): lista lateral números+nombres de pila con estados
  visuales, zona central con 5 números (current destacado con glow), timer grande, controles
  (Abrir ingreso / Iniciar estudio / Extender +2 min / Iniciar respuesta / Extender +1 min /
  Iniciar orales / Sortear / Finalizar), modal de evaluación con rúbrica (rubric_json de la actividad),
  resultado rápido, puntaje y observación. `prefers-reduced-motion` respetado.
- **`js/engines/lottoHostEngine.js`** — polling `lotto_host_state` cada 2 s; timer sync con server_now;
  botones llaman: `lotto_open_login`, `lotto_start_study`, `lotto_extend_study` (extra_seconds:120),
  `lotto_start_response`, `lotto_extend_response` (60), `lotto_start_oral`, `lotto_draw_initial`,
  `lotto_evaluate_student` (con `advance_draw:true`), `lotto_postpone_student`, `lotto_mark_absent`,
  `lotto_release_student_login` (botón por estudiante en la lista), `lotto_finish_activity`.
  El modal de evaluación necesita la pregunta oral + respuestas esperadas del estudiante current:
  se pueden obtener del reporte (`lotto_report`) o agregar un endpoint
  `lotto_student_detail_for_teacher` (más liviano; valorar al retomar — el reporte ya trae todo).
- Estados visuales (spec §13.3): pending neutro, logged verde flúo/check, studying cian, ready verde,
  called dorado, evaluated violeta, postponed naranja, absent gris, disconnected rojo suave
  (el backend ya entrega `effective_status` calculado).

### 2. Validador JS espejo
- `js/validators/lottoValidator.js` — espejo de `php/lotto_validator.php` (opcional para v1: el panel
  ya valida vía API antes de guardar; la spec lo pide, conviene crearlo aunque sea con las reglas core).

### 3. Documentación y cierre
- `docs/LOTTO.md` — doc técnico + instructivo docente (flujo completo, endpoints, estados, seguridad).
- Enlace en `panel/dashboard.php` a `panel/lotto.php` (junto al de "Estudia y responde"; buscar la
  sección donde está el botón de study_answer, línea ~456 tiene los botones legados como referencia).
- Actualizar `AGENTS.md`: fila en tabla 2.1, registro de decisiones (patrón asistente IA, migración),
  y `ACTIVIDADES.md` (existe en docs/ACTIVIDADES.md) con la nueva modalidad.
- Bump `APP_VERSION` en `service-worker.js` (está en **5.0.7**; pasar a 5.0.8 al tocar JS).
  Nota: panel/lotto.php y lotto.php ya referencian css `?v=5.0.7`.

### 4. Verificación end-to-end (pendiente por completo)
- `php -l` ya pasó en todo lo creado; falta `node --check` de los 2 JS nuevos
  (`js/panel/lottoPanel.js`, `js/engines/lottoStudentEngine.js`) y de los que se creen.
- Flujo HTTP completo con sesión docente (patrón conocido, ver memoria del proyecto):
  login docente vía Invoke-WebRequest (cookie SESSID), crear actividad con el fixture
  `docs/fixtures/lotto_demo.json` vía `lotto_save_activity` + `lotto_publish_activity` +
  `lotto_open_login`, login estudiante vía `lotto_student_login` (sin sesión, requiere CSRF de la
  página lotto.php), recorrer fases y sorteo, verificar reporte. El preview de Claude bloquea cookies:
  para UI usar el truco de guardar HTML autenticado en scratch/ (ver memoria triviax-dev-environment).
- Probar las pantallas en el navegador (panel, estudiante, host).

### 5. Entregable para el usuario
- Recordarle aplicar `NoSubir/triviax_db_lotto.sql` en el servidor remoto (local YA está aplicado).
- Resumen de archivos nuevos/modificados y riesgos pendientes.

---

## Archivos creados hasta ahora

```
NoSubir/triviax_db_lotto.sql        ✅ (aplicado en BD local)
php/lotto_validator.php             ✅
php/lotto_engine.php                ✅
php/lotto_api.php                   ✅
php/lotto_ai.php                    ✅ (stub para LLM futuro)
docs/fixtures/lotto_demo.json       ✅ (demo completo: 4 estudiantes, 4 secciones, 4 asignaciones)
scratch/test_lotto.php              ✅ (72/72 PASS)
panel/lotto.php                     ✅
js/panel/lottoPanel.js              ✅ (falta node --check)
lotto.php                           ✅
js/engines/lottoStudentEngine.js    ✅ (falta node --check)
scratch/tmp_check_lotto.php         🗑 borrar (script auxiliar de verificación)
```

## Archivos modificados

```
api.php                             ✅ dispatcher lotto_* agregado tras el de study_
```

## Notas técnicas para quien retome

- Convenciones API: respuestas con `triviax_api_success/error`; errores de estado → HTTP 409
  `INVALID_STATE`; validación → 422 `VALIDATION_ERROR`.
- El estudiante NUNCA debe recibir `teacher_expected_answers_json` ni `oral_followups_json`
  (hay tests que lo verifican).
- `lotto_student_state` actualiza `last_seen_at` (polling = heartbeat); el host marca
  `disconnected` si pasan >30 s (constante `LOTTO_DISCONNECT_SECONDS`).
- Trampa conocida del proyecto: nombres de función PHP deben ser únicos entre archivos incluidos
  juntos (todo lo lotto usa prefijo `lotto`/`_lotto`).
- Usuario docente de prueba: crear temporal con `password_hash()` y borrarlo al final
  (patrón en scratch/test_lotto.php).
```
