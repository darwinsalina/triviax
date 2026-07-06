# API de TRIVIAX (Catálogo y Contratos)

Este documento detalla los endpoints de la API expuestos en `api.php`. Todos los endpoints modificadores (POST) requieren el envío del token CSRF (cabecera `X-CSRF-Token` o cuerpo).

La URL base de la API local es: `http://localhost/triviax/api.php`

---

## 1. Endpoints de Consulta (GET)

### 1.1. Listar Actividades (`action=list`)
Devuelve todas las actividades y proyectos válidos en el sistema de archivos local (`/proyectos/`).
* **Respuesta (JSON 200):**
  ```json
  {
    "success": true,
    "projects": [
      {
        "id": "informatica_7mo",
        "title": "Informática 7mo Grado",
        "author": "Docente Darwin",
        "nivel": "Media"
      }
    ],
    "csrf_token": "token_csrf_para_la_sesion"
  }
  ```

### 1.2. Obtener Actividad (`action=get`)
Retorna las preguntas, configuración del tablero y metadata de un proyecto específico.
* **Parámetros GET:**
  - `project` (string, obligatorio): Identificador o slug del proyecto (ej: `informatica_7mo`).
* **Respuesta (JSON 200):**
  ```json
  {
    "success": true,
    "metadata": {
      "title": "Informática 7mo Grado",
      "author": "Docente Darwin",
      "nivel": "Media"
    },
    "questions": [
      {
        "id": 1,
        "text": "¿Qué significa CPU?",
        "answers": [
          {"text": "Central Processing Unit", "correct": true},
          {"text": "Computer Personal Unit", "correct": false}
        ]
      }
    ],
    "board": {
      "type": "serpentine"
    }
  }
  ```

### 1.3. Estado de la Sesión (`action=session_state`)
Permite a los clientes de juego consultar el estado en tiempo real de una partida multijugador conectada a la BD.
* **Parámetros GET:**
  - `session_id` / `sesion_id` (int, obligatorio): ID de la sesión.
  - `jugador_id` (int, obligatorio): ID del jugador que realiza la consulta.
  - `player_token` (string, obligatorio): Token de acceso asignado al unirse.
* **Respuesta (JSON 200):**
  ```json
  {
    "ok": true,
    "success": true,
    "sesion": {
      "id": 12,
      "estado": "active",
      "current_turn_player_id": 3,
      "current_turn_number": 4,
      "updated_at": "2026-06-09 12:00:00"
    },
    "jugadores": [
      {
        "id": 3,
        "nombre_display": "Alice",
        "posicion": 4,
        "puntaje": 120,
        "estado": "activo"
      }
    ],
    "turno": {
      "id": 45,
      "sesion_id": 12,
      "jugador_id": 3,
      "turn_number": 4,
      "dice_value": 3,
      "board_position_before": 1,
      "board_position_after": 4,
      "challenge_key": "q12",
      "estado": "challenge_assigned"
    }
  }
  ```

---

## 2. Endpoints de Acción (POST)

### 2.1. Unirse a una Sesión (`action=unirse_sesion`)
Permite unirse a una sesión activa compartiendo el código de 6 caracteres.
* **Cuerpo (JSON):**
  ```json
  {
    "codigo": "ABC123",
    "jugadores": ["Alice", "Bob"]
  }
  ```
* **Respuesta (JSON 200):**
  ```json
  {
    "success": true,
    "sesion_id": 12,
    "jugador_ids": [3, 4],
    "jugadores": [
      {"jugador_id": 3, "nombre": "Alice", "player_token": "token_secreto_plano_1"},
      {"jugador_id": 4, "nombre": "Bob", "player_token": "token_secreto_plano_2"}
    ],
    "sesion_nombre": "Primer Torneo Darwin",
    "proyecto_id": "informatica_7mo"
  }
  ```

