# Tests de TRIVIAX

Suite de pruebas versionada. Se divide en dos clases según necesiten o no MySQL.

## Pruebas unitarias (sin BD) — versionadas, ejecutables en cualquier entorno

Viven en este directorio (`tests/`). No requieren servidor ni base de datos:
se pueden correr desde la CLI directamente y son aptas para CI.

| Archivo | Cubre |
|---------|-------|
| `board_eval_test.php` | Evaluación autoritativa del tablero (#1): clamp de puntos y degradación de veredicto en opción múltiple/multimedia, con fallback seguro. |

Ejecutar todas:

```bash
php tests/run.php
```

O una sola:

```bash
php tests/board_eval_test.php
```

(En WAMP el binario suele estar en `C:\wamp64\bin\php\php8.2.0\php.exe`.)

## Pruebas de integración (requieren MySQL) — locales, en `scratch/`

`scratch/` está en `.gitignore` (artefactos locales). Estas pruebas levantan
la conexión real y necesitan la BD `triviax` operativa:

| Archivo | Cubre |
|---------|-------|
| `scratch/test_auditoria_seguridad.php` | Seguridad, rate limiting y reportes. |
| `scratch/test_fase_45.php` | Turnos y concurrencia. |
| `scratch/test_lotto.php` | Modalidad Lotto. |
| `scratch/test_study_answer.php` | Modalidad "Estudia y responde". |

Pendiente (deuda de #11): portar/duplicar las regresiones de los arreglos de
seguridad recientes (rate limiting, autorización de `guardar_resultados`,
aislamiento entre docentes) a pruebas de integración versionadas cuando se
disponga de un MySQL de pruebas reproducible (p. ej. contenedor dedicado).
