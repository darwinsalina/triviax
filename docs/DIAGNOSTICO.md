# TRIVIAX+ Diagnóstico Pedagógico Predictivo (Épica 4, v7.4)

Analiza las matrices de acierto-error registradas en base de datos y agrupa a
los estudiantes de cada actividad en **tres perfiles pedagógicos**, con un
generador de **actividades de refuerzo** asistido por IA.

## 1. Motor de agrupamiento (`php/diagnostico_engine.php`)

Sin cambios de base de datos: opera como capa analítica sobre `intentos`,
`sesiones`, `sesion_jugadores` y `stats_desafios`.

Clasificador determinista por **umbrales estricto-pedagógicos** (opción
habilitada por el plan frente a K-means: con cohortes chicas es estable y
explicable). Tipos "complejos" (aplicación): `drag_drop`, `code_challenge`,
`sequence_order`, `matching_pairs`, `image_hotspot`, `fill_blank`.

| Perfil | Regla |
|---|---|
| 🟡 Inconsistencia de Aplicación | ≥3 intentos complejos, acierto general ≥50 % y brecha ≥20 puntos frente al acierto complejo (o complejo <50 %). |
| 🔴 Comprensión Crítica | Acierto general <67,5 % (o sin datos: prioridad de atención). |
| 🟢 Dominio Avanzado | El resto. |

Funciones: `triviax_diagnostico_perfil()` (clasificador puro),
`triviax_diagnostico_clasificar()` (agrupa y calcula porcentajes),
`triviax_diagnostico_metricas_proyecto()` (SQL por estudiante),
`triviax_diagnostico_desafios_debiles()` (peor acierto acumulado, ≥3 muestras)
y `triviax_diagnostico_prompt_refuerzo()` (prompt canónico para la IA).
Tests puros: `tests/diagnostico_test.php`.

## 2. Cliente LLM (`php/llm_client.php`)

Implementa la interfaz que `php/lotto_ai.php` dejó prevista. Configuración en
`triviax.env` (fuera del webroot):

```
LLM_PROVIDER=anthropic   # o gemini
LLM_API_KEY=…
LLM_MODEL=claude-sonnet-5   # opcional
```

- `triviax_llm_available()` / `triviax_llm_generate($prompt)` /
  `triviax_llm_extract_json($texto)` (tolera vallas markdown).
- Proveedores: **Anthropic** (`/v1/messages`) y **Gemini**
  (`generateContent`). Sin proveedor configurado no se hace NINGUNA llamada
  externa (regla del proyecto).
- `php/lotto_ai.php` ahora delega en este cliente: si se configura un
  proveedor, la generación directa de actividades Lotto queda operativa
  (siempre validada por `lotto_validator`).

## 3. API (`php/diagnostico_api.php`, prefijo `diagnostico_`)

Requiere sesión docente (o superadmin).

| Acción | Método | Descripción |
|---|---|---|
| `diagnostico_perfiles` | GET | Tres perfiles con estudiantes y métricas + desafíos débiles + `ia_disponible`. |
| `diagnostico_sugerir` | POST + CSRF | Genera el sub-proyecto remedial. Con IA configurada: llama al proveedor, extrae el JSON y lo valida con `triviax_validate_project` antes de devolverlo (`modo: "ia"`). Sin IA: devuelve el prompt listo para el chatbot del docente (`modo: "asistente"`, patrón de la casa). Con throttle y `audit_log`. |

## 4. Panel de estadísticas (`estadisticas.php`)

Sección nueva «🧭 Diagnóstico pedagógico (TRIVIAX+)» sobre la tabla de
preguntas: tres tarjetas de perfil con estudiantes, porcentaje general y de
aplicación, y recomendación pedagógica. El botón «🤖 Sugerir actividades de
refuerzo» llama a `diagnostico_sugerir`: con IA descarga el
`refuerzo_<proyecto>.json` validado; sin IA muestra el prompt con botón de
copiado. El JSON se importa desde el Panel de Actividades.

## 5. Verificación

- `tests/diagnostico_test.php` — 22 aserciones puras (suite 13 suites / 0 FAIL).
- E2E HTTP contra Apache local (11/11 OK): sembrado de tres estudiantes con
  perfiles diseñados → clasificación correcta (crítica/inconsistencia/
  avanzado), sugerencia en modo asistente con prompt canónico, y seguridad
  (401 sin sesión, 405 mutación por GET).
- Verificación visual: sección renderizada en `estadisticas.php` con las tres
  tarjetas y el panel de prompt tras pulsar el botón.

## 6. Despliegue

1. Subir por FTPS: `api.php`, `php/diagnostico_engine.php`,
   `php/diagnostico_api.php`, `php/llm_client.php`, `php/lotto_ai.php`,
   `estadisticas.php`, `js/config.js`, `service-worker.js`, `index.html`,
   `tests/diagnostico_test.php` y docs.
2. Sin migración SQL (capa analítica sobre tablas existentes).
3. (Opcional) Configurar `LLM_PROVIDER`/`LLM_API_KEY` en el `triviax.env` del
   servidor para habilitar la generación directa.