### 2.2. Iniciar Turno (`action=start_turn`)
El jugador activo marca el inicio de su turno.
* **Cuerpo (JSON):**
  ```json
  {
    "sesion_id": 12,
    "jugador_id": 3,
    "player_token": "token_secreto_plano_1"
  }
  ```
* **Respuesta (JSON 200):**
  ```json
  {
    "ok": true,
    "success": true,
    "data": {
      "turno_id": 45,
      "turn_number": 4
    }
  }
  ```

### 2.3. Lanzar Dado (`action=roll_dice`)
Registra el valor del dado obtenido en el cliente y opcionalmente asocia la casilla de destino.
* **Cuerpo (JSON):**
  ```json
  {
    "sesion_id": 12,
    "jugador_id": 3,
    "turno_id": 45,
    "player_token": "token_secreto_plano_1",
    "dice_value": 3,
    "challenge_key": "q12",
    "board_position_after": 4
  }
  ```
* **Respuesta (JSON 200):**
  ```json
  {
    "ok": true,
    "success": true,
    "data": {
      "turno_id": 45,
      "dice_value": 3,
      "estado": "challenge_assigned"
    }
  }
  ```

### 2.4. Enviar Respuesta (`action=submit_answer`)
Envía la resolución del desafío actual con control de duplicación por idempotencia.
* **Cuerpo (JSON):**
  ```json
  {
    "sesion_id": 12,
    "jugador_id": 3,
    "turno_id": 45,
    "player_token": "token_secreto_plano_1",
    "idempotency_key": "idemp_abc_123_xyz",
    "challenge_key": "q12",
    "resultado": "correct",
    "points_delta": 100,
    "board_position_after": 4,
    "time_ms": 4500,
    "nombre_jugador": "Alice",
    "prompt_text": "¿Qué significa CPU?",
    "challenge_type": "multiple_choice",
    "answer_payload": {"selected_option_index": 0}
  }
  ```
* **Respuesta (JSON 200):**
  ```json
  {
    "ok": true,
    "success": true,
    "data": {
      "turno_id": 45,
      "points_delta": 100
    }
  }
  ```
* **Respuesta Replay (JSON 200 - Idempotencia):**
  ```json
  {
    "ok": true,
    "success": true,
    "data": {
      "cached": true
    },
    "message": "Respuesta ya registrada."
  }
  ```

### 2.5. Finalizar Turno (`action=end_turn`)
Completa el turno actual y transfiere el control al siguiente jugador de la sesión.
* **Cuerpo (JSON):**
  ```json
  {
    "sesion_id": 12,
    "jugador_id": 3,
    "turno_id": 45,
    "player_token": "token_secreto_plano_1"
  }
  ```
* **Respuesta (JSON 200):**
  ```json
  {
    "ok": true,
    "success": true,
    "data": {
      "next_player_id": 4
    }
  }
  ```

### 2.6. Enviar Reporte (`action=send_report`)
Envía el reporte final de la partida al correo del docente. En modo conectado, los datos de los jugadores e intentos se consultan desde la BD en base al `sesion_id`.
* **Cuerpo (JSON):**
  ```json
  {
    "email": "docente@darwin.edu.uy",
    "project": "informatica_7mo",
    "title": "Informática 7mo Grado",
    "date": "06/09/2026",
    "time": "12:00",
    "sesion_id": 12,
    "players": [],
    "attempts": []
  }
  ```
* **Respuesta (JSON 200):**
  ```json
  {
    "success": true
  }
  ```

### 2.7. Guardar Intento Legacy (`action=guardar_intento`)
Guardado directo de respuestas individuales. Usado en modo híbrido.
* **Cuerpo (JSON):**
  ```json
  {
    "sesion_id": 12,
    "jugador_id": 3,
    "player_token": "token_secreto_plano_1",
    "challenge_key": "q12",
    "prompt_text": "¿Qué significa CPU?",
    "challenge_type": "multiple_choice",
    "resultado": "correct",
    "points_delta": 100,
    "time_ms": 4500,
    "orden_turno": 4,
    "nombre_jugador": "Alice",
    "idempotency_key": "idemp_abc_123_xyz"
  }
  ```
