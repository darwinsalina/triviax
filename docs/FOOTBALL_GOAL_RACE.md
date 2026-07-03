# TRIVIAX Futbol - Camino al Gol

`football_goal_race` es una modalidad de tablero para dos lados: azul y rojo. Ambos parten del centro de una cancha y avanzan hacia el arco contrario resolviendo preguntas.

## Estado actual

- Pantalla jugable: `football.php`.
- API: `api.php?action=football_*`, delegada a `php/football_api.php`.
- Motor de reglas: `php/football_engine.php`.
- Tablero demo: `images/cancha.png` con overlay responsive.
- Fixture demo: `docs/fixtures/football_goal_race_demo.json`.
- Pruebas: `tests/football_goal_race_test.php` y wrapper `scratch/test_football_goal_race.php`.
- Migracion preparada: `db/migraciones/6.5_football_goal_race.sql`.

La v1 usa sesion PHP como almacenamiento autoritativo para jugar sin aplicar migraciones. La migracion SQL deja lista la persistencia formal con tableros, sesiones, eventos e integrantes.

## Reglas

- `blue` avanza hacia el arco izquierdo.
- `red` avanza hacia el arco derecho.
- Cada lado tiene posiciones `0..30`.
- Llegar o superar `30` activa `final_shot`.
- Si el tiro final acierta, la partida termina y gana ese lado.
- Si falla, vuelve a posicion `28` y pasa el turno.

Casillas especiales:

| Posicion | Tipo | Efecto si acierta | Efecto si falla |
|---|---|---|---|
| 7 | Pared | +2 | 0 |
| 14 | Pared | +2 | 0 |
| 15 | VAR | +3 | -3 y -5 puntos |
| 19 | Pase largo | +3 | -1 |
| 24 | Tiro libre | +2 | 0 |

## API

- `football_board` `GET`: devuelve el tablero.
- `football_demo` `GET`: devuelve fixture demo y tablero.
- `football_start` `POST`: crea partida en sesion PHP.
- `football_state` `GET`: devuelve estado publico.
- `football_roll` `POST`: resuelve el dado en servidor y asigna pregunta normal.
- `football_answer` `POST`: procesa respuesta pendiente normal, especial o final.

El cliente nunca recibe la respuesta correcta. La respuesta se compara en servidor contra la pregunta guardada en `pendingAction`.

## Accesibilidad y responsive

El tablero no depende solo de color: las casillas especiales muestran texto abreviado (`PAR`, `VAR`, `PAS`, `TIR`) y `title`. Las fichas usan color y letra (`A`, `R`). El movimiento respeta `prefers-reduced-motion`.

