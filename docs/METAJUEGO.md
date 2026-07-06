# TRIVIAX+ Metajuego — XP, monedas, rachas y tienda (Épica 1, v7.1)

Sistema de progresión persistente para estudiantes con cuenta: cada respuesta
en una partida de tablero otorga **XP** y **monedas**, mantiene una **racha de
días** consecutivos con multiplicador y permite canjear monedas por cosméticos
en una **tienda** (avatares, fichas de tablero y bordes).

> Los jugadores anónimos no participan del metajuego: se requiere una cuenta
> (`usuarios`) vinculada al jugador de la sesión (`sesion_jugadores.usuario_id`).
> El metajuego **nunca** corta el flujo de juego: si la migración no está
> aplicada o la BD falla, el bloque `metagame` de las respuestas llega `null`.

## 1. Base de datos (migración `db/migraciones/6.7_metajuego.sql`)

| Tabla | Propósito |
|---|---|
| `estudiante_perfiles` | XP, monedas, racha y último acceso por usuario. FK a `usuarios` con `ON DELETE CASCADE`. |
| `item_tienda` | Catálogo de cosméticos: `tipo` ∈ {`avatar`, `ficha_tablero`, `borde`}, `costo`, `url_asset`. |
| `estudiante_inventario` | Ítems adquiridos por usuario, con marca `equipado`. |

La migración incluye un seed idempotente de 6 ítems (solo si la tienda está
vacía); los assets viven en `images/tienda/*.svg`.

## 2. Reglas de economía (`php/metagame_engine.php`)

Funciones puras (testeables sin BD, ver `tests/metagame_test.php`):

- **Nivel:** `nivel = floor(sqrt(xp / 100)) + 1` → 100 XP para nivel 2, 400
  para nivel 3, 900 para nivel 4…
- **Racha:** días consecutivos con actividad. Mismo día no la cambia; día
  siguiente la incrementa; un hueco la reinicia a 1.
- **Multiplicador de racha:** `x1.0` el día 1, `+0.1` por día extra, tope
  `x1.6` (racha ≥ 7).
- **XP por respuesta correcta:** `max(points_delta, 10) · multiplicador`.
- **XP por incorrecta/timeout:** 2 XP fijos de participación (sin multiplicador).
- **Monedas:** `floor(XP ganada / 5) + 2` si fue correcta; 0 en caso contrario.

Funciones con BD (todas fallan en silencio devolviendo `null`):

- `triviax_metagame_add_rewards($pdo, $usuarioId, $points, $resultado)` —
  registra la recompensa de un intento; se invoca dentro de la transacción de
  `submit_answer` y en el flujo legado `guardar_intento`.
- `triviax_metagame_profile($pdo, $usuarioId)` — perfil + inventario.
- `triviax_metagame_shop_items($pdo, $usuarioId)` — catálogo con posesión.
- `triviax_metagame_buy($pdo, $usuarioId, $itemId)` — compra atómica
  (`FOR UPDATE`, valida saldo y duplicados).
- `triviax_metagame_equip($pdo, $usuarioId, $itemId, $equipar)` — equipa
  desequipando otros del mismo tipo.

## 3. API (`php/metagame_api.php`, prefijo `metagame_`)

Identidad aceptada (en orden): sesión PHP autenticada, o la terna
`sesion_id + jugador_id + player_token` de una partida cuyo jugador esté
vinculado a una cuenta.

| Acción | Método | Descripción |
|---|---|---|
| `metagame_profile` | GET | Perfil: xp, monedas, racha, nivel, umbrales de XP e inventario. 401 sin identidad. |
| `metagame_shop` | GET | Catálogo público; marca `adquirido`/`equipado` si hay identidad. |
| `metagame_buy` | POST + CSRF | Compra un ítem (`item_id`). Errores: `ITEM_NO_EXISTE`, `YA_ADQUIRIDO`, `MONEDAS_INSUFICIENTES`. |
| `metagame_equip` | POST + CSRF | Equipa/desequipa (`item_id`, `equipar`). Error: `NO_ADQUIRIDO`. |

Además, `submit_answer` y `guardar_intento` devuelven un campo extra
`metagame` (o `null`) con:
`xp_ganada, monedas_ganadas, multiplicador, xp_total, monedas_total,
racha_dias, nivel, subio_nivel, xp_siguiente_nivel`.

## 4. Frontend (`js/services/metagameClient.js`)

`MetagameClient` (módulo ES, sin dependencias):

- `handleAnswerResponse(resp, playerName)` — enganchado en `js/main.js` al
  resolver `submitAnswer`: muestra un toast flotante con la XP/monedas ganadas
  y la racha, y una celebración a pantalla completa al subir de nivel.
- `fetchProfile(identity)` — consulta `metagame_profile`.
- Microanimaciones CSS inyectadas una sola vez (`#metagame-styles`), con
  soporte de `prefers-reduced-motion` y `aria-live` para lectores de pantalla.

## 5. Verificación

- `tests/metagame_test.php` — 31 aserciones de las funciones puras (suite
  incluida en `php tests/run.php`).
- Verificado E2E por HTTP contra Apache local: `unirse_sesion → start_turn →
  roll_dice → submit_answer` devuelve el bloque `metagame` y
  `metagame_profile` refleja la XP acumulada (15/15 OK).

## 6. Despliegue

1. Subir por FTPS: `api.php`, `php/metagame_engine.php`, `php/metagame_api.php`,
   `js/services/metagameClient.js`, `js/main.js`, `js/config.js`,
   `service-worker.js`, `index.html`, `images/tienda/*.svg`,
   `db/migraciones/6.7_metajuego.sql`, `tests/metagame_test.php` y docs.
2. Ejecutar `db/migraciones/6.7_metajuego.sql` en phpMyAdmin de producción
   (regla del proyecto: las migraciones SQL nunca se aplican por FTP).