* **Respuesta (JSON 200):**
  ```json
  {
    "success": true
  }
  ```

### 2.8. Guardar Resultados Finales (`action=guardar_resultados`)
Al finalizar, además de `resultados`, se registra una fila por jugador en la tabla transversal `actividad_entregas` (v7.0) con política, versión y pertenencia si corresponden.
Cierra la sesión de la partida y registra las métricas acumuladas finales de todos los jugadores.
* **Cuerpo (JSON):**
  ```json
  {
    "sesion_id": 12,
    "resultados": [
      {
        "jugador_id": 3,
        "puntaje": 240,
        "correctas": 3,
        "incorrectas": 1,
        "posicion": 7
      }
    ]
  }
  ```
* **Respuesta (JSON 200):**
  ```json
  {
    "success": true
  }
  ```

---

## 3. v7.0 — Acceso, grupos e identidad

Desde v7.0 los endpoints de juego y catálogo aplican la **política de acceso transversal** (`actividad_acceso_politicas`):

* `action=list` y los `*_list_published` de cada modalidad solo muestran actividades **públicas y actualmente abiertas** (el docente dueño y el superadmin siguen viendo las suyas).
* `action=get`, `unirse_sesion`, `study_start`, `jigsaw_start`, `etiquetar_start`, `ws_get`, `cw_get` y `lotto_student_login` **rechazan con HTTP 403** cuando la política lo exige, con cuerpo `{"success":false,"error":"<mensaje>","motivo":"<código>"}`.
* `ws_submit` y `cw_submit` registran la entrega transversal en `actividad_entregas` al completar Sopa de letras o Crucigrama; revalidan la misma política de acceso y aceptan `codigo` en el cuerpo cuando la actividad lo requiere.
* Motivos posibles: `no_publicada`, `no_disponible_aun`, `plazo_finalizado`, `requiere_login`, `requiere_email_verificado`, `requiere_codigo`, `codigo_invalido`, `codigo_bloqueado`, `dominio_no_permitido`, `fuera_de_grupo`, `pendiente_validacion`, `bloqueado`, `max_intentos`.
* Si la actividad tiene código de acceso, se envía como parámetro `codigo` (GET en `action=get`/`ws_get`/`cw_get`; en el cuerpo JSON en los `*_start`; `codigo_actividad` en `unirse_sesion`).

### 3.1. Grupos (`grp_*`)

| Acción | Método | Quién | Descripción |
|---|---|---|---|
| `grp_list` | GET | docente | Grupos propios con conteos de estudiantes y pendientes. |
| `grp_create` / `grp_update` | POST | docente | Crear/editar grupo (`nombre`, `nivel`, `descripcion`, `activo`). |
| `grp_regen_code` | POST | docente | Regenera el código de inscripción (6–8 chars). **Se devuelve una única vez**; en BD queda solo el hash. |
| `grp_students` | GET | docente | Estudiantes del grupo. |
| `grp_student_add` | POST | docente | Alta manual (alias sugerido automáticamente). |
| `grp_student_estado` | POST | docente | `validado` / `rechazado` / `desactivado` / `pendiente`. Auditado. |
| `grp_import_preview` | POST (multipart o JSON) | docente | Previsualiza CSV: detecta columnas `nombre`/`apellido`/`email`, sugiere alias, marca duplicados. No escribe. |
| `grp_import_confirm` | POST | docente | Importa las filas confirmadas (máx. 500) y registra el resumen en `grupo_importaciones`. |
| `grp_export` | GET | docente | Descarga CSV de la lista del grupo. |
| `grp_join` | POST | estudiante | Unirse con código de inscripción; si su email fue importado, vincula la fila existente (estado `pendiente`). |
| `grp_mis_grupos` | GET | estudiante | Grupos a los que pertenece. |
| `grp_codigo_docente_regen` | POST | docente | Genera/rota el código docente personal de 12 caracteres (hash en `docente_perfiles`). |

