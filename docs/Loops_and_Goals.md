# Loops y Goals en Claude Code — Viabilidad e Implementación para TRIVIAX

> **Contexto:** Análisis basado en el video "El creador de Claude Code dejó de promptear (ahora corre loops)" (Benjamín Cordero, YouTube) y documentación técnica de los comandos `/goal` y `/loop`, aplicado al estado actual de TRIVIAX v6.1.1.

---

## 1. Qué son estos comandos y por qué cambian la forma de trabajar

Desde la versión 2.1.139 de Claude Code (12 de mayo de 2026), existen dos comandos nuevos que transforman el modelo de trabajo con IA: en vez de escribir prompts uno por uno y esperar resultados, se define **qué se quiere lograr** y se deja que Claude itere solo hasta conseguirlo.

### /goal — Objetivo con condición de cierre

```
/goal <condición verificable de finalización>
```

Define un estado final concreto y medible. Claude trabaja en múltiples turnos hasta cumplirlo. La clave técnica es que usa **dos modelos**: uno ejecuta la tarea y otro (Haiku) evalúa de forma independiente si el objetivo fue alcanzado. Esto evita que el agente se "autoconvenza" de haber terminado cuando no es así.

**Regla fundamental:** la condición debe ser verificable, no interpretable.

- ❌ Malo: "mejorá el sistema de preguntas"
- ✅ Bueno: "el parser de `preguntas.txt` acepta archivos de hasta 200 preguntas sin errores de validación y el test `challenge_validator_test.php` pasa al 100%"

### /loop — Repetición programada en sesión

```
/loop [intervalo] [until: condición]
```

Repite una tarea cada cierto tiempo mientras la sesión esté activa. Tiene techo de 7 días y máximo 50 tareas por sesión. Si cerrás la terminal, el loop desaparece.

```
/loop 30m until: error_log vacío
/loop 2h             ← sin condición de cierre: corre hasta que cerrés sesión
```

### /schedule (Routines) — La variante persistente

Para tareas que deben correr **sin que la máquina esté encendida**, existe `/schedule` o las Routines de Claude Code. Estas corren en la infraestructura de Anthropic y persisten indefinidamente hasta que las desactivás vos.

---

## 2. Tabla comparativa: ¿cuándo usar cada uno?

| Herramienta | Para qué sirve | Vive en | Termina cuando |
|---|---|---|---|
| `/goal` | Tarea larga con objetivo concreto. Itera hasta cumplirlo. | Sesión activa | El evaluador confirma la condición |
| `/loop` | Repetir un chequeo o tarea cada X tiempo en tu sesión | Sesión activa | Cerrás sesión o pasan 7 días |
| `/schedule` / Routines | Automatización recurrente sin máquina encendida | Infraestructura Anthropic | Vos lo desactivás |

---

## 3. ¿Es viable para TRIVIAX? Sí — y con casos concretos

TRIVIAX tiene 131 archivos, 2.293 nodos en el grafo de conocimiento, múltiples motores de actividad (Lotto, Jigsaw, Etiquetado, Estudio, Tablero), un sistema de testing propio en `tests/` y un panel docente completo. Es exactamente el tipo de proyecto donde promtear uno por uno es el cuello de botella.

A continuación se describen los casos de uso ordenados por impacto y facilidad de implementación.

---

## 4. Casos de uso para TRIVIAX

### 4.1. Pasar la suite de tests completa sin intervención

El directorio `tests/` tiene: `board_eval_test.php`, `board_grade_test.php`, `challenge_validator_test.php`, `project_import_test.php` y `run.php`. Un goal para garantizar que ninguna mejora rompe lo que ya funciona:

```
/goal Ejecutá php tests/run.php y asegurate de que todos los tests pasen al 100%.
Si alguno falla, identificá la causa, hacé el fix mínimo necesario y volvé a correr
hasta que el output sea "All tests passed" sin warnings.
```

Esto reemplaza el ciclo manual de: correr → leer error → ir al archivo → corregir → volver a correr.

---

### 4.2. Revisar y limpiar el formato de preguntas de todos los proyectos

En `proyectos/` hay 13 proyectos activos. El parser de `preguntas.txt` es estricto: 3-4 opciones, exactamente una correcta, sin saltos de formato. Un goal para auditar todos los archivos:

```
/goal Revisá el archivo preguntas.txt de cada carpeta en proyectos/.
El objetivo está cumplido cuando: todos los archivos pasan la validación
del parser de php/triviax_core.php sin errores, y tenés un reporte
en docs/reports/auditoria_preguntas_FECHA.md con el resultado por proyecto.
```

