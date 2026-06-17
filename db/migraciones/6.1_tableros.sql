-- ============================================================
-- TRIVIAX v6.0 — Migración: tableros personalizados
-- Registra en BD las características de cada tablero creado con el
-- generador visual (panel/generador_tableros.php), identificado por un
-- código único, para poder reutilizarlo en las actividades del docente.
-- El juego sigue leyendo el .json de images/tableros/ (esta tabla es
-- adicional, no lo reemplaza).
-- Idempotente (CREATE TABLE IF NOT EXISTS).
-- Fecha: 2026-06-15
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tableros (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(40) NOT NULL,                 -- código único del tablero
  slug VARCHAR(150) NOT NULL,                   -- id legible (nombre del .json)
  docente_id INT UNSIGNED NULL,                 -- creador (docente/superadmin)
  label VARCHAR(200) NOT NULL,
  descripcion TEXT NULL,
  image_path VARCHAR(255) NOT NULL,             -- ruta relativa: images/tableros/<archivo>.jpg
  size SMALLINT UNSIGNED NOT NULL DEFAULT 50,
  is_loop TINYINT(1) NOT NULL DEFAULT 0,
  default_victory_mode VARCHAR(20) NOT NULL DEFAULT 'race',
  allowed_victory_modes JSON NULL,
  cell_shape VARCHAR(20) NOT NULL DEFAULT 'circle',
  smooth_path TINYINT(1) NOT NULL DEFAULT 1,
  custom_positions JSON NOT NULL,
  special_cells JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tableros_codigo (codigo),
  UNIQUE KEY uq_tableros_slug (slug),
  KEY idx_tableros_docente (docente_id),
  CONSTRAINT fk_tableros_docente
    FOREIGN KEY (docente_id) REFERENCES usuarios(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
