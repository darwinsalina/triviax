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
