# Épica #7 / #1 Etapa 2 — COMPLETADA (incluye 6.3c)

> **ESTADO: cerrado el 2026-06-18.** El paso 6.3c ya está implementado y
> verificado (`action=get` saneado; el servidor sigue evaluando contra los datos
> originales). La fuga #1 queda cerrada por completo. El resto del documento se
> conserva como registro de diseño y verificación.
>
> **Implementado en 6.3c:**
> - `php/board_eval.php`: `triviax_board_sanitize_challenge_for_client()` (por tipo).
> - `api.php` (`action=get`): ambas ramas (json y txt) pasan por el saneador.
> - `tests/board_grade_test.php`: casos del saneador (56 OK, 0 FAIL).
> - Verificado por HTTP en :8123 (demo_mixto, 6 tipos): SIN fugas; `grade`
>   sigue dando `correct:true` para la opción buena y revela la solución solo
>   en el feedback.

---

**Fecha de suspensión (histórico):** 2026-06-18
**Rama:** `master` · árbol **limpio** (todo commiteado)
**Último commit al suspender:** `06a7e14` (paso 6.3b)

---

## Dónde quedamos

Sesión larga sobre la Épica #7 (importador filesystem→BD) y el cierre de #1
Etapa 2 (validación server-side de TODOS los tipos + dejar de exponer respuestas).
Commits de la sesión, en orden:

| Commit | Qué cierra |
|---|---|
| `cb0520d` | #7.1-3 importador base (mapeo + import + migración 6.2 + tests) |
| `df83ae1` | #7.4 disparadores en admin.php + CLI `tools/import_projects.php` |
| `0b043c4` | #7.5 lecturas desde BD en `action=get` y `submit_answer` (fallback FS) |
| `98f2627` | WIP "línea de recorrido" (showPath en tableros pelados) — no relacionado |
| `e088a91` | #6.1 grader server-side de los 9 tipos (`triviax_board_grade_answer`) |
| `b988fae` | #6.2 el cliente envía la respuesta cruda `raw` por tipo |
| `c92a9fe` | #6.3a endpoint `action=grade` sin estado + `triviax_board_solution_for_client` |
| `06a7e14` | #6.3b el cliente delega el veredicto al servidor + **Brecha B cerrada** |
| `2ea3fbb` | #6.3c sanea `action=get` y cierra la fuga de respuestas |

**Estado de seguridad actual:** en modo online el servidor ya evalúa
autoritativamente todos los tipos (endpoint `grade` + `submit_answer`), el cliente
usa ese veredicto para el display y el feedback (muestra la solución que devuelve
el servidor). **La fuga de respuestas quedó cerrada:** `action=get` devuelve los
desafíos saneados al navegador y el servidor conserva los datos completos para
evaluar.

---

## TAREA CERRADA: paso 6.3c (sanear `action=get`)

> Esta sección queda como registro histórico del diseño ejecutado, no como lista
> pendiente.

### Hallazgo clave (ya verificado por revisión)
**NO hace falta tocar los renderers.** Cada renderer ya arma su `raw` desde los
elementos mostrados, y su `isCorrect` local quedó **sin usar** (el veredicto lo da
el servidor). Por eso basta con que `action=get` devuelva los desafíos **saneados**;
los renderers degradan con elegancia (su grading local da resultado erróneo pero
nadie lo mira). Verificado que ninguno lanza excepción si faltan los datos de
respuesta (todos usan optional chaining / fallback).