### 3.2. Políticas de acceso (`acceso_*`)

| Acción | Método | Quién | Descripción |
|---|---|---|---|
| `acceso_get` | GET | docente dueño | Política de una actividad (`tipo`, `ref`) + versión congelada actual + si tiene entregas. Nunca expone hashes. |
| `acceso_save` | POST | docente dueño | Crea/actualiza la política: `visibilidad` (`publica`/`no_listada`/`restringida`), `estado_publicacion` (`borrador`/`programada`/`abierta`/`cerrada`/`desactivada`/`archivada`), `abre_at`/`cierra_at`, `requiere_login`/`requiere_email_verificado`/`requiere_validacion_docente`, `evaluativa`, `max_intentos`, `feedback_policy`, `dominios[]`, `grupos[]`, `estudiantes[]`, `generar_codigo`/`quitar_codigo`. Si queda evaluativa y abierta, congela una versión (snapshot). El código generado viaja una única vez. |
| `acceso_check` | GET | público | ¿Puede el usuario actual iniciar la actividad? Devuelve `puede_iniciar`, `motivo`, `mensaje`, `identidad` (`anonimo`/`login`/`email_verificado`/`validado_docente`). |
| `acceso_versiones` | GET | docente dueño | Historial de versiones congeladas. |
| `acceso_grupos_disponibles` | GET | docente | Grupos activos propios (para la UI de configuración). |
| `acceso_entregas` | GET | docente | Entregas transversales con filtros (`tipo`+`ref`, `grupo_id`, `evaluativa`, `version_id`, `desde`, `hasta`). Con `format=csv` descarga el archivo. |

Los tipos de actividad válidos son: `proyecto`, `study_deck`, `lotto_activity`, `crossword_project`, `wordsearch_project`, `jigsaw_project`, `etiquetar_project`. `actividad_ref` es el id (o slug, para `proyecto`) como string.

---

## 4. TRIVIAX Futbol (`football_*`)

Modalidad `football_goal_race`, primera version funcional en sesion PHP.

| Acción | Método | Descripción |
|---|---|---|
| `football_board` | GET | Devuelve el tablero demo con cancha, rutas azul/roja, casillas especiales y coordenadas porcentuales. |
| `football_demo` | GET | Devuelve el fixture demo completo (`docs/fixtures/football_goal_race_demo.json`). |
| `football_start` | POST | Crea una partida demo; devuelve `session_id`, `token`, `state` publico y `board`. |
| `football_state` | GET | Recupera estado publico de una partida usando `session_id` + `token`. |
| `football_roll` | POST | Resuelve el dado en servidor y asigna pregunta normal. |
| `football_answer` | POST | Procesa respuesta normal, especial o tiro final; acepta `idempotency_key`. |

El cliente no envia posiciones, puntajes ni resultado de dado. El servidor mantiene `pendingAction` y sanea la pregunta publica para no exponer `correct`.

---

## 5. Colecciones de fichas / avatares (`tokens_*`)

Modulo docente para crear colecciones de 24 fichas y usarlas en actividades.

| Accion | Metodo | Descripcion |
|---|---|---|
| `tokens_list` | GET | Lista colecciones del docente autenticado. |
| `tokens_active` | GET | Lista colecciones activas con fichas para asociar a actividades. |
| `tokens_get` | GET | Devuelve una coleccion propia y sus assets (`id`). |
| `tokens_public` | GET | Devuelve una coleccion activa/archivada para el juego (`id`). |
| `tokens_save` | POST | Crea o actualiza metadata y prompts de una coleccion. |
| `tokens_upload_source` | POST multipart | Sube imagen madre 6 x 4. |
| `tokens_slice` | POST | Corta la imagen madre en `ficha_01.png` ... `ficha_24.png`. |
| `tokens_assets_update` | POST | Actualiza etiquetas, categorias y estado activo de fichas. |
| `tokens_status` | POST | Cambia estado `draft`, `active` o `archived`. |

