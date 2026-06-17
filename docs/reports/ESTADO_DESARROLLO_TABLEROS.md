# Estado de desarrollo — Tableros personalizados (v6.1)

> Última actualización: 2026-06-16. Punto de continuación para la funcionalidad de
> tableros personalizados vinculados a la actividad.

## Objetivo de la funcionalidad

Que el docente pueda crear tableros personalizados (con imagen propia) y **fijarlos a
una actividad**. Si una actividad tiene un tablero fijado, **solo se juega en ese
tablero** (los jugadores no pueden elegir otro). Los tableros personalizados se
juegan "pelados": la imagen ya trae las casillas, números y recorrido pintados, así
que el motor **no dibuja nada encima**; las fichas se mueven **saltando casilla a
casilla** por las coordenadas guardadas.

## Qué YA funciona (verificado)

1. **Crear tablero** — `panel/generador_tableros.php` (docente y superadmin). Editor
   visual 16:9; guarda un `.json` en `images/tableros/` con `bareBoard:true` y registra
   metadatos en la tabla `tableros`. El juego lee el `.json` (no la BD).
2. **Fijar tablero a la actividad** — `admin.php`: selector "Tablero del juego" en el
   formulario de creación y en el panel de edición. Al elegir un tablero personalizado
   se oculta la subida de fondo (el tablero ya trae su imagen).
3. **Persistencia del bloqueo** — sidecar `board.json` (`{"lockedId":"<id>"}`) en la
   carpeta del proyecto. Funciona tanto para proyectos `proyecto.json` como
   `preguntas.txt` porque `api.php` lo fusiona en ambas ramas
   (`triviax_merge_board_sidecar`).
4. **Bloqueo en el juego** — `js/main.js` `applyBoardLock()`: si la actividad fija un
   tablero, oculta el desplegable `#board-profile-group`, muestra la nota
   `#board-locked-note` y deja el tablero fijo. Si no, elección libre (estándar).
5. **Render pelado** — `js/engines/boardEngine.js`: en `bareBoard`, `renderSpaces()`
   NUNCA se dibuja (sin círculos ni números). Fix de `getSpaceCoords()`: las
   `customPositions` cubren también la casilla 0 (Salida).
6. **Movimiento de la ficha** — SIEMPRE animado, saltando casilla a casilla
   (`animateTokenMove`/`animateTokenPath`, ~220 ms por casilla). No salta al destino.
7. **Línea del recorrido (opcional)** — único elemento opcional: oculta por defecto en
   pelados; el switch "Mostrar la línea del recorrido sobre el tablero"
   (`#board-trace-checkbox` → `boardConfig.showPath`) la muestra. NO afecta a casillas
   ni números, que siguen ocultos.
8. **Proporción 16:9** — el editor del generador ya es 16:9 (`aspect-ratio:16/9` +
   `object-fit:cover`) y el servidor ajusta a 1920 px. Recomendado 1600×900.

## Archivos clave

| Archivo | Rol |
|---|---|
| `panel/generador_tableros.php` | Editor visual; escribe `images/tableros/<slug>.json` (+ tabla `tableros`) con `bareBoard:true` |
| `admin.php` | Selector de tablero (crear/editar); helpers `triviax_listar_tableros_disponibles()` y `triviax_guardar_tablero_actividad()` (sidecar) |
| `api.php` | `action=list_boards` (lista `.json`); `triviax_merge_board_sidecar()` inyecta `board.lockedId` al servir el proyecto |
| `js/main.js` | `applyBoardLock()`, carga de tableros antes del desplegable, `showPath` desde el switch, animación de la ficha |
| `js/engines/boardEngine.js` | `bareBoard` (sin casillas), `showPath` (línea opcional), animación paso a paso |
| `index.html` | Grupo del desplegable, nota de tablero fijado, switch de la línea |
| `images/tableros/<slug>.json` | Perfil del tablero (id, label, image, size, customPositions, `bareBoard`) |
| `proyectos/<actividad>/board.json` | Sidecar de bloqueo `{"lockedId":"<id>"}` |

## Estado de git

- Rama: `master`. Solo-local (sin remoto; el push se descartó por decisión del usuario).
- Commit `8b7d831` "v6.1: tableros personalizados vinculados a la actividad" — contiene
  el grueso de la funcionalidad (admin/api/generador/migración/guías/imágenes).
- **PENDIENTE DE COMMIT (árbol de trabajo):** el arreglo del movimiento paso a paso y el
  switch de la línea:
  - `js/engines/boardEngine.js`, `js/main.js`, `index.html`.

## Cómo probar (preview en :8123, servidor `php-dev`)

1. `api.php?action=list_boards` lista los tableros (incluye "solarsistem").
2. Crear/editar actividad en `admin.php` → elegir "Sistema Solar" → guarda
   `proyectos/<act>/board.json` con `lockedId`.
3. Abrir el juego con esa actividad: el desplegable de tablero no aparece; arranca en
   "Sistema Solar"; sin círculos/números; la ficha salta casilla a casilla al tirar.
4. Activar el switch de la línea → aparece la línea del recorrido (las casillas/números
   siguen sin verse).

## Pendientes / próximos pasos posibles

- [ ] **Commitear** los 3 archivos pendientes del movimiento paso a paso.
- [ ] **Prueba end-to-end por la UI real** del panel (crear actividad con tablero y
      jugarla con login real). Hasta ahora se verificó vía API + render aislado + render
      autenticado de `admin.php`, no el click-through completo.
- [ ] (Opcional) Casillas especiales en tableros personalizados: el generador hoy
      escribe `specialCells: []`.
- [ ] (Opcional) Validación dura server-side de la proporción 16:9 al subir (hoy es
      visual + guía, no rechazo).
- [ ] (Opcional) Que el switch de la línea recuerde su preferencia entre partidas.

## Notas / trampas conocidas

- `api.php` lee `action` SOLO de `$_GET` (POST necesita `?action=`). Ver
  `docs`/memorias del proyecto.
- El juego usa el `.json` del filesystem, NO la tabla `tableros` (esa es solo registro).
- `admin.php` incluye varios PHP juntos: los nombres de función deben ser únicos.
- El service worker cachea los JS: tras cambios, subir versión con
  `php tools/bump_version.php X.Y.Z` para invalidar cachés de clientes.
