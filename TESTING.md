# Guía de Pruebas (TESTING) en TRIVIAX

Este documento detalla el entorno, los scripts y la metodología para ejecutar las pruebas automatizadas de integración, concurrencia y seguridad de **TRIVIAX v4.x**.

---

## 1. Requisitos Previos

- Entorno de desarrollo local activo (ej: **WAMP** o **XAMPP**).
- Base de datos MySQL con el esquema actualizado.
- Archivo de conexión configurado bajo la ruta esperada: `C:\wamp64\dbconn\triviax.env` y `dbkey_triviax.php`.

---

## 2. Scripts de Pruebas

En la carpeta `/scratch/` se encuentran los scripts de pruebas diseñados para ejecutarse exclusivamente desde la interfaz de línea de comandos (CLI) de PHP.

### 2.1. Pruebas de Integración y Concurrencia (`scratch/test_fase_45.php`)
Valida el ciclo completo de la máquina de estados de turnos, la integridad de los resultados, los límites de concurrencia y el comportamiento de la idempotencia.

* **Caso Cubierto:** `start_turn` ➔ `roll_dice` ➔ `submit_answer` ➔ `end_turn`.
* **Idempotencia:** Verifica que llamadas duplicadas de red con la misma `idempotency_key` devuelvan el valor cacheado (`cached: true`) sin procesar cambios adicionales.
* **Turnos Cruzados:** Asegura que un jugador no pueda iniciar turnos fuera de su secuencia física activa.

### 2.2. Pruebas de Seguridad y Auditoría (`scratch/test_auditoria_seguridad.php`)
Valida el control de nombres duplicados, el rate limit para accesos fallidos de login y el desacoplamiento físico de reportes.

* **Nombres Duplicados:** Registra dos estudiantes con el mismo nombre ("Alice") en la misma sesión y comprueba que reciban IDs distintos, hashes criptográficos únicos y `player_token` diferentes en la BD.
* **Rate Limiting:** Dispara 5 intentos fallidos consecutivos de login y comprueba que el 6.º sea denegado temporalmente por la tabla `rate_limits`.
* **Audit Logs:** Comprueba la correcta inserción de registros del tipo `login_failed` y `login_rate_limited` en la tabla `audit_log`.
* **Report Decoupling:** Verifica que al enviar un reporte asociado a un `sesion_id`, la API consuma de las tablas de MySQL y no guarde ningún archivo `.json` en el sistema de archivos local.

---

## 3. Ejecución de Pruebas desde la Consola

Dado que las pruebas requieren el entorno local de WAMP, debes ejecutarlas utilizando la ruta absoluta del binario de PHP configurado en tu servidor web (típicamente PHP 8.2+):

### Ejecutar Pruebas de Turnos y Concurrencia
```powershell
C:\wamp64\bin\php\php8.2.0\php.exe scratch/test_fase_45.php
```

### Ejecutar Pruebas de Seguridad, Rate Limit y Reportes
```powershell
C:\wamp64\bin\php\php8.2.0\php.exe scratch/test_auditoria_seguridad.php
```

---

## 4. Estructura de Aserciones y Salida

Ambos scripts implementan aserciones secuenciales y muestran un resumen estructurado al finalizar.

Ejemplo de salida exitosa:
```text
── 1. Nombres Duplicados al Unirse a Sesión ──────────────────────────────────
✅ HTTP 200 al unirse
✅ success true
...
══════════════════════════════════════════
RESULTADO: 31 pasaron, 0 fallaron
🎉 Todas las pruebas de seguridad y reportes pasaron.
══════════════════════════════════════════
```

> [!TIP]
> **Autocleanup:** Ambos scripts eliminan automáticamente todos los registros y archivos temporales creados durante la ejecución al finalizar (`CLEANUP` establecido en true), dejando el sistema en su estado limpio original.
