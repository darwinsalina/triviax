# TRIVIAX+ Marketplace educativo (Épica 3, v7.3)

Red de contenidos libres entre docentes: cada docente puede **publicar** sus
actividades de tablero y **clonar** las de otros a su propio catálogo, sin
romper el aislamiento de datos original.

## 1. Base de datos (migración `db/migraciones/6.9_marketplace.sql`)

Columnas nuevas en `proyectos`:

- `es_publico TINYINT(1)` — visible en el marketplace (default 0).
- `clonado_desde_id VARCHAR(100)` — linaje del clon (el id de `proyectos` es
  un slug VARCHAR, no un INT como decía el plan original).
- `descargas_count INT` — cuántas veces fue clonada.
- Índice `idx_proyectos_marketplace (es_publico, in_trash, nivel)` para el
  listado filtrado.

## 2. Motor (`php/marketplace_engine.php`)

- `triviax_marketplace_slugify()` / `triviax_marketplace_new_project_id()` —
  ids de clon con la convención del importador (`slug_YYMMDD_hex6`),
  puros y testeados en `tests/marketplace_test.php`.
- `triviax_marketplace_list($pdo, $filtros, $viewerId)` — actividades
  públicas con filtros indexados (`q` sobre título/autor/observaciones,
  `nivel`, orden por recientes o descargas), datos del docente y conteo de
  desafíos. Marca `es_mio` para el visor.
- `triviax_marketplace_publish()` — solo el dueño (o superadmin) puede
  publicar/retirar.
- `triviax_marketplace_clone()` — transaccional:
  1. `INSERT INTO proyectos … SELECT` con id nuevo, `docente_id` reasignado
     al clonador, `clonado_desde_id` al original, `es_publico = 0`.
  2. `INSERT INTO desafios … SELECT` duplicando los desafíos canónicos
     (claves, orden, `data_json`). El versionado evaluativo del original
     (`actividad_versiones`) queda intacto.
  3. Copia la carpeta pública del proyecto (`proyecto.json`, `fondo.jpg`,
     `preguntas.txt`, media) **excluyendo** `reportes/` y `stats.json`
     (datos privados). Sin carpeta origen no es error (proyecto 100 % BD).
  4. Incrementa `descargas_count` del original.
  Si algo falla se revierte la transacción y se borra la carpeta a medio copiar.

## 3. API (`php/marketplace_api.php`, prefijo `marketplace_`)

Requiere sesión docente (o superadmin); mutaciones con POST + CSRF
(token síncrono o `X-CSRF-Token`).

| Acción | Método | Descripción |
|---|---|---|
| `marketplace_list` | GET | Actividades públicas; filtros `q`, `nivel`, `orden` (`recientes`/`descargas`). |
| `marketplace_levels` | GET | Niveles disponibles para el filtro. |
| `marketplace_mine` | GET | Proyectos propios con estado de publicación y descargas. |
| `marketplace_publish` | POST + CSRF | Publica/retira un proyecto propio (`project_id`, `publico`). |
| `marketplace_clone` | POST + CSRF | Clona una actividad pública (`project_id`) → `nuevo_id`. Con throttle anti-abuso y registro en `audit_log`. |

## 4. Panel (`panel/marketplace.php`)

Página docente con dos pestañas (enlazada desde el dashboard):

- **Explorar** — tarjetas con nivel, docente, número de desafíos y clones;
  búsqueda con debounce, filtro por nivel y orden. Botón «Clonar a mi
  catálogo» (deshabilitado en actividades propias).
- **Mis publicaciones** — proyectos propios con toggle publicar/retirar y
  contador de clones. Los clones se marcan con chip «clon».

## 5. Verificación

- `tests/marketplace_test.php` — 11 aserciones puras (suite 12 suites / 0 FAIL).
- E2E HTTP contra Apache local con login real (24/24 OK): publicar → listar →
  buscar → clonar (fila + desafíos + carpeta sin datos privados +
  `descargas_count`) → jugar el clon vía `action=get` → despublicar →
  seguridad (401 sin sesión, 405 mutación por GET, 404 clon de no publicado).
- Verificación visual en navegador: login docente, listado, clonación desde
  la UI y pestaña «Mis publicaciones» con el clon en estado privado.

## 6. Despliegue

1. Subir por FTPS: `api.php`, `php/marketplace_engine.php`,
   `php/marketplace_api.php`, `panel/marketplace.php`, `panel/dashboard.php`,
   `js/config.js`, `service-worker.js`, `index.html`,
   `tests/marketplace_test.php` y docs.
2. Ejecutar `db/migraciones/6.9_marketplace.sql` en phpMyAdmin de producción.