### Qué se implementó
1. **`php/board_eval.php`** — nueva función pura
   `triviax_board_sanitize_challenge_for_client(array $c): array`, por tipo
   (normalizar con `triviax_normalize_challenge_type`):
   - **Siempre:** quitar `correct` de `options[]` y de `answers[]` (cubre choice/
     media/true_false/txt).
   - `multiple_choice`/`media_choice`: `unset($c['answer']['correctOptionId'])`.
   - `true_false`: `unset($c['answer']['value'])`.
   - `sequence_order`: `unset($c['answer']['order'])` + `shuffle($c['items'])`
     (el array `items` filtra el orden correcto).
   - `matching_pairs`: **desacoplar** — barajar la columna derecha entre las
     entradas para romper la asociación `pairs[i].left↔pairs[i].right` (que ES la
     respuesta). Asegurar que el orden resultante no sea idéntico al original
     (si `count>1` y quedó igual, intercambiar [0] y [1]). El renderer construye
     left/right de `pairs[].left`/`pairs[].right` por separado, así que sigue
     mostrando todos los pares; el `raw` (mapa left→right) se evalúa contra los
     pares ORIGINALES en el servidor.
   - `drag_drop` (classification): quitar `categoryId` de cada `items[]` + barajar
     `items`.
   - `fill_blank`: quitar `correct` de cada `blanks[k]` (conservar `options`).
   - `image_hotspot`: `unset($c['answer']['hotspot'])`.
   - `code_challenge`: `unset($c['answer']['lines'])` + `shuffle($c['lines'])`.
   - Al final: si `$c['answer']` quedó vacío, quitarlo.
2. **`api.php` (`action=get`)** — envolver la lista de `questions` con el saneador
   en **ambas ramas** (json y txt):
   `'questions' => array_map('triviax_board_sanitize_challenge_for_client', $dbChallenges ?? $parsedJsonProject['challenges'])`
   y lo análogo para `$parsedProject['questions']`. (El endpoint `grade` y
   `submit_answer` NO se sanean: necesitan los datos completos del lado servidor.)
3. **Tests** — añadir a `tests/board_grade_test.php` (o nuevo) casos puros del
   saneador: que no quede `correct`/`correctOptionId`/`value`/`order`/`hotspot`/
   `categoryId`/`lines`, y que matching quede desacoplado.

### Verificación hecha
- `api.php?action=get&project=demo_mixto` devuelve 6 preguntas.
- Revisión estructural del JSON recibido: 0 claves sensibles (`correct`,
  `correctOptionId`, `value`, `order`, `hotspot`, `categoryId`, `lines`) fuera de
  textos de feedback.
- `api.php?action=grade` con CSRF y `demo_mixto/mc_001` responde HTTP 200 con
  `correct:true` para `raw.optionId = a`.
- `tests/run.php`: 3 suites, 81 OK, 0 FAIL.
- `tests/board_grade_test.php`: 56 OK, 0 FAIL.
- `tests/project_import_test.php`: 16 OK, 0 FAIL.

### Consecuencia a comunicar (decidido por el usuario: "completo, todos los modos")
Tras sanear, **el juego online siempre requiere el servidor para evaluar**. En modo
**PWA verdaderamente offline (sin red)** la evaluación no es posible (no hay servidor
ni respuestas en el cliente); el fallback de `ui.js` caería al `isCorrect` local que
ahora es erróneo. Si el offline competitivo importa, habría que cachear un veredicto
o aceptar que offline no puntúa. (El modo pantalla-única ONLINE sí funciona: hay
servidor PHP.)

---

## Notas de entorno (para retomar sin fricción)
- PHP CLI: `/c/wamp64/bin/php/php8.2.0/php.exe`. Tests: `php tests/board_grade_test.php`,
  `php tests/board_eval_test.php`, `php tests/project_import_test.php`.
- BD dev: root sin password; `demo_mixto` tiene los 6 tipos base importados.
- `graphify` exe en `/c/Users/Usuario/.local/bin/graphify`; **`graphify update .` se
  niega** (AST da ~1125 vs ~2251 nodos enriquecidos). El hook de git relanza el
  rebuild en cada commit; no forzar.
- Helpers api.php: `triviax_api_require_post()`, `triviax_verify_csrf_json()`,
  `triviax_api_throttle(scope,max,win)`, `triviax_api_input()`, `triviax_api_success()`,
  `triviax_api_error()`. CSRF: GET `action=list` setea `$_SESSION['triviax_csrf']` y
  devuelve `csrf_token`; enviarlo como header `X-CSRF-Token`.
- Pendiente mayor aparte de #7: Épica #10 (tiempo real/SSE) — ver
  [plan-epicas-7-10.md](plan-epicas-7-10.md). Operativo: cargar claves Turnstile en prod.
