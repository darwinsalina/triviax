-- ============================================================
-- TRIVIAX v6.3 — Migración: actividades de palabras
-- Modalidades "fuera del tablero": Sopa de letras (ws) y
-- Crucigrama (cw), integradas desde experimental/.
-- El armado del puzzle (grilla, intersecciones, numeración) lo hace
-- el algoritmo local en el cliente; aquí se guarda el resultado ya
-- validado por el servidor como JSON, junto a los metadatos.
-- Idempotente (CREATE TABLE IF NOT EXISTS).
-- Aplicar en phpMyAdmin o vía CLI sobre la base `triviax`, igual que
-- las tablas jigsaw_* y etiquetar_* (6.0).
-- Fecha: 2026-07-02
-- ============================================================

SET NAMES utf8mb4;

-- ════════════════════ SOPA DE LETRAS (ws) ════════════════════

CREATE TABLE IF NOT EXISTS wordsearch_projects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  docente_id INT UNSIGNED NOT NULL,
  titulo VARCHAR(200) NOT NULL,
  rows_n TINYINT UNSIGNED NOT NULL DEFAULT 12,   -- "rows" es palabra reservada
  cols_n TINYINT UNSIGNED NOT NULL DEFAULT 12,
  word_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  source ENUM('manual','ia') NOT NULL DEFAULT 'manual',
  puzzle_json JSON NOT NULL,                     -- grilla + palabras colocadas + opciones
  estado ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ws_docente (docente_id),
  KEY idx_ws_estado (estado),
  CONSTRAINT fk_ws_docente
    FOREIGN KEY (docente_id) REFERENCES usuarios(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ════════════════════ CRUCIGRAMA (cw) ════════════════════

CREATE TABLE IF NOT EXISTS crossword_projects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  docente_id INT UNSIGNED NOT NULL,
  titulo VARCHAR(200) NOT NULL,
  rows_n TINYINT UNSIGNED NOT NULL DEFAULT 0,
  cols_n TINYINT UNSIGNED NOT NULL DEFAULT 0,
  word_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  source ENUM('manual','ia') NOT NULL DEFAULT 'manual',
  puzzle_json JSON NOT NULL,                     -- grilla + palabras + pistas across/down
  estado ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cw_docente (docente_id),
  KEY idx_cw_estado (estado),
  CONSTRAINT fk_cw_docente
    FOREIGN KEY (docente_id) REFERENCES usuarios(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
