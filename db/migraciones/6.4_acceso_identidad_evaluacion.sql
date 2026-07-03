-- ============================================================
-- TRIVIAX v7.0 — Migración 6.4: acceso, identidad y evaluación
--
-- Capa transversal de identidad, pertenencia, acceso y evaluación
-- para TODAS las modalidades (tablero, study, lotto, crucigrama,
-- sopa de letras, jigsaw, etiquetar) sin tocar sus tablas base.
--
-- Aditiva e idempotente:
--   · CREATE TABLE IF NOT EXISTS para tablas nuevas.
--   · Procedimiento temporal para ALTER TABLE condicionales
--     (MySQL 8 no soporta ADD COLUMN IF NOT EXISTS).
-- No renombra ni elimina nada existente.
--
-- Aplicar en phpMyAdmin o CLI sobre la base `triviax`.
-- Fecha: 2026-07-03
-- ============================================================

SET NAMES utf8mb4;

-- ════════════════════ 0. Perfil docente (código secundario) ════════════════════

CREATE TABLE IF NOT EXISTS docente_perfiles (
  usuario_id INT UNSIGNED PRIMARY KEY,
  codigo_docente_hash VARCHAR(255) NULL,
  codigo_docente_generado_at DATETIME NULL,
  codigo_docente_rotado_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_dp_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ════════════════════ 1. Instituciones ════════════════════

CREATE TABLE IF NOT EXISTS instituciones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(180) NOT NULL,
  slug VARCHAR(180) NOT NULL UNIQUE,
  created_by INT UNSIGNED NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inst_creador FOREIGN KEY (created_by)
    REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Dominio normalizado: sin '@', en minúsculas (ej. woodsideschool.edu.uy)
CREATE TABLE IF NOT EXISTS institucion_dominios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id INT UNSIGNED NOT NULL,
  dominio VARCHAR(255) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inst_dominio (institucion_id, dominio),
  CONSTRAINT fk_instdom_inst FOREIGN KEY (institucion_id)
    REFERENCES instituciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ════════════════════ 2. Grupos y estudiantes ════════════════════

CREATE TABLE IF NOT EXISTS grupos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  docente_id INT UNSIGNED NOT NULL,
  institucion_id INT UNSIGNED NULL,
  nivel VARCHAR(80) NULL,
  nombre VARCHAR(120) NOT NULL,
  descripcion TEXT NULL,
  codigo_inscripcion_hash VARCHAR(255) NULL,
  codigo_generado_at DATETIME NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_grupo_docente (docente_id, nivel, nombre),
  KEY idx_grupo_institucion (institucion_id),
  CONSTRAINT fk_grupo_docente FOREIGN KEY (docente_id)
    REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_grupo_institucion FOREIGN KEY (institucion_id)
    REFERENCES instituciones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- El alias identifica al estudiante DENTRO del grupo; el login global
-- sigue siendo por email. El email puede ser NULL (importado sin correo).
CREATE TABLE IF NOT EXISTS grupo_estudiantes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grupo_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NULL,
  email VARCHAR(255) NULL,
  nombre VARCHAR(100) NOT NULL,
  apellido VARCHAR(100) NOT NULL,
  alias VARCHAR(80) NOT NULL,
  estado ENUM('importado','pendiente','validado','rechazado','desactivado') NOT NULL DEFAULT 'importado',
  source ENUM('manual','csv','xlsx','self_join') NOT NULL DEFAULT 'manual',
  validated_by INT UNSIGNED NULL,
  validated_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ge_alias (grupo_id, alias),
  UNIQUE KEY uq_ge_email (grupo_id, email),
  KEY idx_ge_usuario (usuario_id),
  KEY idx_ge_email (email),
  CONSTRAINT fk_ge_grupo FOREIGN KEY (grupo_id)
    REFERENCES grupos(id) ON DELETE CASCADE,
  CONSTRAINT fk_ge_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_ge_validador FOREIGN KEY (validated_by)
    REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS grupo_importaciones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grupo_id INT UNSIGNED NOT NULL,
  docente_id INT UNSIGNED NOT NULL,
  filename VARCHAR(255) NULL,
  mime_type VARCHAR(120) NULL,
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
  error_rows INT UNSIGNED NOT NULL DEFAULT 0,
  errors_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_gi_grupo (grupo_id),
  CONSTRAINT fk_gi_grupo FOREIGN KEY (grupo_id)
    REFERENCES grupos(id) ON DELETE CASCADE,
  CONSTRAINT fk_gi_docente FOREIGN KEY (docente_id)
    REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT chk_gi_errors CHECK (errors_json IS NULL OR JSON_VALID(errors_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ════════════════════ 3. Políticas de acceso (capa lateral) ════════════════════

-- actividad_ref es VARCHAR porque proyectos.id es un slug textual;
-- el resto de las modalidades usa ID entero (se guarda como string).
CREATE TABLE IF NOT EXISTS actividad_acceso_politicas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actividad_tipo ENUM('proyecto','study_deck','lotto_activity','crossword_project',
                      'wordsearch_project','jigsaw_project','etiquetar_project') NOT NULL,
  actividad_ref VARCHAR(100) NOT NULL,
  docente_id INT UNSIGNED NULL,
  visibilidad ENUM('publica','no_listada','restringida') NOT NULL DEFAULT 'publica',
  estado_publicacion ENUM('borrador','programada','abierta','cerrada','desactivada','archivada') NOT NULL DEFAULT 'borrador',
  abre_at DATETIME NULL,
  cierra_at DATETIME NULL,
  requiere_login TINYINT(1) NOT NULL DEFAULT 0,
  requiere_email_verificado TINYINT(1) NOT NULL DEFAULT 0,
  requiere_validacion_docente TINYINT(1) NOT NULL DEFAULT 0,
  evaluativa TINYINT(1) NOT NULL DEFAULT 0,
  max_intentos SMALLINT UNSIGNED NULL,
  feedback_policy ENUM('inmediato','al_final','al_cierre','nunca') NOT NULL DEFAULT 'al_final',
  codigo_acceso_hash VARCHAR(255) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_politica_actividad (actividad_tipo, actividad_ref),
  KEY idx_pol_docente (docente_id),
  KEY idx_pol_visibilidad (visibilidad),
  KEY idx_pol_estado (estado_publicacion),
  KEY idx_pol_fechas (abre_at, cierra_at),
  CONSTRAINT fk_pol_docente FOREIGN KEY (docente_id)
    REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS actividad_acceso_dominios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  politica_id INT UNSIGNED NOT NULL,
  dominio VARCHAR(255) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_aad (politica_id, dominio),
  CONSTRAINT fk_aad_politica FOREIGN KEY (politica_id)
    REFERENCES actividad_acceso_politicas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- scope_type permite usar el mismo grupo como alcance general ('grupo'),
-- clase puntual ('clase', con fecha) o tarea ('tarea', con título).
CREATE TABLE IF NOT EXISTS actividad_acceso_grupos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  politica_id INT UNSIGNED NOT NULL,
  grupo_id INT UNSIGNED NOT NULL,
  scope_type ENUM('grupo','clase','tarea') NOT NULL DEFAULT 'grupo',
  titulo_asignacion VARCHAR(180) NULL,
  fecha_clase DATE NULL,
  instrucciones TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_aag (politica_id, grupo_id, scope_type, fecha_clase, titulo_asignacion),
  CONSTRAINT fk_aag_politica FOREIGN KEY (politica_id)
    REFERENCES actividad_acceso_politicas(id) ON DELETE CASCADE,
  CONSTRAINT fk_aag_grupo FOREIGN KEY (grupo_id)
    REFERENCES grupos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Habilitación individual: puede referirse a un estudiante importado
-- (grupo_estudiante_id), un usuario registrado (usuario_id) o solo un
-- email de alguien que aún no creó su cuenta.
CREATE TABLE IF NOT EXISTS actividad_acceso_estudiantes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  politica_id INT UNSIGNED NOT NULL,
  grupo_estudiante_id INT UNSIGNED NULL,
  usuario_id INT UNSIGNED NULL,
  email VARCHAR(255) NULL,
  estado ENUM('habilitado','bloqueado') NOT NULL DEFAULT 'habilitado',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_aae_politica (politica_id),
  KEY idx_aae_usuario (usuario_id),
  KEY idx_aae_email (email),
  CONSTRAINT fk_aae_politica FOREIGN KEY (politica_id)
    REFERENCES actividad_acceso_politicas(id) ON DELETE CASCADE,
  CONSTRAINT fk_aae_ge FOREIGN KEY (grupo_estudiante_id)
    REFERENCES grupo_estudiantes(id) ON DELETE CASCADE,
  CONSTRAINT fk_aae_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ════════════════════ 4. Versionado evaluativo ════════════════════

CREATE TABLE IF NOT EXISTS actividad_versiones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actividad_tipo ENUM('proyecto','study_deck','lotto_activity','crossword_project',
                      'wordsearch_project','jigsaw_project','etiquetar_project') NOT NULL,
  actividad_ref VARCHAR(100) NOT NULL,
  version_num INT UNSIGNED NOT NULL DEFAULT 1,
  content_hash CHAR(64) NOT NULL,
  snapshot_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  notes TEXT NULL,
  UNIQUE KEY uq_av_version (actividad_tipo, actividad_ref, version_num),
  KEY idx_av_actividad (actividad_tipo, actividad_ref),
  KEY idx_av_hash (content_hash),
  CONSTRAINT fk_av_creador FOREIGN KEY (created_by)
    REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT chk_av_snapshot CHECK (JSON_VALID(snapshot_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ════════════════════ 5. Entregas transversales ════════════════════

-- Capa de reporte/exportación/evaluación formal. NO reemplaza las
-- tablas de resultados de cada modalidad: las referencia vía
-- source_table/source_id.
CREATE TABLE IF NOT EXISTS actividad_entregas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  politica_id INT UNSIGNED NULL,
  version_id INT UNSIGNED NULL,
  actividad_tipo ENUM('proyecto','study_deck','lotto_activity','crossword_project',
                      'wordsearch_project','jigsaw_project','etiquetar_project') NOT NULL,
  actividad_ref VARCHAR(100) NOT NULL,
  usuario_id INT UNSIGNED NULL,
  grupo_id INT UNSIGNED NULL,
  grupo_estudiante_id INT UNSIGNED NULL,
  source_table VARCHAR(80) NULL,
  source_id VARCHAR(100) NULL,
  estado ENUM('started','submitted','graded','cancelled') NOT NULL DEFAULT 'started',
  puntaje DECIMAL(8,2) NULL,
  max_puntaje DECIMAL(8,2) NULL,
  porcentaje DECIMAL(5,2) NULL,
  time_ms INT UNSIGNED NULL,
  started_at DATETIME NULL,
  submitted_at DATETIME NULL,
  evaluativa TINYINT(1) NOT NULL DEFAULT 0,
  summary_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ae_usuario (usuario_id),
  KEY idx_ae_grupo (grupo_id),
  KEY idx_ae_actividad (actividad_tipo, actividad_ref),
  KEY idx_ae_version (version_id),
  KEY idx_ae_evaluativa (evaluativa),
  CONSTRAINT fk_ae_politica FOREIGN KEY (politica_id)
    REFERENCES actividad_acceso_politicas(id) ON DELETE SET NULL,
  CONSTRAINT fk_ae_version FOREIGN KEY (version_id)
    REFERENCES actividad_versiones(id) ON DELETE SET NULL,
  CONSTRAINT fk_ae_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_ae_grupo FOREIGN KEY (grupo_id)
    REFERENCES grupos(id) ON DELETE SET NULL,
  CONSTRAINT fk_ae_ge FOREIGN KEY (grupo_estudiante_id)
    REFERENCES grupo_estudiantes(id) ON DELETE SET NULL,
  CONSTRAINT chk_ae_summary CHECK (summary_json IS NULL OR JSON_VALID(summary_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ════════════════════ 6. ALTERs aditivos (condicionales) ════════════════════

DROP PROCEDURE IF EXISTS triviax_add_col_64;
DELIMITER //
CREATE PROCEDURE triviax_add_col_64(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_def TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
  ) THEN
    SET @s = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_def);
    PREPARE stmt FROM @s;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END //
DELIMITER ;

-- sesiones: vínculo con política, versión y marca evaluativa
CALL triviax_add_col_64('sesiones', 'politica_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('sesiones', 'actividad_version_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('sesiones', 'evaluativa', 'TINYINT(1) NOT NULL DEFAULT 0');

-- sesion_jugadores: pertenencia y nivel de identidad verificado
CALL triviax_add_col_64('sesion_jugadores', 'grupo_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('sesion_jugadores', 'grupo_estudiante_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('sesion_jugadores', 'identity_status',
  "ENUM('anonimo','login','email_verificado','validado_docente') NOT NULL DEFAULT 'anonimo'");
CALL triviax_add_col_64('sesion_jugadores', 'access_verified_at', 'DATETIME NULL');

-- study_sessions / jigsaw_sessions / etiquetar_sessions
CALL triviax_add_col_64('study_sessions', 'politica_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('study_sessions', 'actividad_version_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('study_sessions', 'grupo_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('study_sessions', 'grupo_estudiante_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('study_sessions', 'evaluativa', 'TINYINT(1) NOT NULL DEFAULT 0');

CALL triviax_add_col_64('jigsaw_sessions', 'politica_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('jigsaw_sessions', 'actividad_version_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('jigsaw_sessions', 'grupo_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('jigsaw_sessions', 'grupo_estudiante_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('jigsaw_sessions', 'evaluativa', 'TINYINT(1) NOT NULL DEFAULT 0');

CALL triviax_add_col_64('etiquetar_sessions', 'politica_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('etiquetar_sessions', 'actividad_version_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('etiquetar_sessions', 'grupo_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('etiquetar_sessions', 'grupo_estudiante_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('etiquetar_sessions', 'evaluativa', 'TINYINT(1) NOT NULL DEFAULT 0');

-- lotto_activities: solo el vínculo lateral (nivel/grupo/status ya existen)
CALL triviax_add_col_64('lotto_activities', 'politica_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('lotto_activities', 'actividad_version_id', 'INT UNSIGNED NULL');

-- Tablas de resultados por modalidad: versión usada (nullable)
CALL triviax_add_col_64('resultados', 'actividad_version_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('intentos', 'actividad_version_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('study_attempts', 'actividad_version_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('lotto_student_responses', 'actividad_version_id', 'INT UNSIGNED NULL');
CALL triviax_add_col_64('lotto_evaluations', 'actividad_version_id', 'INT UNSIGNED NULL');

DROP PROCEDURE IF EXISTS triviax_add_col_64;
