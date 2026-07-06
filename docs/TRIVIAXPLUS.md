# Plan de Acción: Evolución de TRIVIAX a TRIVIAX+ v8.0

Este documento contiene el plan de ruta técnico, arquitectónico y metodológico para transformar la base de código actual de TRIVIAX (**v7.0.2**) en una suite educativa de alto rendimiento, atacando las brechas competitivas en gamificación, asincronía, comunidad, accesibilidad y analítica predictiva.

---

## Índice de Épicas de Desarrollo

1. [Épica 1: Arquitectura del Metajuego (XP, Logros y Recompensas)](#1-épica-1-arquitectura-del-metajuego-xp-logros-y-recompensas)
2. [Épica 2: Desacoplamiento Sincrónico (Modo Tarea y Bots)](#2-épica-2-desacoplamiento-sincrónico-modo-tarea-y-bots)
3. [Épica 3: Marketplace Educativo y Red de Contenidos Libres](#3-épica-3-marketplace-educativo-y-red-de-contenidos-libres)
4. [Épica 4: Inteligencia Predictiva y Diagnóstico Pedagógico](#4-épica-4-inteligencia-predictiva-y-diagnóstico-pedagógico)
5. [Épica 5: Accesibilidad Universal (Inclusión DUA Nativa)](#5-épica-5-accesibilidad-universal-inclusión-dua-nativa)

---

## 1. Épica 1: Arquitectura del Metajuego (XP, Logros y Recompensas)

> ✅ **Implementada en v7.1.0 (2026-07-06).** Migración `6.7_metajuego.sql`,
> motor `php/metagame_engine.php`, API `metagame_*`, hook de recompensas en
> `submit_answer`/`guardar_intento` y `js/services/metagameClient.js`.
> Documentación: `docs/METAJUEGO.md`. Nota: el helper se llamó
> `triviax_metagame_add_rewards()` (convención de prefijos del proyecto) en
> lugar de `metagame_engine.php::addRewards`.

### 1.1. Modificaciones en Base de Datos (MySQL)

Crear las tablas de persistencia para el sistema de economía y cosméticos:

```sql
CREATE TABLE estudiante_perfiles (
    usuario_id INT PRIMARY KEY,
    xp INT DEFAULT 0,
    monedas INT DEFAULT 0,
    racha_dias INT DEFAULT 0,
    ultimo_acceso DATE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE TABLE item_tienda (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100),
    tipo ENUM('avatar', 'ficha_tablero', 'borde'),
    costo INT,
    url_asset VARCHAR(255)
);

CREATE TABLE estudiante_inventario (
    usuario_id INT,
    item_id INT,
    equipado BOOLEAN DEFAULT FALSE,
    PRIMARY KEY (usuario_id, item_id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    FOREIGN KEY (item_id) REFERENCES item_tienda(id)
);
```

### 1.2. Instrucciones para el Agente de IA

- **Backend:** Modificar el endpoint de calificación central (`api.php` → `action=grade` / `submit_answer`). Tras procesar los aciertos, invocar un helper intermedio (`php/metagame_engine.php::addRewards(userId, points)`) que calcule el multiplicador de racha diaria y actualice los campos `xp` y `monedas`.
- **Frontend:** Crear un módulo `js/services/metagameClient.js` para consumir los datos del perfil y renderizar un componente dinámico flotante de subida de nivel utilizando microanimaciones CSS en `index.html`.

---

## 2. Épica 2: Desacoplamiento Sincrónico (Modo Tarea y Bots)

### 2.1. Modificaciones en Base de Datos (MySQL)

Expandir los estados e identificadores de sesión:

```sql
ALTER TABLE sesiones
    ADD COLUMN modalidad_sincronia ENUM('sincrono', 'asincrono_tarea') DEFAULT 'sincrono',
    ADD COLUMN fecha_limite_tarea DATETIME NULL;
```

### 2.2. Instrucciones para el Agente de IA

- **Backend:** Modificar `php/tablero_engine.php` y `php/api.php`. Si la sesión es `asincrono_tarea`, omitir el bloqueo por orden de turnos en tiempo real (`start_turn`, `end_turn`). Cada estudiante juega en una instancia clonada de manera aislada contra la base de datos.
- **Algoritmo de Simulación (Bots):** Implementar en JS (`js/engines/botEngine.js`) una máquina de estados finitos que simule los tiros de dado y las respuestas de "compañeros fantasmas" basados en la tasa de acierto promedio de la actividad histórica (`stats_desafios`). Esto mantendrá la tensión competitiva del juego de fútbol (*Camino al Gol*) en modo individual.

---

## 3. Épica 3: Marketplace Educativo y Red de Contenidos Libres

### 3.1. Modificaciones en Base de Datos (MySQL)

Permitir el intercambio seguro de proyectos entre profesores sin violar el aislamiento original de datos:

```sql
ALTER TABLE proyectos
    ADD COLUMN es_publico BOOLEAN DEFAULT FALSE,
    ADD COLUMN clonado_desde_id INT NULL,
    ADD COLUMN descargas_count INT DEFAULT 0;
```

### 3.2. Instrucciones para el Agente de IA

- **Backend:** Desarrollar `panel/marketplace.php` y `php/marketplace_api.php`. Diseñar un filtro SQL eficiente que liste actividades públicas indexadas por etiquetas, materias y niveles.
- **Mecanismo de Clonación:** La acción `clone_project` debe realizar un `INSERT INTO proyectos ... SELECT` duplicando las filas correspondientes de la tabla `desafios`, reasignando el `docente_id` al nuevo usuario autenticado y manteniendo intacto el versionado evaluativo original.

---

## 4. Épica 4: Inteligencia Predictiva y Diagnóstico Pedagógico

### 4.1. Modificaciones en Base de Datos (MySQL)

Añadir una capa analítica no relacional o vistas optimizadas sobre `stats_desafios` y `resultados` para procesar agrupaciones.

### 4.2. Instrucciones para el Agente de IA

- **Backend (`panel/estadisticas.php`):** Extender el motor de consultas estructuradas. Implementar un algoritmo de agrupamiento K-means (o basado en umbrales estricto-pedagógicos en PHP puro) que analice las matrices de acierto-error.
- **Formato de Salida:** Generar un reporte automatizado que agrupe a los estudiantes en tres perfiles:
  1. *Comprensión Crítica* (bloqueados en conceptos núcleo).
  2. *Inconsistencia de Aplicación* (errores en desafíos complejos como código o drag & drop).
  3. *Dominio Avanzado*.
- **IA Generativa:** Integrar en el importador y validador de IA (`php/lotto_ai.php` / `php/project_validator.php`) un botón de "Sugerir Actividades de Refuerzo" que use la API de LLM configurada para armar un sub-proyecto remedial basado en las debilidades detectadas.

---

## 5. Épica 5: Accesibilidad Universal (Inclusión DUA Nativa)

### 5.1. Implementación en Frontend (Vanilla JS)

No requiere cambios estructurales en la base de datos, sino un rediseño de la capa de componentes en el cliente (`js/activityRenderers/`).

### 5.2. Instrucciones para el Agente de IA

- **Motor de Síntesis de Voz (TTS):** Crear un helper global `js/services/ttsService.js` que envuelva la Web Speech API (`window.speechSynthesis`).
- **Inyección en Renderizadores:** En cada tipo de desafío interactivo (opción múltiple, verdadero/falso, completar espacios), inyectar dinámicamente un botón flotante con el icono 🔊 junto al enunciado de la pregunta y las opciones disponibles.
- **Navegación por Teclado:** Asegurar que todos los flujos interactivos (incluyendo el ordenamiento de secuencias y la selección de celdas en crucigramas) cuenten con atributos `aria-live`, soporte completo de índices de tabulación (`tabindex`) e intercepción de eventos de flechas direccionales y la tecla Space/Enter para prescindir por completo del puntero del mouse.

---

## Reglas de Control de Calidad e Integración para la IA

1. **Consistencia de Versión:** Cada cambio sustancial en scripts clave o migraciones en `db/migraciones/` debe obligar a la ejecución de la herramienta de control de versiones interna `tools/bump_version.php` (verificar con `--check`).
2. **Protección Doble CSRF:** Ningún endpoint nuevo (`marketplace_api.php`, etc.) puede procesar mutaciones (POST/PUT) sin verificar la existencia del token síncrono o la cabecera HTTP `X-CSRF-Token`.
3. **No Regresión:** Tras implementar cualquiera de las épicas anteriores, se debe verificar la suite completa ejecutando localmente el backend de testing automatizado mediante `tests/run.php`.
