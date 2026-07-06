-- TRIVIAX 6.9 - Marketplace educativo (TRIVIAX+ v8.0, Épica 3).
-- Los docentes publican actividades de tablero y otros docentes las clonan
-- a su propio catálogo. Ejecutar en phpMyAdmin sobre la base triviax.
-- Nota: proyectos.id es VARCHAR(100) (slug), por eso clonado_desde_id también.

ALTER TABLE proyectos
    ADD COLUMN es_publico TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN clonado_desde_id VARCHAR(100) NULL,
    ADD COLUMN descargas_count INT NOT NULL DEFAULT 0;

CREATE INDEX idx_proyectos_marketplace ON proyectos(es_publico, in_trash, nivel);