---

### 4.3. Desarrollo de nueva actividad educativa con goal iterativo

Cuando agregás un nuevo tipo de actividad (por ejemplo, actividad de "Línea de Tiempo" o "Mapa Conceptual"), podés definir el goal completo desde el principio:

```
/goal Implementá una nueva actividad "timeline_order" para TRIVIAX.
Estará completa cuando: existan los archivos js/engines/timelineEngine.js,
js/activityRenderers/timelineRenderer.js y php/timeline_api.php con la misma
estructura que jigsaw (motor + renderer + api), el challenge_validator_test.php
la reconozca como tipo válido, y esté visible en el catálogo de actividades de index.html.
```

Claude iterará: crea archivos, revisa consistencia con el patrón existente (jigsaw, etiquetado, lotto), integra, verifica. Vos seguís el progreso sin intervenir paso a paso.

---

### 4.4. Sincronización y limpieza de stats.json

El sistema guarda estadísticas acumuladas en `stats.json`. Con el tiempo puede acumular datos inconsistentes entre proyectos archivados y activos.

```
/goal Auditá stats.json. El objetivo está cumplido cuando:
- no existan entradas de proyectos que ya no están en proyectos/
- todos los campos numéricos sean válidos (no null, no NaN)
- el resultado de php tools/audit_project_db_sync.php sea "OK" sin warnings
- exista un backup en docs/reports/stats_backup_FECHA.json antes de cualquier modificación
```

---

### 4.5. Loop de monitoreo durante desarrollo activo

Mientras trabajás en una sesión larga de desarrollo, un loop que vigila errores en el log del servidor:

```
/loop 15m "Revisá si hay errores PHP nuevos en el log de Apache desde hace 15 minutos.
Si encontrás alguno relacionado con triviax, reportalo con el archivo y línea."
```

O uno que verifica que el service worker esté actualizado con la versión actual:

```
/loop 1h "Compará APP_VERSION en js/config.js con la versión declarada en service-worker.js.
Si no coinciden, avisame."
```

---

### 4.6. Verificación de consistencia de versión (regla crítica del proyecto)

El AGENTS.md establece que `bump_version.php` debe ser la única forma de cambiar la versión. Un goal para verificar que todo esté alineado antes de un release:

```
/goal Verificá la consistencia de versión en TRIVIAX. El objetivo está cumplido cuando:
APP_VERSION en js/config.js, la versión en service-worker.js, y todos los
elementos data-app-version en index.html muestran el mismo número.
Si hay inconsistencias, aplicá php tools/bump_version.php --check y reportá.
No hacer cambios: solo auditoría.
```

---

### 4.7. Generación automática de nuevo proyecto de preguntas (con Routines)

Esta es la aplicación más poderosa para un docente. Con `/schedule` (Routines), podés tener una tarea recurrente que, por ejemplo, todos los lunes genera un borrador de preguntas nuevas para un proyecto existente basándose en el temario del nivel:

```
Routine semanal (lunes 7:00 AM):
"Revisá informatica_7mo/preguntas.txt. Identificá los temas con menos de
5 preguntas. Generá 3 preguntas nuevas para cada tema deficiente,
respetando el formato del parser. Guardá el borrador en
proyectos/informatica_7mo/preguntas_nuevas_SEMANA.txt para revisión docente."
```

Esta rutina corre en la infraestructura de Anthropic, sin que la máquina esté prendida, y te deja un borrador listo para revisión cada semana.

---

## 5. Nuevas actividades que se pueden descubrir e implementar con estos loops

La combinación `/goal` + conocimiento profundo del proyecto habilita explorar funcionalidades que hoy no existen en TRIVIAX pero son viables dado el stack actual:

### 5.1. Actividad: Sopa de Letras generada desde preguntas.txt
Las respuestas correctas de un banco de preguntas son vocabulario del tema. Un goal puede implementar un generador de sopa de letras usando esas palabras, con el mismo patrón de motor + renderer que ya tienen Jigsaw y Etiquetado.

### 5.2. Actividad: Flashcards de repaso
Usando el modo "Estudia y responde" como base (`study.php`), se puede implementar un modo de flashcards tipo Anki que priorice las preguntas que el estudiante contestó incorrectamente. La lógica de priorización ya existe en `js/questionBank.js`.

