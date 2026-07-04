# Seguridad en TRIVIAX

Este documento detalla el modelo de seguridad, las políticas de protección y las buenas prácticas aplicadas en la versión 4.x de **TRIVIAX** para garantizar la integridad y confidencialidad del sistema en entornos educativos.

---

## 1. Autenticación y Gestión de Contraseñas

### 1.1. Hashing de Contraseñas (Bcrypt)
Todas las contraseñas de los usuarios (docentes, estudiantes y administradores) se procesan utilizando el algoritmo estándar de la industria **Bcrypt** mediante las funciones nativas de PHP:
- **Registro/Cambio de clave:** `password_hash($plainPassword, PASSWORD_DEFAULT)`
- **Verificación:** `password_verify($plainPassword, $hash)`

### 1.2. Deprecación de Cifrado Reversible
Históricamente, en versiones preliminares a la v4.4, se utilizaban las funciones `triviax_encrypt_password()` y `triviax_decrypt_password()` para almacenar contraseñas de manera reversible utilizando una clave basada en `tkey`.
- **Estado actual:** **DEPRECADO (v4.4+)**. La columna `password_encrypted` en la base de datos se mantiene únicamente por compatibilidad estructural legacy, pero la aplicación **nunca** lee ni escribe información en ella.
- Las contraseñas en texto plano nunca son almacenadas ni transmitidas de forma reversible.

---

## 2. Protección contra CSRF (Cross-Site Request Forgery)

TRIVIAX implementa dos mecanismos independientes de protección CSRF según el tipo de petición:

### 2.1. Formularios HTML Clásicos
Para peticiones `POST` tradicionales (ej: inicio de sesión, registro), se inyecta un campo oculto con un token criptográficamente seguro:
```html
<!-- Se genera mediante triviax_csrf_input() -->
<input type="hidden" name="csrf_token" value="hash_seguro_de_32_bytes">
```
La petición es validada en el backend llamando a `trixiax_verificar_csrf()`, la cual compara el valor enviado con el almacenado en la sesión activa utilizando una comparación de tiempo constante (`hash_equals`).

### 2.2. Endpoints AJAX / JSON (API)
Los endpoints del archivo `api.php` que realizan modificaciones en la base de datos o el sistema de archivos (ej: `send_report`, `guardar_intento`, `start_turn`, etc.) están protegidos mediante `trixiax_verify_csrf_json()`.

El token CSRF se puede suministrar de tres formas:
1. En el payload JSON del cuerpo de la petición (`csrf_token`).
2. En los parámetros tradicionales de `POST`.
3. A través de la cabecera HTTP `X-CSRF-Token` (opción utilizada por el cliente API en JavaScript).

---

## 3. Limitación de Tasa (Rate Limiting) y Auditoría

Para prevenir ataques de fuerza bruta sobre el inicio de sesión, el sistema implementa un bloqueo temporal a nivel de aplicación:

- **Límite:** Máximo de **5 intentos fallidos** de inicio de sesión.
- **Bloqueo:** Tras alcanzar el límite, el identificador compuesto por el `correo|IP` queda bloqueado para iniciar sesión durante **10 minutos**.
- **Base de datos:** El estado se persiste en la tabla `rate_limits`.

### Registro de Auditoría (Audit Log)
Cualquier evento relacionado con la seguridad de acceso se registra en la tabla `audit_log` con los siguientes tipos de acción:
- `login_success`: Inicio de sesión exitoso.
- `login_failed`: Intento fallido de inicio de sesión (almacena el correo y la IP).
- `login_rate_limited`: Intento de inicio de sesión realizado bajo estado de bloqueo activo.

---

## 4. Control de Concurrencia y Sesiones Multijugador

En el modo multijugador conectado a base de datos (v4.5+):
- **Tokens de Jugador:** Cada jugador recibe un `player_token` plano único de 64 caracteres (SHA-256 generado aleatoriamente) al unirse.
- **Seguridad en BD:** En la tabla `sesion_jugadores` solo se almacena el **hash SHA-256** del token. Para cada acción del juego, el navegador envía el token plano, el cual es validado calculando su hash en el backend, evitando así la exposición del token original en la base de datos.
- **Idempotencia:** Las respuestas a las actividades incluyen un identificador `idempotency_key`. Si una petición de respuesta se duplica debido a reintentos de red, el servidor detecta la clave existente en la tabla `intentos` y devuelve el resultado guardado con el flag `cached: true` sin duplicar la puntuación ni alterar el orden de los turnos.

---

## 5. Prevención de Path Traversal y Aislamiento de Archivos

Para los proyectos y reportes basados en el sistema de archivos local (retrocompatibilidad):
- La función `isSafePath($path)` (con alias `trixiax_is_safe_project_path`) valida rigurosamente que cualquier ruta absoluta resuelta esté confinada de manera estricta dentro del directorio `/proyectos/`.
- Previene accesos maliciosos utilizando secuencias de salto de directorio (ej: `../../`).
- **Seguridad extra:** El archivo `.htaccess` en la raíz web bloquea el acceso directo a extensiones sensibles como `.env`, `.sql`, `.bak`, `.log` y restringe el listado de directorios (`Options -Indexes`).

---

## 6. Desacoplamiento de Reportes de Partida

Para evitar la acumulación innecesaria de archivos JSON en el servidor y reducir la superficie de ataque:
- **Sesión local (Legacy):** Escribe el reporte como un archivo JSON físico en `/proyectos/[proyecto]/reportes/reporte_[fecha].json`.
- **Sesión conectada (BD):** **No se genera ningún archivo en el filesystem**. Cuando el docente solicita o envía el reporte, la API (`api.php?action=send_report`) consulta dinámicamente las tablas `sesiones`, `sesion_jugadores` e `intentos` para generar el correo electrónico.

---

## 7. v7.0 — Códigos, identidad y acceso a actividades

### 7.1. Códigos compartibles (grupo, actividad, docente)
Todos los códigos que se comparten (inscripción a grupo de 6–8 caracteres, código de acceso a actividad de 8, código docente personal de 12) se generan con `random_int()` sobre un alfabeto sin caracteres confusos (`O`, `0`, `I`, `1`, `L`) y **se almacenan únicamente como hash Bcrypt** (`triviax_code_hash`/`triviax_code_verify`, insensibles a mayúsculas). El texto plano viaja UNA sola vez en la respuesta que lo genera; si se pierde, se regenera (el anterior queda invalidado). Los intentos de código de acceso a actividad tienen rate limit propio (scope `act_code`, 10/10min por IP+actividad).

### 7.2. Niveles de identidad
`anonimo` → `login` → `email_verificado` → `validado_docente` (asociado a un grupo y confirmado por el docente en `panel/grupos.php`). Las actividades restringidas pueden exigir cualquier nivel; la verificación es **siempre server-side** (`triviax_can_start_activity`): el frontend oculta actividades pero nunca es la única barrera, y la API rechaza con 403 aunque se conozca la URL.

### 7.3. Privacidad de estudiantes (mínima recolección)
No se solicita ni almacena edad. El registro usa solo: nombre, apellido, email, contraseña y código de grupo opcional. El alias de aula identifica dentro del grupo; el login global sigue siendo por email.

### 7.4. Auditoría v7.0
Se registran en `audit_log`: creación/edición de grupos, importaciones, validación/rechazo de estudiantes, regeneración de códigos (grupo y docente), cambios de política de acceso (visibilidad/plazos/evaluación), creación de versiones evaluativas y backfills.
