-- ============================================================
-- TRIVIAX v6.5 - Modalidad football_goal_race
-- TRIVIAX Futbol - Camino al Gol
-- Aditiva e idempotente. Aplicar sobre la base `triviax`.
-- Fecha: 2026-07-03
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS football_boards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  board_key VARCHAR(100) NOT NULL UNIQUE,
  mode VARCHAR(60) NOT NULL DEFAULT 'football_goal_race',
  label VARCHAR(180) NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  config_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_fb_config CHECK (JSON_VALID(config_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS football_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  activity_id VARCHAR(100) NULL,
  mode VARCHAR(60) NOT NULL DEFAULT 'football_goal_race',
  board_key VARCHAR(100) NOT NULL,
  status ENUM('playing','finished','cancelled') NOT NULL DEFAULT 'playing',
  current_side ENUM('blue','red') NOT NULL DEFAULT 'blue',
  state_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  winner_side ENUM('blue','red') NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  KEY idx_fs_status (status),
  KEY idx_fs_board (board_key),
  CONSTRAINT chk_fs_state CHECK (JSON_VALID(state_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS football_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  turn_number INT UNSIGNED NOT NULL,
  side ENUM('blue','red') NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  question_id VARCHAR(100) NULL,
  answer_is_correct TINYINT(1) NULL,
  dice_value TINYINT UNSIGNED NULL,
  from_position TINYINT UNSIGNED NULL,
  to_position TINYINT UNSIGNED NULL,
  points_delta SMALLINT DEFAULT 0,
  event_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fe_session (session_id),
  KEY idx_fe_turn (session_id, turn_number),
  CONSTRAINT fk_fe_session FOREIGN KEY (session_id)
    REFERENCES football_sessions(id) ON DELETE CASCADE,
  CONSTRAINT chk_fe_event CHECK (event_json IS NULL OR JSON_VALID(event_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS football_team_members (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  side ENUM('blue','red') NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  rotation_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
  turns_answered SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  correct_answers SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  incorrect_answers SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ftm_session_side (session_id, side),
  CONSTRAINT fk_ftm_session FOREIGN KEY (session_id)
    REFERENCES football_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

