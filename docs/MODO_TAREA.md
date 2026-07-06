# TRIVIAX+ Modo Tarea y Compañeros Fantasma (Épica 2, v7.2)

Desacopla la sesión de tablero del tiempo real: el docente puede publicar una
actividad como **tarea asíncrona** (cada estudiante juega su propia instancia,
cuando quiera, antes de una fecha límite) y el estudiante que juega solo puede
sumar **compañeros fantasma (bots)** que mantienen la tensión competitiva.

## 1. Base de datos (migración `db/migraciones/6.8_modo_tarea.sql`)

- `sesiones.modalidad_sincronia` — `ENUM('sincrono','asincrono_tarea')`,
  default `sincrono` (todo lo previo sigue idéntico).
- `sesiones.fecha_limite_tarea` — `DATETIME NULL`; vencida, nadie puede
  unirse (`403 task_expired`) ni iniciar turnos (`409 TASK_EXPIRED`).
- `sesion_turnos`: la unicidad `(sesion_id, turn_number)` pasa a ser
  `(sesion_id, jugador_id, turn_number)` porque en modo tarea cada jugador
  lleva su propio contador de turnos. En modo síncrono el `FOR UPDATE` sobre
  la fila de `sesiones` ya serializa los `start_turn` concurrentes.

## 2. Backend (`php/session_mode.php` + `api.php`)

Helpers puros (ver `tests/session_mode_test.php`):

- `triviax_sesion_es_asincrona($sesion)` — detecta el modo con tolerancia a
  la ausencia de columnas (pre-migración → siempre síncrono).
- `triviax_sesion_tarea_vencida($sesion, $ahora)` — vencimiento inyectable.

Comportamiento de los endpoints en modo `asincrono_tarea`:

| Endpoint | Cambio |
|---|---|
| `unirse_sesion` | Rechaza con 403/`task_expired` si venció; la respuesta incluye `modalidad_sincronia` y `fecha_limite_tarea`. |
| `start_turn` | **Sin bloqueo por turno global**: no exige `current_turn_player_id` ni lo actualiza; el `turn_number` es por jugador (`COUNT` de sus turnos + 1). Rechaza con `TASK_EXPIRED` si venció. |
| `end_turn` | Sin rotación: `next_player_id` es el mismo jugador. |
| `session_state` | Expone `modalidad_sincronia` y `fecha_limite_tarea`. |
| `project_accuracy` (nuevo, GET) | Precisión histórica global de la actividad (`SUM(correct)/SUM(shown)` de `stats_desafios`, mínimo 10 muestras) para calibrar bots. No expone detalle por desafío. |

## 3. Panel docente (`panel/sesion_nueva.php`)

Selector de **Modalidad**: «🎲 En vivo» (clásico) o «📝 Tarea». En modo tarea
se habilitan la **fecha límite** (opcional, debe ser futura) y un máximo de
**hasta 60 estudiantes** (en vivo sigue siendo 2–8). Si la migración 6.8 no
está aplicada, el formulario oculta la opción y funciona como siempre.

## 4. Compañeros fantasma (`js/engines/botEngine.js`)

Máquina de estados finitos por bot (`idle → rolling → answering → resolved`):

- `BotEngine.forProject(project)` consulta `project_accuracy` y calibra la
  probabilidad de acierto de los bots con la tasa real de la actividad
  (fallback 0.6 sin datos; acotada a [0.05, 0.95]).
- `playTurn(bot, hooks)` simula tiempos humanos (dado ~0.7–1.3 s, respuesta
  1.2–3 s) y sortea el veredicto.
- Los bots son **solo locales**: no se inscriben en la sesión de BD, no
  envían intentos ni consumen desafíos de la batería del jugador.

Integración (`js/main.js` + `index.html`):

- Nueva pestaña «1 Jugador» y control «Compañeros fantasma 🤖» (1–3 bots) en
  la configuración de partida.
- Los bots se agregan al final de la lista de jugadores (no alteran los
  índices de los humanos en la sesión de BD) y `runBotTurnsIfAny()` encadena
  sus turnos automáticamente tras cada turno humano: avanzan, puntúan, sufren
  penalizaciones (`lose_turn`/`deduct_points`) y pueden ganar la partida.

## 5. Verificación

- `tests/session_mode_test.php` — 11 aserciones puras (suite 11 suites / 0 FAIL).
- E2E HTTP contra Apache local (19/19 OK): dos estudiantes juegan la misma
  tarea en paralelo sin `TURN_NOT_ACTIVE`, contadores de turno independientes,
  sin rotación en `end_turn`, `current_turn_player_id` global nulo, bloqueo
  total tras vencer la fecha límite y `project_accuracy` operativo.
- Verificación en navegador: partida real 1 humano + 2 bots — los bots juegan
  en cadena, aciertan/fallan, aplican penalizaciones y devuelven el dado.

## 6. Despliegue

1. Subir por FTPS: `api.php`, `php/session_mode.php`, `panel/sesion_nueva.php`,
   `js/main.js`, `js/engines/botEngine.js`, `js/engines/gameEngine.js`,
   `js/config.js`, `service-worker.js`, `index.html`,
   `tests/session_mode_test.php` y docs.
2. Ejecutar `db/migraciones/6.8_modo_tarea.sql` en phpMyAdmin de producción.
