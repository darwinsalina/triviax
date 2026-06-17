-- ───────────────────────────────────────────────────────────────────────────
-- TRIVIAX · Migración 6.2 — Persistencia de desafíos en BD (importador #7)
--
-- Versiona las tablas `desafios` y `stats_desafios`, que ya existían en la BD
-- de desarrollo pero no tenían DDL en el repositorio. El importador
-- filesystem→BD (php/project_import.php) escribe en `desafios`; el payload
-- completo de cada desafío se guarda en `data_json` (no hay tablas hijas).
--
-- Idempotente: CREATE TABLE IF NOT EXISTS. Seguro de aplicar sobre una BD que
-- ya las tenga (no toca datos existentes).
-- ───────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `desafios` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `proyecto_id` varchar(100) COLLATE utf8mb4_spanish_ci NOT NULL,
  `challenge_key` varchar(100) COLLATE utf8mb4_spanish_ci NOT NULL COMMENT 'Id dentro del proyecto (ej: "1", "mc_001")',
  `tipo` enum('multiple_choice','true_false','matching_pairs','sequence_order','drag_drop','fill_blank','media_choice','image_hotspot','code_challenge') COLLATE utf8mb4_spanish_ci NOT NULL DEFAULT 'multiple_choice',
  `prompt_text` text COLLATE utf8mb4_spanish_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `difficulty` tinyint unsigned DEFAULT '1' COMMENT '1=fácil 2=medio 3=difícil',
  `points` smallint unsigned DEFAULT '10',
  `time_limit` smallint unsigned DEFAULT NULL COMMENT 'Segundos (NULL = sin límite)',
  `data_json` json DEFAULT NULL COMMENT 'Payload completo del desafío',
  `orden` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_proyecto_key` (`proyecto_id`,`challenge_key`),
  CONSTRAINT `fk_desafios_proyecto` FOREIGN KEY (`proyecto_id`) REFERENCES `proyectos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS `stats_desafios` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `proyecto_id` varchar(100) COLLATE utf8mb4_spanish_ci NOT NULL,
  `challenge_key` varchar(100) COLLATE utf8mb4_spanish_ci NOT NULL,
  `challenge_type` varchar(50) COLLATE utf8mb4_spanish_ci NOT NULL DEFAULT 'multiple_choice',
  `shown` int unsigned NOT NULL DEFAULT '0',
  `correct` int unsigned NOT NULL DEFAULT '0',
  `incorrect` int unsigned NOT NULL DEFAULT '0',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stats` (`proyecto_id`,`challenge_key`),
  CONSTRAINT `fk_stats_proyecto` FOREIGN KEY (`proyecto_id`) REFERENCES `proyectos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