### 5.3. Actividad: Crucigrama educativo
Las preguntas en formato "¿Cuál es la unidad mínima de información?" + respuesta "bit" son naturalmente crucigrama. Un motor de crucigrama auto-generado desde el banco de preguntas del proyecto activo.

### 5.4. Actividad: Modo duelo 1v1 en tiempo real
Usando la infraestructura de Lotto (que ya tiene host + players via polling) se puede implementar un modo de duelo directo entre dos jugadores con el mismo banco de preguntas en simultáneo.

### 5.5. Auditoría pedagógica automática
Una Routine semanal que analice `stats.json` y genere un reporte docente con: preguntas más falladas, jugadores con más dificultades, temas que necesitan refuerzo, y lo envíe por email usando la funcionalidad ya existente en `api.php → action=send_report`.

---

## 6. Cómo escribir buenos goals para TRIVIAX

Reglas prácticas basadas en la estructura real del proyecto:

**1. Usá los tests existentes como criterio de cierre.**
`php tests/run.php` es el evaluador objetivo. Cualquier goal de código debería terminar con ese comando pasando.

**2. Referenciá archivos reales del proyecto.**
En lugar de "agrega validación", escribí "agrega validación en `php/challenge_validator.php` consistente con el método `validate()` de `php/jigsaw_validator.php`".

**3. Pedí un reporte en `docs/reports/` como parte del goal.**
Así el evaluador tiene algo concreto que verificar además del código.

**4. Definí qué NO debe cambiar.**
AGENTS.md tiene una tabla de componentes críticos. Incluí en el goal: "no modificar `js/config.js`, `js/main.js` ni `js/board.js` salvo que sea estrictamente necesario para la tarea".

**5. Empezá con goals de solo lectura.**
Antes de un goal que modifica archivos, probá uno que solo audita y reporta. Cuando el comportamiento es predecible, escalás a modificaciones.

---

## 7. Riesgos y límites

| Riesgo | Mitigación |
|---|---|
| Goal vago → loop infinito | Definir condición medible y verificable |
| Cambios en archivos críticos | Explicitar en el goal qué archivos son inmutables |
| Costo de tokens en loops largos | Usar `/cost` para monitorear; empezar con scope chico |
| Loop se pierde al cerrar sesión | Para persistencia usar `/schedule` o Routines |
| Evaluador declara éxito prematuramente | Incluir múltiples condiciones de cierre (código + test + reporte) |

---

## 8. Plan de adopción sugerido

1. **Semana 1 — Familiarización:** correr un goal de solo lectura sobre los tests existentes. Observar el comportamiento sin modificaciones.
2. **Semana 2 — Primera iteración real:** usar `/goal` para la auditoría de formato de todos los `preguntas.txt`. Bajo riesgo, resultado concreto y verificable.
3. **Semana 3 — Desarrollo asistido:** implementar una nueva actividad pequeña con un goal que defina los archivos esperados, los tests y la integración en el catálogo.
4. **Semana 4 — Automatización recurrente:** configurar una Routine semanal para el reporte docente de estadísticas.

---

## 9. Conclusión

Los comandos `/goal` y `/loop` son viables y altamente recomendables para TRIVIAX. El proyecto tiene la madurez suficiente (tests, estructura modular, documentación en AGENTS.md) para beneficiarse directamente del modelo "definir condición → dejar iterar → verificar resultado". El cambio de paradigma no es técnico: es aprender a describir el estado final deseado de forma mecánicamente verificable, algo que como docente e informático ya hacés de forma natural al diseñar criterios de evaluación.

---

*Documento generado el 27/06/2026. Estado del proyecto referenciado: TRIVIAX v6.1.1.*

---

### Fuentes consultadas

- [Goals y Loops en Claude Code — Facundo Zupel](https://facundogrowth.com/blog/claude-code-goals-loops)
- [How to Use /goal and /loop in Claude Code — MindStudio](https://www.mindstudio.ai/blog/claude-code-goal-loop-commands-autonomous-tasks)
- [AI Loop Engineering: Build Autonomous Agents — Sabrina.dev](https://www.sabrina.dev/p/loop-engineering-claude-code-goal-routines)
- [Loop Engineering: How to Design Coding Agent Loops — ExplainX](https://explainx.ai/blog/loop-engineering-coding-agents-claude-code-guide-2026)
- [El creador de Claude Code dejó de promptear (ahora corre loops) — Benjamín Cordero](https://www.youtube.com/watch?v=HtKx75MwDBc)
