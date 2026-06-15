-- ============================================================
-- TRIVIAX v6.0 — Migración: actividades con imágenes
-- Modalidades "fuera del tablero": Puzle (jigsaw) y Etiquetar (etiquetar).
-- Las imágenes viven en filesystem (media/<modalidad>/<slug>/); aquí solo
-- se guardan metadatos, opciones, etiquetas y sesiones de juego.
-- Idempotente (CREATE TABLE IF NOT EXISTS).
-- Aplicar en phpMyAdmin o vía CLI sobre la base `triviax`, igual que las
-- tablas study_* y lotto_*.
-- Fecha: 2026-06-15
-- ============================================================

SET NAMES utf8mb4;

-- ════════════════════ PUZLE (jigsaw) ════════════════════

-- 1) Proyectos de puzle -------------------------------------------------
CREATE TABLE IF NOT EXISTS jigsaw_projects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  docente_id INT UNSIGNED NOT NULL,
  slug VARCHAR(150) NOT NULL,
  titulo VARCHAR(200) NOT NULL,
  descripcion TEXT NULL,
  image_path VARCHAR(255) NOT NULL,          -- carpeta relativa: media/jigsaw/<slug>
  image_w SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  image_h SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  difficulty_mode ENUM('free','fixed') NOT NULL DEFAULT 'free',
  fixed_grid TINYINT UNSIGNED NOT NULL DEFAULT 4,
  piece_shape ENUM('classic','jigsaw') NOT NULL DEFAULT 'classic',
  play_mode ENUM('tray','scatter','choose') NOT NULL DEFAULT 'tray',
  show_hint TINYINT(1) NOT NULL DEFAULT 0,
  hint_opacity TINYINT UNSIGNED NOT NULL DEFAULT 60,
  estado ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_jigsaw_slug (slug),
  KEY idx_jigsaw_docente (docente_id),
  KEY idx_jigsaw_estado (estado),
  CONSTRAINT fk_jigsaw_docente
    FOREIGN KEY (docente_id) REFERENCES usuarios(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Sesiones de juego de puzle ----------------------------------------
CREATE TABLE IF NOT EXISTS jigsaw_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NULL,
  player_token_hash CHAR(64) NULL,
  estado ENUM('active','finished','cancelled') NOT NULL DEFAULT 'active',
  grid TINYINT UNSIGNED NULL,
  mode ENUM('tray','scatter') NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  time_ms INT UNSIGNED NULL,
  moves SMALLINT UNSIGNED NULL,
  summary_json JSON NULL,
  KEY idx_jigsaw_session_project (project_id),
  KEY idx_jigsaw_session_user (usuario_id),
  KEY idx_jigsaw_session_estado (estado),
  KEY idx_jigsaw_session_token (player_token_hash),
  CONSTRAINT fk_jigsaw_session_project
    FOREIGN KEY (project_id) REFERENCES jigsaw_projects(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_jigsaw_session_user
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ════════════════════ ETIQUETAR (etiquetar) ════════════════════

-- 3) Proyectos de etiquetado -------------------------------------------
CREATE TABLE IF NOT EXISTS etiquetar_projects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  docente_id INT UNSIGNED NOT NULL,
  slug VARCHAR(150) NOT NULL,
  titulo VARCHAR(200) NOT NULL,
  descripcion TEXT NULL,
  image_path VARCHAR(255) NOT NULL,          -- carpeta relativa: media/etiquetar/<slug>
  image_w SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  image_h SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  box_x DECIMAL(6,5) NOT NULL DEFAULT 0.62000,  -- posición caja de etiquetas (0..1)
  box_y DECIMAL(6,5) NOT NULL DEFAULT 0.04000,
  estado ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_etiquetar_slug (slug),
  KEY idx_etiquetar_docente (docente_id),
  KEY idx_etiquetar_estado (estado),
  CONSTRAINT fk_etiquetar_docente
    FOREIGN KEY (docente_id) REFERENCES usuarios(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Etiquetas (coordenadas normalizadas 0..1) -------------------------
CREATE TABLE IF NOT EXISTS etiquetar_labels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  text VARCHAR(200) NOT NULL,
  ax DECIMAL(6,5) NOT NULL,   -- punto ancla x
  ay DECIMAL(6,5) NOT NULL,   -- punto ancla y
  lx DECIMAL(6,5) NOT NULL,   -- casilla/etiqueta x (JSON: bx)
  ly DECIMAL(6,5) NOT NULL,   -- casilla/etiqueta y (JSON: by)
  KEY idx_etiquetar_label_project (project_id, orden),
  CONSTRAINT fk_etiquetar_label_project
    FOREIGN KEY (project_id) REFERENCES etiquetar_projects(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5) Sesiones de juego de etiquetado -----------------------------------
CREATE TABLE IF NOT EXISTS etiquetar_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NULL,
  player_token_hash CHAR(64) NULL,
  estado ENUM('active','finished','cancelled') NOT NULL DEFAULT 'active',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  time_ms INT UNSIGNED NULL,
  correct SMALLINT UNSIGNED NULL,
  total SMALLINT UNSIGNED NULL,
  summary_json JSON NULL,
  KEY idx_etiquetar_session_project (project_id),
  KEY idx_etiquetar_session_user (usuario_id),
  KEY idx_etiquetar_session_estado (estado),
  KEY idx_etiquetar_session_token (player_token_hash),
  CONSTRAINT fk_etiquetar_session_project
    FOREIGN KEY (project_id) REFERENCES etiquetar_projects(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_etiquetar_session_user
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
