-- TRIVIAX 6.8 - Modo Tarea asíncrono (TRIVIAX+ v8.0, Épica 2).
-- Cada estudiante juega su propia instancia de la sesión sin turnos en vivo,
-- con fecha límite opcional. Ejecutar en phpMyAdmin sobre la base triviax.
-- Nota: sintaxis compatible con MySQL 8 y MariaDB (sin IF NOT EXISTS en ALTER).

ALTER TABLE sesiones
    ADD COLUMN modalidad_sincronia ENUM('sincrono','asincrono_tarea') NOT NULL DEFAULT 'sincrono',
    ADD COLUMN fecha_limite_tarea DATETIME NULL;

-- En modo tarea varios jugadores comparten el mismo número de turno (cada uno
-- lleva su propio contador), así que la unicidad pasa a ser por jugador.
-- En modo síncrono el candado FOR UPDATE sobre la fila de `sesiones` ya
-- serializa los start_turn concurrentes, por lo que no se pierde control.
ALTER TABLE sesion_turnos
    DROP INDEX uq_sesion_turn,
    ADD UNIQUE KEY uq_sesion_jugador_turno (sesion_id, jugador_id, turn_number);

CREATE INDEX idx_sesiones_modalidad ON sesiones(modalidad_sincronia, estado);
