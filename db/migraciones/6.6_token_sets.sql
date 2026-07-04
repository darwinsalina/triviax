-- TRIVIAX 6.6 - Colecciones de fichas / avatares para actividades
-- Ejecutar en phpMyAdmin sobre la base triviax.

CREATE TABLE IF NOT EXISTS token_sets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    activity_theme VARCHAR(190) NULL,
    token_type VARCHAR(80) NOT NULL DEFAULT 'personajes',
    visual_style VARCHAR(120) NOT NULL DEFAULT 'educativo',
    prompt_text MEDIUMTEXT NULL,
    negative_prompt_text TEXT NULL,
    cut_prompt_text TEXT NULL,
    source_image_path VARCHAR(255) NULL,
    status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    grid_cols TINYINT UNSIGNED NOT NULL DEFAULT 6,
    grid_rows TINYINT UNSIGNED NOT NULL DEFAULT 4,
    cell_size SMALLINT UNSIGNED NOT NULL DEFAULT 256,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_token_sets_teacher FOREIGN KEY (teacher_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_token_sets_teacher_status (teacher_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS token_assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_set_id INT NOT NULL,
    label VARCHAR(120) NOT NULL,
    category ENUM('male','female','neutral','object','animal','vehicle','ship','creature','symbol','other') NOT NULL DEFAULT 'other',
    row_index TINYINT UNSIGNED NOT NULL,
    col_index TINYINT UNSIGNED NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    thumb_path VARCHAR(255) NULL,
    width SMALLINT UNSIGNED NOT NULL DEFAULT 256,
    height SMALLINT UNSIGNED NOT NULL DEFAULT 256,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_token_assets_set FOREIGN KEY (token_set_id) REFERENCES token_sets(id) ON DELETE CASCADE,
    UNIQUE KEY uq_token_assets_set_order (token_set_id, sort_order),
    INDEX idx_token_assets_set_active (token_set_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE proyectos
    ADD COLUMN IF NOT EXISTS token_mode ENUM('standard_only','special_optional','special_required') NOT NULL DEFAULT 'standard_only',
    ADD COLUMN IF NOT EXISTS token_set_id INT NULL;

CREATE INDEX IF NOT EXISTS idx_proyectos_token_set ON proyectos(token_set_id);

ALTER TABLE sesion_jugadores
    ADD COLUMN IF NOT EXISTS selected_token_type ENUM('standard','special') NOT NULL DEFAULT 'standard',
    ADD COLUMN IF NOT EXISTS selected_token_id INT NULL,
    ADD COLUMN IF NOT EXISTS selected_standard_color VARCHAR(32) NULL;

CREATE INDEX IF NOT EXISTS idx_sesion_jugadores_token ON sesion_jugadores(selected_token_id);