Las escrituras requieren sesion docente y CSRF. El corte es deterministico con GD: valida imagen real, proporcion 3:2, conserva alfa y guarda PNGs en `uploads/token_sets/{teacher_id}/{token_set_id}/tokens/`.

---

## 6. Metajuego TRIVIAX+ (`metagame_*`) — v7.1

Progresión persistente para estudiantes con cuenta: XP, monedas, racha diaria con multiplicador y tienda de cosméticos. Detalle completo en `docs/METAJUEGO.md`.

| Acción | Método | Descripción |
|---|---|---|
| `metagame_profile` | GET | Perfil del estudiante: xp, monedas, racha, nivel, umbrales e inventario. 401 sin identidad. |
| `metagame_shop` | GET | Catálogo público de la tienda; marca `adquirido`/`equipado` si hay identidad. |
| `metagame_buy` | POST + CSRF | Compra un ítem (`item_id`) descontando monedas de forma atómica. |
| `metagame_equip` | POST + CSRF | Equipa o desequipa un ítem adquirido (`item_id`, `equipar`). |

Identidad aceptada: sesión PHP autenticada, o la terna `sesion_id` + `jugador_id` + `player_token` de una partida cuyo jugador esté vinculado a una cuenta (`sesion_jugadores.usuario_id`).

Además, `submit_answer` y `guardar_intento` devuelven un campo `metagame` (o `null`) con `xp_ganada`, `monedas_ganadas`, `multiplicador`, `xp_total`, `monedas_total`, `racha_dias`, `nivel`, `subio_nivel` y `xp_siguiente_nivel`.

---

## 7. Modo Tarea asíncrono y bots (v7.2)

Detalle completo en `docs/MODO_TAREA.md`. Con `sesiones.modalidad_sincronia = 'asincrono_tarea'` (migración 6.8):

- `unirse_sesion` devuelve además `modalidad_sincronia` y `fecha_limite_tarea`, y rechaza con 403/`task_expired` si la tarea venció.
- `start_turn` no exige ni actualiza el turno global; `turn_number` es por jugador. Rechaza con 409/`TASK_EXPIRED` si la tarea venció.
- `end_turn` no rota el turno: `next_player_id` es el mismo jugador.
- `session_state` expone `modalidad_sincronia` y `fecha_limite_tarea` dentro de `sesion`.

| Acción | Método | Descripción |
|---|---|---|
| `project_accuracy` | GET | Precisión histórica global de una actividad (`accuracy` 0–1 o `null` con menos de 10 muestras, `muestras`). Calibra los compañeros fantasma (`js/engines/botEngine.js`). |

---

## 8. Marketplace educativo (`marketplace_*`) — v7.3

Red de contenidos libres entre docentes. Detalle en `docs/MARKETPLACE.md`. Requiere sesión docente; mutaciones con POST + CSRF.

| Acción | Método | Descripción |
|---|---|---|
| `marketplace_list` | GET | Actividades públicas con filtros `q`, `nivel`, `orden` (`recientes`/`descargas`). |
| `marketplace_levels` | GET | Niveles disponibles para el filtro. |
| `marketplace_mine` | GET | Proyectos propios con estado de publicación. |
| `marketplace_publish` | POST + CSRF | Publica/retira un proyecto propio (`project_id`, `publico`). |
| `marketplace_clone` | POST + CSRF | Clona una actividad pública: duplica proyecto + desafíos + carpeta pública (sin `reportes/` ni `stats.json`), reasigna docente, registra `clonado_desde_id` e incrementa `descargas_count`. |
