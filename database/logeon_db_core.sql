-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Versione server:              10.4.32-MariaDB - mariadb.org binary distribution
-- S.O. server:                  Win64
-- HeidiSQL Versione:            12.15.0.7171
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

-- Dump della struttura di tabella logeon_db.applied_narrative_states
DROP TABLE IF EXISTS `applied_narrative_states`;
CREATE TABLE IF NOT EXISTS `applied_narrative_states` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `state_id` int(11) NOT NULL,
  `source_ability_id` int(11) DEFAULT NULL,
  `source_event_id` int(11) DEFAULT NULL,
  `scene_id` int(11) DEFAULT NULL,
  `target_type` enum('character','scene','location','faction','conflict','event') NOT NULL,
  `target_id` int(11) NOT NULL,
  `applier_character_id` int(11) NOT NULL,
  `intensity` decimal(10,2) DEFAULT NULL,
  `stacks` int(11) NOT NULL DEFAULT 1,
  `duration_value` int(11) DEFAULT NULL,
  `duration_unit` varchar(20) DEFAULT NULL,
  `status` enum('active','expired','removed') NOT NULL DEFAULT 'active',
  `visibility` enum('public','private','staff_only','hidden') NOT NULL DEFAULT 'public',
  `meta_json` longtext DEFAULT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `removed_at` timestamp NULL DEFAULT NULL,
  `removal_reason` varchar(80) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_applied_states_target` (`target_type`,`target_id`,`status`),
  KEY `idx_applied_states_scene` (`scene_id`,`status`),
  KEY `idx_applied_states_state` (`state_id`,`status`),
  KEY `idx_applied_states_conflict` (`status`,`state_id`,`target_type`,`target_id`,`scene_id`),
  KEY `idx_applied_states_source_event` (`source_event_id`),
  KEY `idx_applied_states_visibility` (`visibility`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.applied_narrative_states: ~0 rows (circa)
DELETE FROM `applied_narrative_states`;

-- Dump della struttura di tabella logeon_db.archetype_configs
DROP TABLE IF EXISTS `archetype_configs`;
CREATE TABLE IF NOT EXISTS `archetype_configs` (
  `id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `archetypes_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `archetype_required` tinyint(1) NOT NULL DEFAULT 0,
  `multiple_archetypes_allowed` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.archetype_configs: ~0 rows (circa)
DELETE FROM `archetype_configs`;
INSERT INTO `archetype_configs` (`id`, `archetypes_enabled`, `archetype_required`, `multiple_archetypes_allowed`) VALUES
	(1, 1, 0, 0);

-- Dump della struttura di tabella logeon_db.archetypes
DROP TABLE IF EXISTS `archetypes`;
CREATE TABLE IF NOT EXISTS `archetypes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `lore_text` text DEFAULT NULL,
  `icon` varchar(512) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_selectable` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_archetypes_slug` (`slug`),
  KEY `idx_archetypes_active_selectable` (`is_active`,`is_selectable`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.archetypes: ~0 rows (circa)
DELETE FROM `archetypes`;
INSERT INTO `archetypes` (`id`, `name`, `slug`, `description`, `lore_text`, `icon`, `is_active`, `is_selectable`, `sort_order`, `created_at`) VALUES
	(1, 'Umano', 'umano', 'Un semplice umano', NULL, NULL, 1, 1, 1, '2026-04-15 12:58:38');

-- Dump della struttura di tabella logeon_db.blacklist
DROP TABLE IF EXISTS `blacklist`;
CREATE TABLE IF NOT EXISTS `blacklist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `banned_id` int(11) NOT NULL,
  `author_id` int(11) NOT NULL,
  `motivation` varchar(255) NOT NULL DEFAULT '',
  `date_start` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_end` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `author_id` (`author_id`),
  KEY `banned_id` (`banned_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.blacklist: ~0 rows (circa)
DELETE FROM `blacklist`;

-- Dump della struttura di tabella logeon_db.character_archetypes
DROP TABLE IF EXISTS `character_archetypes`;
CREATE TABLE IF NOT EXISTS `character_archetypes` (
  `character_id` int(10) unsigned NOT NULL,
  `archetype_id` int(10) unsigned NOT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`character_id`,`archetype_id`),
  KEY `idx_character_archetypes_archetype` (`archetype_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_archetypes: ~0 rows (circa)
DELETE FROM `character_archetypes`;

-- Dump della struttura di tabella logeon_db.character_attribute_definitions
DROP TABLE IF EXISTS `character_attribute_definitions`;
CREATE TABLE IF NOT EXISTS `character_attribute_definitions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(80) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `attribute_group` enum('primary','secondary','narrative') NOT NULL DEFAULT 'primary',
  `value_type` enum('number') NOT NULL DEFAULT 'number',
  `position` int(11) NOT NULL DEFAULT 0,
  `min_value` decimal(12,2) DEFAULT NULL,
  `max_value` decimal(12,2) DEFAULT NULL,
  `default_value` decimal(12,2) DEFAULT NULL,
  `fallback_value` decimal(12,2) DEFAULT NULL,
  `round_mode` enum('none','floor','ceil','round') NOT NULL DEFAULT 'none',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_derived` tinyint(1) NOT NULL DEFAULT 0,
  `allow_manual_override` tinyint(1) NOT NULL DEFAULT 0,
  `visible_in_profile` tinyint(1) NOT NULL DEFAULT 1,
  `visible_in_location` tinyint(1) NOT NULL DEFAULT 0,
  `maps_to_core_health_max` tinyint(1) NOT NULL DEFAULT 0,
  `maps_health_active` tinyint(4) GENERATED ALWAYS AS (case when `is_active` = 1 and `maps_to_core_health_max` = 1 then 1 else NULL end) VIRTUAL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_character_attribute_slug` (`slug`),
  UNIQUE KEY `uq_character_attribute_health_active` (`maps_health_active`),
  KEY `idx_character_attribute_group_position` (`attribute_group`,`position`),
  KEY `idx_character_attribute_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_attribute_definitions: ~0 rows (circa)
DELETE FROM `character_attribute_definitions`;

-- Dump della struttura di tabella logeon_db.character_attribute_rule_steps
DROP TABLE IF EXISTS `character_attribute_rule_steps`;
CREATE TABLE IF NOT EXISTS `character_attribute_rule_steps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rule_id` int(11) NOT NULL,
  `step_order` int(11) NOT NULL DEFAULT 1,
  `operator_code` enum('set','add','sub','mul','div','min','max') NOT NULL DEFAULT 'set',
  `operand_type` enum('attribute','value') NOT NULL DEFAULT 'value',
  `operand_attribute_id` int(11) DEFAULT NULL,
  `operand_value` decimal(12,2) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_character_attribute_rule_step` (`rule_id`,`step_order`),
  KEY `idx_character_attribute_rule_steps_rule` (`rule_id`),
  KEY `idx_character_attribute_rule_steps_operand_attr` (`operand_attribute_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_attribute_rule_steps: ~0 rows (circa)
DELETE FROM `character_attribute_rule_steps`;

-- Dump della struttura di tabella logeon_db.character_attribute_rules
DROP TABLE IF EXISTS `character_attribute_rules`;
CREATE TABLE IF NOT EXISTS `character_attribute_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attribute_id` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `fallback_value` decimal(12,2) DEFAULT NULL,
  `round_mode` enum('none','floor','ceil','round') DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_character_attribute_rule_attribute` (`attribute_id`),
  KEY `idx_character_attribute_rule_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_attribute_rules: ~0 rows (circa)
DELETE FROM `character_attribute_rules`;

-- Dump della struttura di tabella logeon_db.character_attribute_values
DROP TABLE IF EXISTS `character_attribute_values`;
CREATE TABLE IF NOT EXISTS `character_attribute_values` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `character_id` int(11) NOT NULL,
  `attribute_id` int(11) NOT NULL,
  `base_value` decimal(12,2) DEFAULT NULL,
  `override_value` decimal(12,2) DEFAULT NULL,
  `effective_value` decimal(12,2) DEFAULT NULL,
  `value_source` enum('base','default','override','derived','fallback') NOT NULL DEFAULT 'base',
  `last_recomputed_at` timestamp NULL DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_character_attribute_value` (`character_id`,`attribute_id`),
  KEY `idx_character_attribute_values_character` (`character_id`),
  KEY `idx_character_attribute_values_attribute` (`attribute_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_attribute_values: ~0 rows (circa)
DELETE FROM `character_attribute_values`;

-- Dump della struttura di tabella logeon_db.character_bond_events
DROP TABLE IF EXISTS `character_bond_events`;
CREATE TABLE IF NOT EXISTS `character_bond_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bond_id` int(11) NOT NULL,
  `actor_id` int(11) NOT NULL,
  `target_id` int(11) NOT NULL,
  `event_key` varchar(50) NOT NULL,
  `points` smallint(6) NOT NULL,
  `source_type` varchar(30) NOT NULL,
  `source_id` int(11) DEFAULT NULL,
  `meta_json` text DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `bond_event_bond_idx` (`bond_id`),
  KEY `bond_event_pair_idx` (`actor_id`,`target_id`),
  KEY `bond_event_key_idx` (`event_key`),
  KEY `bond_event_date_idx` (`date_created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_bond_events: ~0 rows (circa)
DELETE FROM `character_bond_events`;

-- Dump della struttura di tabella logeon_db.character_bond_requests
DROP TABLE IF EXISTS `character_bond_requests`;
CREATE TABLE IF NOT EXISTS `character_bond_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `requester_id` int(11) NOT NULL,
  `target_id` int(11) NOT NULL,
  `action_type` varchar(20) NOT NULL,
  `requested_type` varchar(32) DEFAULT NULL,
  `message` varchar(1000) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_resolved` datetime DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bond_req_requester_idx` (`requester_id`),
  KEY `bond_req_target_idx` (`target_id`),
  KEY `bond_req_status_idx` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_bond_requests: ~0 rows (circa)
DELETE FROM `character_bond_requests`;

-- Dump della struttura di tabella logeon_db.character_bonds
DROP TABLE IF EXISTS `character_bonds`;
CREATE TABLE IF NOT EXISTS `character_bonds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `character_low_id` int(11) NOT NULL,
  `character_high_id` int(11) NOT NULL,
  `bond_type` varchar(32) NOT NULL DEFAULT 'conoscente',
  `intensity` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `is_public` tinyint(1) NOT NULL DEFAULT 1,
  `created_by_character_id` int(11) NOT NULL,
  `last_interaction_at` datetime DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `bond_pair_unique` (`character_low_id`,`character_high_id`),
  KEY `bond_low_idx` (`character_low_id`),
  KEY `bond_high_idx` (`character_high_id`),
  KEY `bond_status_idx` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_bonds: ~0 rows (circa)
DELETE FROM `character_bonds`;

-- Dump della struttura di tabella logeon_db.character_chat_archive_messages
DROP TABLE IF EXISTS `character_chat_archive_messages`;
CREATE TABLE IF NOT EXISTS `character_chat_archive_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `archive_id` int(11) NOT NULL,
  `source_message_id` int(11) DEFAULT NULL,
  `sent_at` datetime NOT NULL,
  `character_id` int(11) DEFAULT NULL,
  `character_name_snapshot` varchar(160) NOT NULL DEFAULT '',
  `message_type` tinyint(1) NOT NULL DEFAULT 1,
  `message_html` mediumtext NOT NULL,
  `metadata_json` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_chat_archive_msgs_archive_id` (`archive_id`,`id`),
  KEY `idx_chat_archive_msgs_archive_time` (`archive_id`,`sent_at`),
  CONSTRAINT `fk_chat_archive_msgs_archive` FOREIGN KEY (`archive_id`) REFERENCES `character_chat_archives` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_chat_archive_messages: ~0 rows (circa)
DELETE FROM `character_chat_archive_messages`;

-- Dump della struttura di tabella logeon_db.character_chat_archives
DROP TABLE IF EXISTS `character_chat_archives`;
CREATE TABLE IF NOT EXISTS `character_chat_archives` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `owner_user_id` int(11) NOT NULL,
  `owner_character_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `source_type` enum('location') NOT NULL DEFAULT 'location',
  `source_location_id` int(11) DEFAULT NULL,
  `started_at` datetime NOT NULL,
  `ended_at` datetime NOT NULL,
  `diary_event_id` int(11) DEFAULT NULL,
  `visibility` enum('private','public') NOT NULL DEFAULT 'private',
  `public_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `public_token` varchar(80) DEFAULT NULL,
  `checksum_hash` varchar(64) NOT NULL DEFAULT '',
  `total_messages_in_range` int(11) NOT NULL DEFAULT 0,
  `included_messages_count` int(11) NOT NULL DEFAULT 0,
  `total_participants_in_range` int(11) NOT NULL DEFAULT 0,
  `included_participants_count` int(11) NOT NULL DEFAULT 0,
  `completeness_level` enum('complete','partial') NOT NULL DEFAULT 'complete',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_archives_uuid` (`uuid`),
  UNIQUE KEY `uq_chat_archives_public_token` (`public_token`),
  KEY `idx_chat_archives_owner_char` (`owner_character_id`,`deleted_at`),
  KEY `idx_chat_archives_owner_user` (`owner_user_id`,`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_chat_archives: ~0 rows (circa)
DELETE FROM `character_chat_archives`;

-- Dump della struttura di tabella logeon_db.character_equipment
DROP TABLE IF EXISTS `character_equipment`;
CREATE TABLE IF NOT EXISTS `character_equipment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `character_id` int(11) NOT NULL,
  `slot_id` int(11) NOT NULL,
  `inventory_item_id` int(11) DEFAULT NULL,
  `character_item_instance_id` int(11) DEFAULT NULL,
  `equipped_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_character_slot` (`character_id`,`slot_id`),
  UNIQUE KEY `uniq_character_instance` (`character_item_instance_id`),
  UNIQUE KEY `uniq_inventory_item` (`inventory_item_id`),
  KEY `idx_character_equipment_character` (`character_id`),
  KEY `idx_character_equipment_slot` (`slot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_equipment: ~0 rows (circa)
DELETE FROM `character_equipment`;

-- Dump della struttura di tabella logeon_db.character_events
DROP TABLE IF EXISTS `character_events`;
CREATE TABLE IF NOT EXISTS `character_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `character_id` int(11) NOT NULL,
  `title` varchar(160) NOT NULL,
  `body` text NOT NULL,
  `location_id` int(11) DEFAULT NULL,
  `date_event` date DEFAULT NULL,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  `created_by_user_id` int(11) NOT NULL,
  `created_by_character_id` int(11) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `character_id` (`character_id`),
  KEY `location_id` (`location_id`),
  KEY `is_visible` (`is_visible`),
  KEY `date_event` (`date_event`),
  KEY `date_created` (`date_created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_events: ~0 rows (circa)
DELETE FROM `character_events`;

-- Dump della struttura di tabella logeon_db.character_identity_requests
DROP TABLE IF EXISTS `character_identity_requests`;
CREATE TABLE IF NOT EXISTS `character_identity_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `character_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `new_surname` varchar(30) DEFAULT NULL,
  `new_height` decimal(5,2) DEFAULT NULL,
  `new_weight` smallint(6) DEFAULT NULL,
  `new_eyes` varchar(80) DEFAULT NULL,
  `new_hair` varchar(80) DEFAULT NULL,
  `new_skin` varchar(80) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `date_created` datetime NOT NULL,
  `date_resolved` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cir_character_id` (`character_id`),
  KEY `idx_cir_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_identity_requests: ~0 rows (circa)
DELETE FROM `character_identity_requests`;

-- Dump della struttura di tabella logeon_db.character_item_instances
DROP TABLE IF EXISTS `character_item_instances`;
CREATE TABLE IF NOT EXISTS `character_item_instances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `character_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `is_equipped` tinyint(1) NOT NULL DEFAULT 0,
  `slot` varchar(30) DEFAULT NULL,
  `durability` int(11) DEFAULT NULL,
  `meta_json` text DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `character_id` (`character_id`),
  KEY `item_id` (`item_id`),
  KEY `character_equipped` (`character_id`,`is_equipped`),
  KEY `character_slot` (`character_id`,`slot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_item_instances: ~0 rows (circa)
DELETE FROM `character_item_instances`;

-- Dump della struttura di tabella logeon_db.character_job_tasks
DROP TABLE IF EXISTS `character_job_tasks`;
CREATE TABLE IF NOT EXISTS `character_job_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `character_job_id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `assigned_date` date NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `choice_id` int(11) DEFAULT NULL,
  `pay` int(11) NOT NULL DEFAULT 0,
  `fame` int(11) NOT NULL DEFAULT 0,
  `points` int(11) NOT NULL DEFAULT 0,
  `date_completed` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_character_task_day` (`character_job_id`,`task_id`,`assigned_date`),
  KEY `character_job_id` (`character_job_id`),
  KEY `task_id` (`task_id`),
  KEY `assigned_date` (`assigned_date`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.character_job_tasks: ~0 rows (circa)
DELETE FROM `character_job_tasks`;

-- Dump della struttura di tabella logeon_db.character_jobs
DROP TABLE IF EXISTS `character_jobs`;
CREATE TABLE IF NOT EXISTS `character_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `character_id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `level` int(11) NOT NULL DEFAULT 1,
  `points` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `date_assigned` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_character_job` (`character_id`,`job_id`),
  KEY `character_id` (`character_id`),
  KEY `job_id` (`job_id`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.character_jobs: ~0 rows (circa)
DELETE FROM `character_jobs`;

-- Dump della struttura di tabella logeon_db.character_lifecycle_transitions
DROP TABLE IF EXISTS `character_lifecycle_transitions`;
CREATE TABLE IF NOT EXISTS `character_lifecycle_transitions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `character_id` int(11) NOT NULL,
  `from_phase_id` int(11) DEFAULT NULL COMMENT 'NULL for initial assignment',
  `to_phase_id` int(11) NOT NULL,
  `triggered_by` enum('admin','system','event','combat','conflict') NOT NULL DEFAULT 'admin',
  `triggered_by_event_id` int(11) DEFAULT NULL COMMENT 'FK to narrative_events.id if triggered by an event',
  `notes` text DEFAULT NULL,
  `applied_by` int(11) DEFAULT NULL COMMENT 'character_id of the staff who applied the transition',
  `meta_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lifecycle_transitions_character` (`character_id`,`created_at`),
  KEY `idx_lifecycle_transitions_phase` (`to_phase_id`),
  KEY `idx_lifecycle_transitions_event` (`triggered_by_event_id`)
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_lifecycle_transitions: ~0 rows (circa)
DELETE FROM `character_lifecycle_transitions`;

-- Dump della struttura di tabella logeon_db.character_loanface_requests
DROP TABLE IF EXISTS `character_loanface_requests`;
CREATE TABLE IF NOT EXISTS `character_loanface_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `character_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `current_loanface` varchar(255) NOT NULL,
  `new_loanface` varchar(255) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `date_resolved` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `character_id` (`character_id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  KEY `idx_character_loanface_requests_reviewed_by` (`reviewed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_loanface_requests: ~0 rows (circa)
DELETE FROM `character_loanface_requests`;

-- Dump della struttura di tabella logeon_db.character_name_requests
DROP TABLE IF EXISTS `character_name_requests`;
CREATE TABLE IF NOT EXISTS `character_name_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `character_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `current_name` varchar(25) NOT NULL,
  `new_name` varchar(25) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `date_resolved` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `character_id` (`character_id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  KEY `idx_character_name_requests_reviewed_by` (`reviewed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_name_requests: ~0 rows (circa)
DELETE FROM `character_name_requests`;

-- Dump della struttura di tabella logeon_db.character_wallets
DROP TABLE IF EXISTS `character_wallets`;
CREATE TABLE IF NOT EXISTS `character_wallets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `character_id` int(11) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `balance` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `character_currency_unique` (`character_id`,`currency_id`),
  KEY `character_id` (`character_id`),
  KEY `currency_id` (`currency_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_wallets: ~0 rows (circa)
DELETE FROM `character_wallets`;
INSERT INTO `character_wallets` (`id`, `character_id`, `currency_id`, `balance`) VALUES
	(1, 1, 1, 0);

-- Dump della struttura di tabella logeon_db.character_whisper_policies
DROP TABLE IF EXISTS `character_whisper_policies`;
CREATE TABLE IF NOT EXISTS `character_whisper_policies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `character_id` int(10) unsigned NOT NULL,
  `target_character_id` int(10) unsigned NOT NULL,
  `policy` enum('mute','block') NOT NULL DEFAULT 'mute',
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_character_target` (`character_id`,`target_character_id`),
  KEY `idx_target_character` (`target_character_id`),
  KEY `idx_policy` (`policy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.character_whisper_policies: ~0 rows (circa)
DELETE FROM `character_whisper_policies`;

-- Dump della struttura di tabella logeon_db.characters
DROP TABLE IF EXISTS `characters`;
CREATE TABLE IF NOT EXISTS `characters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `socialstatus_id` int(11) NOT NULL DEFAULT 1,
  `name` varchar(25) NOT NULL,
  `surname` varchar(30) DEFAULT NULL,
  `gender` tinyint(1) NOT NULL DEFAULT 0,
  `loanface` varchar(255) DEFAULT NULL,
  `last_map` int(11) DEFAULT NULL,
  `last_location` int(11) DEFAULT NULL,
  `height` double(11,2) DEFAULT NULL,
  `weight` int(11) DEFAULT NULL,
  `eyes` varchar(80) DEFAULT NULL,
  `hair` varchar(80) DEFAULT NULL,
  `skin` varchar(80) DEFAULT NULL,
  `particular_signs` varchar(255) DEFAULT NULL,
  `description_body` mediumtext DEFAULT NULL,
  `description_temper` mediumtext DEFAULT NULL,
  `background_story` text DEFAULT NULL,
  `friends_knowledge_html` longtext DEFAULT NULL,
  `mod_status` mediumtext DEFAULT NULL,
  `online_status` varchar(255) DEFAULT NULL,
  `privacy_show_online` tinyint(1) NOT NULL DEFAULT 1,
  `dm_policy` tinyint(1) NOT NULL DEFAULT 0,
  `invite_policy` tinyint(1) NOT NULL DEFAULT 0,
  `notify_messages` tinyint(1) NOT NULL DEFAULT 1,
  `notify_invites` tinyint(1) NOT NULL DEFAULT 1,
  `notify_news` tinyint(1) NOT NULL DEFAULT 1,
  `availability` tinyint(4) NOT NULL DEFAULT 1,
  `is_visible` tinyint(1) DEFAULT 1,
  `avatar` varchar(255) DEFAULT '/assets/imgs/defaults-images/default-profile.png',
  `background_music_url` varchar(512) DEFAULT NULL,
  `health` int(11) NOT NULL DEFAULT 100,
  `health_max` decimal(11,2) NOT NULL DEFAULT 100.00,
  `experience` double(11,2) NOT NULL DEFAULT 0.00,
  `rank` int(11) NOT NULL DEFAULT 1,
  `money` int(11) NOT NULL DEFAULT 0,
  `bank` int(11) NOT NULL DEFAULT 0,
  `fame` double(11,2) DEFAULT 0.00,
  `date_created` timestamp NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `date_last_signin` timestamp NULL DEFAULT NULL,
  `date_last_signout` timestamp NULL DEFAULT NULL,
  `date_last_seed` timestamp NULL DEFAULT NULL,
  `delete_requested_at` timestamp NULL DEFAULT NULL,
  `delete_scheduled_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `userID` (`user_id`),
  KEY `lastMap` (`last_map`),
  KEY `lastLocation` (`last_location`),
  KEY `SocialStatus_ID` (`socialstatus_id`) USING BTREE,
  KEY `idx_characters_visibility_seed` (`is_visible`,`date_last_seed`),
  KEY `idx_characters_availability_seed` (`availability`,`date_last_seed`),
  KEY `idx_characters_online_lookup` (`is_visible`,`privacy_show_online`,`date_last_seed`,`last_location`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump della struttura di tabella logeon_db.climate_areas
DROP TABLE IF EXISTS `climate_areas`;
CREATE TABLE IF NOT EXISTS `climate_areas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `weather_key` varchar(32) DEFAULT NULL,
  `degrees` int(11) DEFAULT NULL,
  `moon_phase` varchar(32) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_by` int(11) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_climate_areas_code` (`code`),
  KEY `idx_climate_areas_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.climate_areas: ~0 rows (circa)
DELETE FROM `climate_areas`;

-- Dump della struttura di tabella logeon_db.climate_assignments
DROP TABLE IF EXISTS `climate_assignments`;
CREATE TABLE IF NOT EXISTS `climate_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `scope_type` varchar(32) NOT NULL,
  `scope_id` int(11) NOT NULL,
  `climate_zone_id` int(11) NOT NULL,
  `priority` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_climate_assign_scope_zone` (`scope_type`,`scope_id`,`climate_zone_id`),
  KEY `idx_climate_assign_scope` (`scope_type`,`scope_id`,`is_active`,`priority`),
  KEY `idx_climate_assign_zone` (`climate_zone_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.climate_assignments: ~0 rows (circa)
DELETE FROM `climate_assignments`;

-- Dump della struttura di tabella logeon_db.climate_zone_season_profiles
DROP TABLE IF EXISTS `climate_zone_season_profiles`;
CREATE TABLE IF NOT EXISTS `climate_zone_season_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `climate_zone_id` int(11) NOT NULL,
  `season_id` int(11) NOT NULL,
  `temperature_min` decimal(6,2) DEFAULT NULL,
  `temperature_max` decimal(6,2) DEFAULT NULL,
  `temperature_round_mode` varchar(16) NOT NULL DEFAULT 'round',
  `default_weather_type_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_climate_zone_season` (`climate_zone_id`,`season_id`),
  KEY `idx_czsp_active` (`is_active`,`climate_zone_id`,`season_id`),
  KEY `idx_czsp_weather_type` (`default_weather_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.climate_zone_season_profiles: ~0 rows (circa)
DELETE FROM `climate_zone_season_profiles`;

-- Dump della struttura di tabella logeon_db.climate_zone_weather_weights
DROP TABLE IF EXISTS `climate_zone_weather_weights`;
CREATE TABLE IF NOT EXISTS `climate_zone_weather_weights` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `profile_id` int(11) NOT NULL,
  `weather_type_id` int(11) NOT NULL,
  `weight` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_czww_profile_weather` (`profile_id`,`weather_type_id`),
  KEY `idx_czww_profile_active` (`profile_id`,`is_active`),
  KEY `idx_czww_weather_active` (`weather_type_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.climate_zone_weather_weights: ~0 rows (circa)
DELETE FROM `climate_zone_weather_weights`;

-- Dump della struttura di tabella logeon_db.climate_zones
DROP TABLE IF EXISTS `climate_zones`;
CREATE TABLE IF NOT EXISTS `climate_zones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `slug` varchar(80) NOT NULL,
  `description` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_climate_zones_slug` (`slug`),
  KEY `idx_climate_zones_active_sort` (`is_active`,`sort_order`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.climate_zones: ~0 rows (circa)
DELETE FROM `climate_zones`;

-- Dump della struttura di tabella logeon_db.conflict_action_targets
DROP TABLE IF EXISTS `conflict_action_targets`;
CREATE TABLE IF NOT EXISTS `conflict_action_targets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conflict_action_id` int(11) NOT NULL,
  `target_type` enum('character','npc','faction','group','team','self','scene') NOT NULL DEFAULT 'character',
  `target_id` int(11) DEFAULT NULL,
  `team_key` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_conflict_action_targets_action` (`conflict_action_id`),
  KEY `idx_conflict_action_targets_target` (`target_type`,`target_id`),
  KEY `idx_conflict_action_targets_team` (`team_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.conflict_action_targets: ~0 rows (circa)
DELETE FROM `conflict_action_targets`;

-- Dump della struttura di tabella logeon_db.conflict_actions
DROP TABLE IF EXISTS `conflict_actions`;
CREATE TABLE IF NOT EXISTS `conflict_actions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conflict_id` int(11) NOT NULL,
  `actor_type` enum('character','npc','system') NOT NULL DEFAULT 'character',
  `actor_id` int(11) DEFAULT NULL,
  `action_type` enum('action','note','verdict','system') NOT NULL DEFAULT 'action',
  `action_kind` varchar(64) DEFAULT NULL,
  `action_mode` varchar(32) DEFAULT NULL,
  `action_body` text NOT NULL,
  `chat_message_id` int(11) DEFAULT NULL,
  `resolution_type` varchar(64) DEFAULT NULL,
  `resolution_status` varchar(32) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `meta_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_conflict_actions_conflict_created` (`conflict_id`,`created_at`),
  KEY `idx_conflict_actions_actor` (`actor_id`),
  KEY `idx_conflict_actions_resolution` (`conflict_id`,`resolution_status`,`resolved_at`),
  KEY `idx_conflict_actions_chat_message` (`chat_message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.conflict_actions: ~0 rows (circa)
DELETE FROM `conflict_actions`;

-- Dump della struttura di tabella logeon_db.conflict_participants
DROP TABLE IF EXISTS `conflict_participants`;
CREATE TABLE IF NOT EXISTS `conflict_participants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conflict_id` int(11) NOT NULL,
  `participant_type` enum('character','npc','faction','group') NOT NULL DEFAULT 'character',
  `participant_id` int(11) DEFAULT NULL,
  `character_id` int(11) NOT NULL,
  `participant_role` enum('actor','target','support','witness','other') NOT NULL DEFAULT 'actor',
  `team_key` varchar(64) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `joined_at` datetime DEFAULT NULL,
  `left_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_conflict_participant` (`conflict_id`,`character_id`),
  KEY `idx_conflict_participants_conflict` (`conflict_id`),
  KEY `idx_conflict_participants_character` (`character_id`),
  KEY `idx_conflict_participants_entity_active` (`participant_type`,`participant_id`,`is_active`),
  KEY `idx_conflict_participants_conflict_team_active` (`conflict_id`,`team_key`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.conflict_participants: ~0 rows (circa)
DELETE FROM `conflict_participants`;

-- Dump della struttura di tabella logeon_db.conflict_roll_log
DROP TABLE IF EXISTS `conflict_roll_log`;
CREATE TABLE IF NOT EXISTS `conflict_roll_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conflict_id` int(11) NOT NULL,
  `actor_id` int(11) NOT NULL,
  `roll_type` enum('single_roll','single_roll_with_modifiers','opposed_roll','threshold_roll') NOT NULL DEFAULT 'single_roll',
  `die_used` enum('d4','d6','d8','d10','d12','d16','d20','d100') NOT NULL DEFAULT 'd20',
  `base_roll` int(11) NOT NULL,
  `modifiers` decimal(12,2) NOT NULL DEFAULT 0.00,
  `final_result` decimal(12,2) NOT NULL,
  `critical_flag` enum('none','success','failure') NOT NULL DEFAULT 'none',
  `margin` decimal(12,2) DEFAULT NULL,
  `meta_json` longtext DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_conflict_roll_log_conflict_time` (`conflict_id`,`timestamp`),
  KEY `idx_conflict_roll_log_actor` (`actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.conflict_roll_log: ~0 rows (circa)
DELETE FROM `conflict_roll_log`;

-- Dump della struttura di tabella logeon_db.conflicts
DROP TABLE IF EXISTS `conflicts`;
CREATE TABLE IF NOT EXISTS `conflicts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `location_id` int(11) DEFAULT NULL,
  `conflict_origin` enum('chat','admin','system') NOT NULL DEFAULT 'admin',
  `opened_by` int(11) NOT NULL,
  `resolution_mode` enum('narrative','random') NOT NULL DEFAULT 'narrative',
  `resolution_authority` enum('players','master','mixed','deferred_review') NOT NULL DEFAULT 'mixed',
  `status` enum('proposal','open','active','awaiting_resolution','resolved','closed') NOT NULL DEFAULT 'open',
  `proposal_expires_at` datetime DEFAULT NULL,
  `last_activity_at` datetime DEFAULT NULL,
  `outcome_summary` text DEFAULT NULL,
  `verdict_text` text DEFAULT NULL,
  `verdict_meta_json` longtext DEFAULT NULL,
  `participants_snapshot_json` longtext DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `closing_timestamp` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_conflicts_location_status` (`location_id`,`status`),
  KEY `idx_conflicts_status_created` (`status`,`created_at`),
  KEY `idx_conflicts_opened_by` (`opened_by`),
  KEY `idx_conflicts_resolution` (`resolution_mode`,`resolution_authority`),
  KEY `idx_conflicts_proposal_expiry` (`status`,`proposal_expires_at`),
  KEY `idx_conflicts_inactivity` (`status`,`last_activity_at`),
  KEY `idx_conflicts_origin_status_created` (`conflict_origin`,`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.conflicts: ~0 rows (circa)
DELETE FROM `conflicts`;

-- Dump della struttura di tabella logeon_db.currencies
DROP TABLE IF EXISTS `currencies`;
CREATE TABLE IF NOT EXISTS `currencies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `symbol` varchar(10) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.currencies: ~0 rows (circa)
DELETE FROM `currencies`;
INSERT INTO `currencies` (`id`, `code`, `name`, `symbol`, `image`, `is_default`, `is_active`) VALUES
	(1, 'Coin', 'Coin', 'C', '/assets/imgs/defaults-images/default-icon.png', 1, 1);

-- Dump della struttura di tabella logeon_db.currency_logs
DROP TABLE IF EXISTS `currency_logs`;
CREATE TABLE IF NOT EXISTS `currency_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `character_id` int(11) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `account` varchar(20) NOT NULL DEFAULT 'money',
  `amount` decimal(11,2) NOT NULL,
  `balance_before` decimal(11,2) DEFAULT NULL,
  `balance_after` decimal(11,2) DEFAULT NULL,
  `source` varchar(50) NOT NULL,
  `meta` text DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `character_id` (`character_id`),
  KEY `currency_id` (`currency_id`),
  KEY `account` (`account`),
  KEY `source` (`source`),
  KEY `date_created` (`date_created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.currency_logs: ~0 rows (circa)
DELETE FROM `currency_logs`;

-- Dump della struttura di tabella logeon_db.email_verifications
DROP TABLE IF EXISTS `email_verifications`;
CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email_verifications_token_hash` (`token_hash`),
  KEY `idx_email_verifications_user_id` (`user_id`),
  KEY `idx_email_verifications_expires_at` (`expires_at`),
  KEY `idx_email_verifications_used_at` (`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.email_verifications: ~0 rows (circa)
DELETE FROM `email_verifications`;

-- Dump della struttura di tabella logeon_db.equipment_slots
DROP TABLE IF EXISTS `equipment_slots`;
CREATE TABLE IF NOT EXISTS `equipment_slots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `group_key` varchar(50) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `max_equipped` int(11) NOT NULL DEFAULT 1,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_equipment_slots_key` (`key`),
  KEY `idx_equipment_slots_active_sort` (`is_active`,`sort_order`),
  KEY `idx_equipment_slots_group` (`group_key`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.equipment_slots: ~9 rows (circa)
DELETE FROM `equipment_slots`;
INSERT INTO `equipment_slots` (`id`, `key`, `name`, `description`, `icon`, `group_key`, `sort_order`, `is_active`, `max_equipped`, `date_created`, `date_updated`) VALUES
	(1, 'amulet', 'Ciondolo', 'Slot ciondolo', NULL, 'amulet', 10, 1, 1, '2026-03-30 21:04:10', NULL),
	(2, 'helm', 'Elmo', 'Slot elmo', NULL, 'helm', 20, 1, 1, '2026-03-30 21:04:10', NULL),
	(3, 'weapon_1', 'Arma 1', 'Slot arma primaria', NULL, 'weapon', 30, 1, 1, '2026-03-30 21:04:10', NULL),
	(4, 'gloves', 'Guanti', 'Slot guanti', NULL, 'gloves', 40, 1, 1, '2026-03-30 21:04:10', NULL),
	(5, 'armor', 'Armatura', 'Slot armatura', NULL, 'armor', 50, 1, 1, '2026-03-30 21:04:10', NULL),
	(6, 'weapon_2', 'Arma 2', 'Slot arma secondaria', NULL, 'weapon', 60, 1, 1, '2026-03-30 21:04:10', NULL),
	(7, 'ring_1', 'Anello 1', 'Slot anello sinistro', NULL, 'ring', 70, 1, 1, '2026-03-30 21:04:10', NULL),
	(8, 'boots', 'Stivali', 'Slot stivali', NULL, 'boots', 80, 1, 1, '2026-03-30 21:04:10', NULL),
	(9, 'ring_2', 'Anello 2', 'Slot anello destro', NULL, 'ring', 90, 1, 1, '2026-03-30 21:04:10', NULL);

-- Dump della struttura di tabella logeon_db.experience_logs
DROP TABLE IF EXISTS `experience_logs`;
CREATE TABLE IF NOT EXISTS `experience_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `character_id` int(11) NOT NULL,
  `experience_before` decimal(11,2) NOT NULL DEFAULT 0.00,
  `experience_after` decimal(11,2) NOT NULL DEFAULT 0.00,
  `delta` decimal(11,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL,
  `source` varchar(50) NOT NULL DEFAULT 'manual',
  `author_id` int(11) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_experience_logs_character` (`character_id`),
  KEY `idx_experience_logs_author` (`author_id`),
  KEY `idx_experience_logs_date` (`date_created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.experience_logs: ~0 rows (circa)
DELETE FROM `experience_logs`;

-- Dump della struttura di tabella logeon_db.faction_join_requests
DROP TABLE IF EXISTS `faction_join_requests`;
CREATE TABLE IF NOT EXISTS `faction_join_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `faction_id` int(11) NOT NULL,
  `character_id` int(11) NOT NULL,
  `message` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','withdrawn') NOT NULL DEFAULT 'pending',
  `reviewed_by_character_id` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fjr_faction` (`faction_id`,`status`),
  KEY `idx_fjr_character` (`character_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.faction_join_requests: ~0 rows (circa)
DELETE FROM `faction_join_requests`;

-- Dump della struttura di tabella logeon_db.faction_memberships
DROP TABLE IF EXISTS `faction_memberships`;
CREATE TABLE IF NOT EXISTS `faction_memberships` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `faction_id` int(11) NOT NULL,
  `character_id` int(11) NOT NULL,
  `role` varchar(60) NOT NULL DEFAULT 'member' COMMENT 'member|leader|advisor|agent|initiate',
  `rank` varchar(60) DEFAULT NULL COMMENT 'narrative rank title within faction',
  `status` enum('active','inactive','expelled') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `left_at` timestamp NULL DEFAULT NULL,
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_faction_member` (`faction_id`,`character_id`),
  KEY `idx_faction_memberships_faction` (`faction_id`,`status`),
  KEY `idx_faction_memberships_character` (`character_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.faction_memberships: ~0 rows (circa)
DELETE FROM `faction_memberships`;

-- Dump della struttura di tabella logeon_db.faction_relationships
DROP TABLE IF EXISTS `faction_relationships`;
CREATE TABLE IF NOT EXISTS `faction_relationships` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `faction_id` int(11) NOT NULL,
  `target_faction_id` int(11) NOT NULL,
  `relation_type` enum('ally','neutral','rival','enemy','vassal','overlord') NOT NULL DEFAULT 'neutral',
  `intensity` tinyint(4) NOT NULL DEFAULT 5 COMMENT '1-10 relationship strength',
  `notes` text DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_faction_relation` (`faction_id`,`target_faction_id`),
  KEY `idx_faction_relations_faction` (`faction_id`),
  KEY `idx_faction_relations_target` (`target_faction_id`),
  KEY `idx_faction_relations_type` (`relation_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.faction_relationships: ~0 rows (circa)
DELETE FROM `faction_relationships`;

-- Dump della struttura di tabella logeon_db.factions
DROP TABLE IF EXISTS `factions`;
CREATE TABLE IF NOT EXISTS `factions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(80) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `type` varchar(60) NOT NULL DEFAULT 'political' COMMENT 'political|military|religious|criminal|mercantile|other',
  `scope` enum('local','regional','global') NOT NULL DEFAULT 'regional',
  `alignment` varchar(60) DEFAULT NULL COMMENT 'narrative alignment e.g. lawful_good, neutral, chaotic_evil',
  `power_level` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1-10 narrative weight',
  `is_public` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'visible to players',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `allow_join_requests` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Se 1, i player possono inviare richieste di adesione',
  `color_hex` varchar(7) DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `meta_json` longtext DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_factions_code` (`code`),
  KEY `idx_factions_active` (`is_active`,`is_public`),
  KEY `idx_factions_type` (`type`),
  KEY `idx_factions_scope` (`scope`)
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.factions: ~0 rows (circa)
DELETE FROM `factions`;

-- Dump della struttura di tabella logeon_db.fame_logs
DROP TABLE IF EXISTS `fame_logs`;
CREATE TABLE IF NOT EXISTS `fame_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `character_id` int(11) NOT NULL,
  `fame_before` double(11,2) NOT NULL DEFAULT 0.00,
  `fame_after` double(11,2) NOT NULL DEFAULT 0.00,
  `delta` double(11,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL,
  `source` varchar(30) NOT NULL DEFAULT 'manual',
  `author_id` int(11) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `character_id` (`character_id`),
  KEY `author_id` (`author_id`),
  KEY `date_created` (`date_created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.fame_logs: ~0 rows (circa)
DELETE FROM `fame_logs`;

-- Dump della struttura di tabella logeon_db.forum_threads
DROP TABLE IF EXISTS `forum_threads`;
CREATE TABLE IF NOT EXISTS `forum_threads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `father_id` int(11) DEFAULT NULL,
  `forum_id` int(11) NOT NULL,
  `character_id` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `body` longtext NOT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_important` tinyint(1) DEFAULT 0,
  `is_closed` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `father_topic_id` (`father_id`),
  KEY `forum_id` (`forum_id`),
  KEY `character_id` (`character_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.forum_threads: ~0 rows (circa)
DELETE FROM `forum_threads`;

-- Dump della struttura di tabella logeon_db.forum_types
DROP TABLE IF EXISTS `forum_types`;
CREATE TABLE IF NOT EXISTS `forum_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(75) NOT NULL,
  `subtitle` tinytext NOT NULL,
  `is_on_game` tinyint(1) NOT NULL DEFAULT 0,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.forum_types: ~4 rows (circa)
DELETE FROM `forum_types`;
INSERT INTO `forum_types` (`id`, `title`, `subtitle`, `is_on_game`, `date_created`, `date_updated`) VALUES
	(1, 'Gioco', 'In queste sezioni si parla prettamente degli eventi che succedono tra i personaggi e non tra i giocatori. Qui si potranno prendere spunti per creare giocate.', 1, '2023-06-05 22:00:00', NULL),
	(2, 'Comunicazioni', 'In queste sezioni verranno pubblicate le comunicazioni da parte dello staff che richiedono la vostra opinione, inoltre potrete aprire topic per diverse segnalazioni allo staff.', 0, '2023-06-05 22:00:00', NULL),
	(3, 'Proposte & Segnalazioni', 'In queste sezioni verranno inserite le proposte da parte degli utenti e le segnalazioni tecniche', 0, '2023-06-05 22:00:00', NULL),
	(4, 'Eventi', 'Sezione dedicata alla comunicazione degli eventi', 0, '2023-06-05 22:00:00', NULL);

-- Dump della struttura di tabella logeon_db.forums
DROP TABLE IF EXISTS `forums`;
CREATE TABLE IF NOT EXISTS `forums` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `type` tinyint(2) NOT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.forums: ~4 rows (circa)
DELETE FROM `forums`;
INSERT INTO `forums` (`id`, `name`, `description`, `type`, `date_created`) VALUES
	(1, 'Aggiornamenti', 'Sezione dedicata alla comunicazione degli aggiornamenti di gioco riguardanti: la storia, l\'ambientazione e la piattaforma di gioco', 2, '2020-09-03 13:32:08'),
	(2, 'Proposte', 'In questa sezione potete avanzare le vostre proposte per migliorare il gioco in tutti i suoi aspetti: storia, ambientazione, grafica e sviluppo.', 3, '2020-09-03 13:33:07'),
	(3, 'Bug', 'In questa sezione puoi segnalare i bug che riscontri all\'interno della piattaforma di gioco.', 3, '2020-09-03 13:33:40'),
	(4, 'Diario', 'Diaro delle cose che succendono in gioco', 1, '2022-09-19 21:34:48');

-- Dump della struttura di tabella logeon_db.guild_alignments
DROP TABLE IF EXISTS `guild_alignments`;
CREATE TABLE IF NOT EXISTS `guild_alignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.guild_alignments: ~0 rows (circa)
DELETE FROM `guild_alignments`;

-- Dump della struttura di tabella logeon_db.guild_announcements
DROP TABLE IF EXISTS `guild_announcements`;
CREATE TABLE IF NOT EXISTS `guild_announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `guild_id` int(11) NOT NULL,
  `title` varchar(160) NOT NULL,
  `body_html` text DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `guild_id` (`guild_id`),
  KEY `is_pinned` (`is_pinned`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.guild_announcements: ~0 rows (circa)
DELETE FROM `guild_announcements`;

-- Dump della struttura di tabella logeon_db.guild_applications
DROP TABLE IF EXISTS `guild_applications`;
CREATE TABLE IF NOT EXISTS `guild_applications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `guild_id` int(11) NOT NULL,
  `character_id` int(11) NOT NULL,
  `message` text DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_application` (`guild_id`,`character_id`),
  KEY `guild_id` (`guild_id`),
  KEY `character_id` (`character_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.guild_applications: ~0 rows (circa)
DELETE FROM `guild_applications`;

-- Dump della struttura di tabella logeon_db.guild_events
DROP TABLE IF EXISTS `guild_events`;
CREATE TABLE IF NOT EXISTS `guild_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `guild_id` int(11) NOT NULL,
  `title` varchar(160) NOT NULL,
  `body_html` text DEFAULT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `guild_id` (`guild_id`),
  KEY `starts_at` (`starts_at`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.guild_events: ~0 rows (circa)
DELETE FROM `guild_events`;

-- Dump della struttura di tabella logeon_db.guild_kick_requests
DROP TABLE IF EXISTS `guild_kick_requests`;
CREATE TABLE IF NOT EXISTS `guild_kick_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `guild_id` int(11) NOT NULL,
  `requester_id` int(11) NOT NULL,
  `target_id` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `guild_id` (`guild_id`),
  KEY `requester_id` (`requester_id`),
  KEY `target_id` (`target_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.guild_kick_requests: ~0 rows (circa)
DELETE FROM `guild_kick_requests`;

-- Dump della struttura di tabella logeon_db.guild_logs
DROP TABLE IF EXISTS `guild_logs`;
CREATE TABLE IF NOT EXISTS `guild_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `guild_id` int(11) NOT NULL,
  `action` varchar(40) NOT NULL,
  `actor_id` int(11) NOT NULL,
  `target_id` int(11) DEFAULT NULL,
  `meta` text DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `guild_id` (`guild_id`),
  KEY `action` (`action`),
  KEY `actor_id` (`actor_id`),
  KEY `target_id` (`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.guild_logs: ~0 rows (circa)
DELETE FROM `guild_logs`;

-- Dump della struttura di tabella logeon_db.guild_members
DROP TABLE IF EXISTS `guild_members`;
CREATE TABLE IF NOT EXISTS `guild_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `guild_id` int(11) NOT NULL,
  `character_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `salary_last_claim_at` datetime DEFAULT NULL,
  `date_joined` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_member_guild` (`guild_id`,`character_id`),
  KEY `guild_id` (`guild_id`),
  KEY `character_id` (`character_id`),
  KEY `role_id` (`role_id`),
  KEY `idx_guild_members_role` (`guild_id`,`role_id`,`character_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.guild_members: ~0 rows (circa)
DELETE FROM `guild_members`;

-- Dump della struttura di tabella logeon_db.guild_requirements
DROP TABLE IF EXISTS `guild_requirements`;
CREATE TABLE IF NOT EXISTS `guild_requirements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `guild_id` int(11) NOT NULL,
  `type` varchar(40) NOT NULL,
  `value` varchar(120) NOT NULL,
  `label` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `guild_id` (`guild_id`),
  KEY `type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.guild_requirements: ~0 rows (circa)
DELETE FROM `guild_requirements`;

-- Dump della struttura di tabella logeon_db.guild_role_locations
DROP TABLE IF EXISTS `guild_role_locations`;
CREATE TABLE IF NOT EXISTS `guild_role_locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `guild_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_role_location` (`guild_id`,`role_id`,`location_id`),
  KEY `guild_id` (`guild_id`),
  KEY `role_id` (`role_id`),
  KEY `location_id` (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.guild_role_locations: ~0 rows (circa)
DELETE FROM `guild_role_locations`;

-- Dump della struttura di tabella logeon_db.guild_role_scopes
DROP TABLE IF EXISTS `guild_role_scopes`;
CREATE TABLE IF NOT EXISTS `guild_role_scopes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `manages_role_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_role_scope` (`role_id`,`manages_role_id`),
  KEY `role_id` (`role_id`),
  KEY `manages_role_id` (`manages_role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.guild_role_scopes: ~0 rows (circa)
DELETE FROM `guild_role_scopes`;

-- Dump della struttura di tabella logeon_db.guild_roles
DROP TABLE IF EXISTS `guild_roles`;
CREATE TABLE IF NOT EXISTS `guild_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `guild_id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `monthly_salary` int(11) NOT NULL DEFAULT 0,
  `is_leader` tinyint(1) NOT NULL DEFAULT 0,
  `is_officer` tinyint(1) NOT NULL DEFAULT 0,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `guild_id` (`guild_id`),
  KEY `is_leader` (`is_leader`),
  KEY `is_officer` (`is_officer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.guild_roles: ~0 rows (circa)
DELETE FROM `guild_roles`;

-- Dump della struttura di tabella logeon_db.guilds
DROP TABLE IF EXISTS `guilds`;
CREATE TABLE IF NOT EXISTS `guilds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `alignment_id` int(11) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `icon` varchar(255) NOT NULL DEFAULT '/assets/imgs/defaults-images/default-icon.png',
  `website_url` varchar(255) DEFAULT NULL,
  `statute_html` text DEFAULT NULL,
  `objectives_html` text DEFAULT NULL,
  `purpose_html` text DEFAULT NULL,
  `requirements_html` text DEFAULT NULL,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  `leader_character_id` int(11) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `alignment_id` (`alignment_id`),
  KEY `is_visible` (`is_visible`),
  KEY `leader_character_id` (`leader_character_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.guilds: ~0 rows (circa)
DELETE FROM `guilds`;

-- Dump della struttura di tabella logeon_db.how_to_play
DROP TABLE IF EXISTS `how_to_play`;
CREATE TABLE IF NOT EXISTS `how_to_play` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `step` int(11) NOT NULL,
  `substep` int(11) NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_step_substep` (`step`,`substep`),
  KEY `idx_step` (`step`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.how_to_play: ~0 rows (circa)
DELETE FROM `how_to_play`;

-- Dump della struttura di tabella logeon_db.inventory_items
DROP TABLE IF EXISTS `inventory_items`;
CREATE TABLE IF NOT EXISTS `inventory_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `owner_type` varchar(20) NOT NULL DEFAULT 'player',
  `quantity` int(11) NOT NULL DEFAULT 1,
  `durability` int(11) DEFAULT NULL,
  `custom_name` varchar(150) DEFAULT NULL,
  `custom_description` text DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `cooldown_until` timestamp NULL DEFAULT NULL,
  `legacy_stack_id` int(11) DEFAULT NULL,
  `legacy_instance_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_inventory_items_owner_item` (`owner_type`,`owner_id`,`item_id`),
  UNIQUE KEY `uniq_inventory_items_legacy_stack` (`legacy_stack_id`),
  UNIQUE KEY `uniq_inventory_items_legacy_instance` (`legacy_instance_id`),
  KEY `idx_inventory_items_owner` (`owner_type`,`owner_id`),
  KEY `idx_inventory_items_item` (`item_id`),
  KEY `idx_inventory_items_owner_item` (`owner_type`,`owner_id`,`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.inventory_items: ~0 rows (circa)
DELETE FROM `inventory_items`;

-- Dump della struttura di tabella logeon_db.item_categories
DROP TABLE IF EXISTS `item_categories`;
CREATE TABLE IF NOT EXISTS `item_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_item_categories_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.item_categories: ~0 rows (circa)
DELETE FROM `item_categories`;

-- Dump della struttura di tabella logeon_db.item_equipment_rules
DROP TABLE IF EXISTS `item_equipment_rules`;
CREATE TABLE IF NOT EXISTS `item_equipment_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `slot_id` int(11) NOT NULL,
  `priority` int(11) NOT NULL DEFAULT 10,
  `metadata_json` longtext DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_item_slot_rule` (`item_id`,`slot_id`),
  KEY `idx_item_equipment_rules_item` (`item_id`),
  KEY `idx_item_equipment_rules_slot` (`slot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.item_equipment_rules: ~0 rows (circa)
DELETE FROM `item_equipment_rules`;

-- Dump della struttura di tabella logeon_db.item_rarities
DROP TABLE IF EXISTS `item_rarities`;
CREATE TABLE IF NOT EXISTS `item_rarities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `color_hex` varchar(7) NOT NULL DEFAULT '#6c757d',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_rarity_code_unique` (`code`),
  KEY `idx_item_rarities_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.item_rarities: ~0 rows (circa)
DELETE FROM `item_rarities`;

-- Dump della struttura di tabella logeon_db.items
DROP TABLE IF EXISTS `items`;
CREATE TABLE IF NOT EXISTS `items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) DEFAULT NULL,
  `rarity_id` int(11) DEFAULT NULL,
  `rarity` varchar(50) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(160) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `image` varchar(255) DEFAULT '/assets/imgs/defaults-images/default-location.png',
  `price` int(11) NOT NULL DEFAULT 0,
  `type` varchar(50) DEFAULT NULL,
  `item_kind` varchar(30) DEFAULT NULL,
  `is_stackable` tinyint(1) NOT NULL DEFAULT 1,
  `stackable` tinyint(1) NOT NULL DEFAULT 1,
  `max_stack` int(11) NOT NULL DEFAULT 50,
  `usable` tinyint(1) NOT NULL DEFAULT 0,
  `consumable` tinyint(1) NOT NULL DEFAULT 0,
  `tradable` tinyint(1) NOT NULL DEFAULT 1,
  `droppable` tinyint(1) NOT NULL DEFAULT 1,
  `destroyable` tinyint(1) NOT NULL DEFAULT 1,
  `weight` decimal(10,2) DEFAULT NULL,
  `value` int(11) NOT NULL DEFAULT 0,
  `cooldown` int(11) NOT NULL DEFAULT 0,
  `script_effect` text DEFAULT NULL,
  `applies_state_id` int(11) DEFAULT NULL,
  `removes_state_id` int(11) DEFAULT NULL,
  `state_intensity` decimal(10,2) DEFAULT NULL,
  `state_duration_value` int(11) DEFAULT NULL,
  `state_duration_unit` varchar(20) DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `is_equippable` tinyint(1) NOT NULL DEFAULT 0,
  `equip_slot` varchar(30) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_items_slug` (`slug`),
  KEY `type` (`type`),
  KEY `idx_items_category` (`category_id`),
  KEY `idx_items_rarity` (`rarity_id`),
  KEY `idx_items_applies_state` (`applies_state_id`),
  KEY `idx_items_removes_state` (`removes_state_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.items: ~0 rows (circa)
DELETE FROM `items`;

-- Dump della struttura di tabella logeon_db.job_levels
DROP TABLE IF EXISTS `job_levels`;
CREATE TABLE IF NOT EXISTS `job_levels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `job_id` int(11) NOT NULL,
  `level` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `min_points` int(11) NOT NULL DEFAULT 0,
  `pay_bonus_percent` int(11) NOT NULL DEFAULT 0,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_job_level` (`job_id`,`level`),
  KEY `job_id` (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.job_levels: ~0 rows (circa)
DELETE FROM `job_levels`;

-- Dump della struttura di tabella logeon_db.job_logs
DROP TABLE IF EXISTS `job_logs`;
CREATE TABLE IF NOT EXISTS `job_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `character_id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `choice_id` int(11) NOT NULL,
  `assigned_date` date NOT NULL,
  `pay` int(11) NOT NULL DEFAULT 0,
  `fame` int(11) NOT NULL DEFAULT 0,
  `points` int(11) NOT NULL DEFAULT 0,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `character_id` (`character_id`),
  KEY `job_id` (`job_id`),
  KEY `task_id` (`task_id`),
  KEY `date_created` (`date_created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.job_logs: ~0 rows (circa)
DELETE FROM `job_logs`;

-- Dump della struttura di tabella logeon_db.job_task_choices
DROP TABLE IF EXISTS `job_task_choices`;
CREATE TABLE IF NOT EXISTS `job_task_choices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `choice_code` varchar(8) NOT NULL DEFAULT 'on',
  `label` varchar(100) NOT NULL,
  `pay` int(11) NOT NULL DEFAULT 0,
  `fame` int(11) NOT NULL DEFAULT 0,
  `points` int(11) NOT NULL DEFAULT 0,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `task_id` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.job_task_choices: ~0 rows (circa)
DELETE FROM `job_task_choices`;

-- Dump della struttura di tabella logeon_db.job_tasks
DROP TABLE IF EXISTS `job_tasks`;
CREATE TABLE IF NOT EXISTS `job_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `job_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `body` text DEFAULT NULL,
  `min_level` int(11) NOT NULL DEFAULT 1,
  `requires_location_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `job_id` (`job_id`),
  KEY `requires_location_id` (`requires_location_id`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.job_tasks: ~0 rows (circa)
DELETE FROM `job_tasks`;

-- Dump della struttura di tabella logeon_db.jobs
DROP TABLE IF EXISTS `jobs`;
CREATE TABLE IF NOT EXISTS `jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(255) NOT NULL DEFAULT '/assets/imgs/defaults-images/default-icon.png',
  `location_id` int(11) DEFAULT NULL,
  `min_socialstatus_id` int(11) DEFAULT NULL,
  `base_pay` int(11) NOT NULL DEFAULT 0,
  `daily_tasks` int(11) NOT NULL DEFAULT 2,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `location_id` (`location_id`),
  KEY `min_socialstatus_id` (`min_socialstatus_id`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.jobs: ~0 rows (circa)
DELETE FROM `jobs`;

-- Dump della struttura di tabella logeon_db.lifecycle_phase_definitions
DROP TABLE IF EXISTS `lifecycle_phase_definitions`;
CREATE TABLE IF NOT EXISTS `lifecycle_phase_definitions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(80) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(80) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_initial` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Can be assigned as first phase without prior transition',
  `is_terminal` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'No further transitions allowed from this phase',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `visible_to_players` tinyint(1) NOT NULL DEFAULT 1,
  `color_hex` varchar(7) DEFAULT NULL COMMENT 'UI badge color e.g. #3498db',
  `icon` varchar(255) DEFAULT NULL,
  `meta_json` longtext DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lifecycle_phase_code` (`code`),
  KEY `idx_lifecycle_phase_active` (`is_active`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.lifecycle_phase_definitions: ~5 rows (circa)
DELETE FROM `lifecycle_phase_definitions`;
INSERT INTO `lifecycle_phase_definitions` (`id`, `code`, `name`, `description`, `category`, `sort_order`, `is_initial`, `is_terminal`, `is_active`, `visible_to_players`, `color_hex`, `icon`, `meta_json`, `date_created`, `date_updated`) VALUES
	(1, 'newcomer', 'Nuovo arrivato', 'Personaggio appena introdotto nel mondo narrativo.', 'Inizio', 10, 1, 0, 1, 1, '#6c757d', NULL, NULL, '2026-03-18 18:01:05', NULL),
	(2, 'established', 'Stabilito', 'Personaggio con una presenza consolidata nella narrativa.', 'Sviluppo', 20, 0, 0, 1, 1, '#0d6efd', NULL, NULL, '2026-03-18 18:01:05', NULL),
	(3, 'veteran', 'Veterano', 'Personaggio con un percorso narrativo ricco e riconosciuto.', 'Sviluppo', 30, 0, 0, 1, 1, '#198754', NULL, NULL, '2026-03-18 18:01:05', NULL),
	(4, 'retired', 'In pensione', 'Personaggio non piu attivo nella narrativa principale.', 'Chiusura', 90, 0, 1, 1, 1, '#ffc107', NULL, NULL, '2026-03-18 18:01:05', NULL),
	(5, 'deceased', 'Deceduto', 'Personaggio uscito definitivamente dalla narrativa.', 'Chiusura', 100, 0, 1, 1, 1, '#343a40', NULL, NULL, '2026-03-18 18:01:05', NULL);

-- Dump della struttura di tabella logeon_db.location_access_logs
DROP TABLE IF EXISTS `location_access_logs`;
CREATE TABLE IF NOT EXISTS `location_access_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `character_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `allowed` tinyint(1) NOT NULL DEFAULT 0,
  `reason_code` varchar(32) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_character_date` (`character_id`,`date_created`),
  KEY `idx_location_date` (`location_id`,`date_created`),
  KEY `idx_allowed` (`allowed`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.location_access_logs: ~3 rows (circa)
DELETE FROM `location_access_logs`;
INSERT INTO `location_access_logs` (`id`, `character_id`, `location_id`, `allowed`, `reason_code`, `reason`, `date_created`) VALUES
	(1, 1, 1, 1, 'ok', NULL, '2026-04-26 17:42:00'),
	(2, 1, 1, 1, 'ok', NULL, '2026-04-26 17:43:01'),
	(3, 1, 1, 1, 'ok', NULL, '2026-04-26 17:44:01'),
	(4, 1, 1, 1, 'ok', NULL, '2026-04-26 17:45:01'),
	(5, 1, 1, 1, 'ok', NULL, '2026-04-26 17:46:01');

-- Dump della struttura di tabella logeon_db.location_invites
DROP TABLE IF EXISTS `location_invites`;
CREATE TABLE IF NOT EXISTS `location_invites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `location_id` int(11) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `invited_id` int(11) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `owner_notified` tinyint(1) NOT NULL DEFAULT 0,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  `date_responded` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_location_invited` (`location_id`,`invited_id`),
  KEY `idx_invited_status` (`invited_id`,`status`),
  KEY `idx_owner_status` (`owner_id`,`status`),
  KEY `idx_owner_notified` (`owner_id`,`owner_notified`),
  KEY `idx_invited_status_expires` (`invited_id`,`status`,`expires_at`),
  KEY `idx_owner_status_expires` (`owner_id`,`status`,`expires_at`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dump dei dati della tabella logeon_db.location_invites: ~0 rows (circa)
DELETE FROM `location_invites`;

-- Dump della struttura di tabella logeon_db.location_item_drops
DROP TABLE IF EXISTS `location_item_drops`;
CREATE TABLE IF NOT EXISTS `location_item_drops` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `location_id` int(11) NOT NULL,
  `dropped_by` int(11) DEFAULT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `is_stackable` tinyint(1) NOT NULL DEFAULT 1,
  `durability` int(11) DEFAULT NULL,
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`)),
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `location_id` (`location_id`),
  KEY `item_id` (`item_id`),
  KEY `location_item` (`location_id`,`item_id`),
  KEY `location_stackable` (`location_id`,`is_stackable`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.location_item_drops: ~0 rows (circa)
DELETE FROM `location_item_drops`;

-- Dump della struttura di tabella logeon_db.location_position_tags
DROP TABLE IF EXISTS `location_position_tags`;
CREATE TABLE IF NOT EXISTS `location_position_tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `location_id` int(11) NOT NULL,
  `name` varchar(80) NOT NULL,
  `short_description` varchar(255) DEFAULT NULL,
  `thumbnail` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lpt_location_active` (`location_id`,`is_active`),
  CONSTRAINT `fk_lpt_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.location_position_tags: ~0 rows (circa)
DELETE FROM `location_position_tags`;

-- Dump della struttura di tabella logeon_db.location_weather_overrides
DROP TABLE IF EXISTS `location_weather_overrides`;
CREATE TABLE IF NOT EXISTS `location_weather_overrides` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `location_id` int(11) NOT NULL,
  `weather_key` varchar(32) DEFAULT NULL,
  `degrees` int(11) DEFAULT NULL,
  `moon_phase` varchar(32) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_location_weather_overrides_location` (`location_id`),
  KEY `idx_location_weather_overrides_updated_by` (`updated_by`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.location_weather_overrides: ~0 rows (circa)
DELETE FROM `location_weather_overrides`;

-- Dump della struttura di tabella logeon_db.location_whisper_reads
DROP TABLE IF EXISTS `location_whisper_reads`;
CREATE TABLE IF NOT EXISTS `location_whisper_reads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `location_id` int(11) NOT NULL,
  `reader_id` int(11) NOT NULL,
  `other_character_id` int(11) NOT NULL,
  `last_read_message_id` int(11) NOT NULL DEFAULT 0,
  `date_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_location_reader_other` (`location_id`,`reader_id`,`other_character_id`),
  KEY `idx_whisper_reads_reader_location` (`reader_id`,`location_id`),
  KEY `idx_whisper_reads_other` (`other_character_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.location_whisper_reads: ~0 rows (circa)
DELETE FROM `location_whisper_reads`;

-- Dump della struttura di tabella logeon_db.locations
DROP TABLE IF EXISTS `locations`;
CREATE TABLE IF NOT EXISTS `locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `climate_area_id` int(11) DEFAULT NULL,
  `map_id` int(11) NOT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `short_description` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` text DEFAULT NULL,
  `page` varchar(255) DEFAULT NULL,
  `map_link` int(11) DEFAULT NULL,
  `map_x` decimal(6,2) DEFAULT NULL,
  `map_y` decimal(6,2) DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `image` varchar(255) DEFAULT '/assets/imgs/defaults-images/default-location.png',
  `guests` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`guests`)),
  `booking` timestamp NULL DEFAULT NULL,
  `deadline` timestamp NULL DEFAULT NULL,
  `cost` int(11) DEFAULT NULL,
  `min_fame` int(11) DEFAULT NULL,
  `min_socialstatus_id` int(11) DEFAULT NULL,
  `is_chat` tinyint(1) NOT NULL DEFAULT 0,
  `is_private` tinyint(1) NOT NULL DEFAULT 0,
  `is_house` tinyint(1) NOT NULL DEFAULT 0,
  `chat_type` varchar(16) DEFAULT NULL,
  `access_policy` varchar(16) DEFAULT NULL,
  `max_guests` int(11) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `date_deleted` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `LOCATION_NAME_UNIQUE` (`name`),
  KEY `mapID` (`map_id`),
  KEY `ownerID` (`owner_id`),
  KEY `idx_locations_map_active` (`map_id`,`date_deleted`),
  KEY `idx_locations_access` (`is_private`,`is_house`,`min_fame`,`min_socialstatus_id`),
  KEY `idx_locations_climate_area_id` (`climate_area_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.locations: ~1 rows (circa)
DELETE FROM `locations`;
INSERT INTO `locations` (`id`, `climate_area_id`, `map_id`, `owner_id`, `name`, `short_description`, `description`, `status`, `page`, `map_link`, `map_x`, `map_y`, `icon`, `image`, `guests`, `booking`, `deadline`, `cost`, `min_fame`, `min_socialstatus_id`, `is_chat`, `is_private`, `is_house`, `chat_type`, `access_policy`, `max_guests`, `date_created`, `date_updated`, `date_deleted`) VALUES
	(1, NULL, 1, NULL, 'Piazza', 'La piazza della città', NULL, 'open', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, 1, 0, 0, 'standard', 'open', NULL, '2026-04-15 11:00:48', '2026-04-17 19:25:25', NULL);

-- Dump della struttura di tabella logeon_db.locations_messages
DROP TABLE IF EXISTS `locations_messages`;
CREATE TABLE IF NOT EXISTS `locations_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `location_id` int(11) NOT NULL,
  `character_id` int(11) NOT NULL,
  `recipient_id` int(11) DEFAULT NULL,
  `type` tinyint(1) NOT NULL,
  `tag_position` varchar(255) DEFAULT NULL,
  `body` text NOT NULL,
  `meta_json` longtext DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `location_tag_id` int(11) DEFAULT NULL,
  `location_tag_label` varchar(80) DEFAULT NULL,
  `location_tag_detail` varchar(120) DEFAULT NULL,
  `location_tag_display` varchar(220) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_locations_messages_location` (`location_id`,`id`),
  KEY `idx_locations_messages_date` (`location_id`,`date_created`),
  KEY `idx_locations_messages_recipient` (`recipient_id`,`date_created`),
  KEY `idx_locations_messages_location_type_id` (`location_id`,`type`,`id`),
  KEY `idx_locations_messages_whisper_pair` (`location_id`,`type`,`character_id`,`recipient_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.locations_messages: ~0 rows (circa)
DELETE FROM `locations_messages`;

-- Dump della struttura di tabella logeon_db.maps
DROP TABLE IF EXISTS `maps`;
CREATE TABLE IF NOT EXISTS `maps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` mediumtext DEFAULT NULL,
  `status` text DEFAULT NULL,
  `initial` tinyint(1) NOT NULL,
  `position` int(11) NOT NULL,
  `mobile` tinyint(1) DEFAULT NULL,
  `width` smallint(6) DEFAULT NULL,
  `height` smallint(6) DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `render_mode` varchar(16) DEFAULT NULL,
  `meteo` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `MAP_NAME_UNIQUE` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.maps: ~0 rows (circa)
DELETE FROM `maps`;
INSERT INTO `maps` (`id`, `name`, `description`, `status`, `initial`, `position`, `mobile`, `width`, `height`, `icon`, `image`, `render_mode`, `meteo`) VALUES
	(1, 'Città', NULL, 'active', 1, 1, 0, NULL, NULL, NULL, NULL, 'grid', NULL);

-- Dump della struttura di tabella logeon_db.message_reports
DROP TABLE IF EXISTS `message_reports`;
CREATE TABLE IF NOT EXISTS `message_reports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `reported_message_id` int(10) unsigned NOT NULL,
  `reported_message_author_character_id` int(10) unsigned DEFAULT NULL,
  `reported_message_author_user_id` int(10) unsigned DEFAULT NULL,
  `reporter_character_id` int(10) unsigned NOT NULL,
  `reporter_user_id` int(10) unsigned NOT NULL,
  `location_id` int(10) unsigned DEFAULT NULL,
  `reason_code` varchar(60) NOT NULL,
  `reason_text` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `priority` varchar(10) NOT NULL DEFAULT 'low',
  `assigned_to_user_id` int(10) unsigned DEFAULT NULL,
  `reviewed_by_user_id` int(10) unsigned DEFAULT NULL,
  `review_note` text DEFAULT NULL,
  `resolution_code` varchar(60) DEFAULT NULL,
  `message_snapshot_text` text DEFAULT NULL,
  `message_snapshot_meta_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mr_message` (`reported_message_id`),
  KEY `idx_mr_reporter` (`reporter_user_id`),
  KEY `idx_mr_author` (`reported_message_author_user_id`),
  KEY `idx_mr_status` (`status`),
  KEY `idx_mr_status_date` (`status`,`created_at`),
  KEY `idx_mr_location` (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.message_reports: ~0 rows (circa)
DELETE FROM `message_reports`;

-- Dump della struttura di tabella logeon_db.messages
DROP TABLE IF EXISTS `messages`;
CREATE TABLE IF NOT EXISTS `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `thread_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `recipient_id` int(11) NOT NULL,
  `body` text NOT NULL,
  `message_type` enum('on','off') NOT NULL DEFAULT 'on',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `thread_id` (`thread_id`),
  KEY `sender_id` (`sender_id`),
  KEY `recipient_id` (`recipient_id`),
  KEY `idx_unread` (`recipient_id`,`is_read`),
  KEY `idx_thread_id` (`thread_id`,`id`),
  KEY `idx_thread_recipient_read` (`thread_id`,`recipient_id`,`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.messages: ~0 rows (circa)
DELETE FROM `messages`;

-- Dump della struttura di tabella logeon_db.messages_threads
DROP TABLE IF EXISTS `messages_threads`;
CREATE TABLE IF NOT EXISTS `messages_threads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `character_one` int(11) NOT NULL,
  `character_two` int(11) NOT NULL,
  `last_message_body` text DEFAULT NULL,
  `last_message_type` enum('on','off') NOT NULL DEFAULT 'on',
  `last_sender_id` int(11) DEFAULT NULL,
  `date_last_message` timestamp NULL DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `subject` varchar(150) DEFAULT NULL,
  `thread_type` enum('on','off') NOT NULL DEFAULT 'on',
  `deleted_for_one` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_for_two` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `character_one` (`character_one`),
  KEY `character_two` (`character_two`),
  KEY `date_last_message` (`date_last_message`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.messages_threads: ~0 rows (circa)
DELETE FROM `messages_threads`;

-- Dump della struttura di tabella logeon_db.module_runtime_artifacts
DROP TABLE IF EXISTS `module_runtime_artifacts`;
CREATE TABLE IF NOT EXISTS `module_runtime_artifacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module_id` varchar(120) NOT NULL,
  `artifact_type` varchar(60) NOT NULL,
  `artifact_key` varchar(255) NOT NULL,
  `artifact_scope` varchar(80) DEFAULT NULL,
  `artifact_payload` longtext DEFAULT NULL,
  `checksum_sha1` char(40) DEFAULT NULL,
  `date_seen` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_module_artifact` (`module_id`,`artifact_type`,`artifact_key`),
  KEY `idx_module_artifact_module` (`module_id`),
  KEY `idx_module_artifact_scope` (`artifact_scope`)
) ENGINE=InnoDB AUTO_INCREMENT=641 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.module_runtime_artifacts: ~40 rows (circa)
DELETE FROM `module_runtime_artifacts`;
INSERT INTO `module_runtime_artifacts` (`id`, `module_id`, `artifact_type`, `artifact_key`, `artifact_scope`, `artifact_payload`, `checksum_sha1`, `date_seen`, `date_created`) VALUES
	(1, 'logeon.archetypes', 'entrypoint', 'bootstrap:bootstrap.php', 'runtime', '{"module_id":"logeon.archetypes","entrypoint":"bootstrap","path":"bootstrap.php"}', '992d7ebf88be096bae1d22549eb9fac8a6d4b3b2', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(2, 'logeon.archetypes', 'entrypoint', 'routes:routes.php', 'runtime', '{"module_id":"logeon.archetypes","entrypoint":"routes","path":"routes.php"}', '48d3dae669f049eacd2b24aa3f05a7946a6c5086', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(3, 'logeon.archetypes', 'asset_js', 'game:dist/game.js', 'game', '{"module_id":"logeon.archetypes","channel":"game","asset_type":"js","path":"dist/game.js"}', '78a1de3658ba812b7934b465f225066a29dde4a4', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(4, 'logeon.archetypes', 'asset_js', 'admin:dist/admin.js', 'admin', '{"module_id":"logeon.archetypes","channel":"admin","asset_type":"js","path":"dist/admin.js"}', '1750559bfcecfe0e8a019aa16386a78885d941eb', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(5, 'logeon.archetypes', 'menu_entry', 'game:info_dropdown:/game/archetypes', 'game', '{"module_id":"logeon.archetypes","channel":"game","slot":"info_dropdown","label":"Archetipi","href":"/game/archetypes","page":""}', 'edf686b1512e33a9096972f27d2e10d0e983c724', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(6, 'logeon.archetypes', 'menu_entry', 'game:info_offcanvas:/game/archetypes', 'game', '{"module_id":"logeon.archetypes","channel":"game","slot":"info_offcanvas","label":"Archetipi","href":"/game/archetypes","page":""}', '549bbda3397e56b73f5e5b5adf2f8a1ffa0ea7a2', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(7, 'logeon.archetypes', 'menu_entry', 'admin:aside:/admin/archetypes', 'admin', '{"module_id":"logeon.archetypes","channel":"admin","slot":"aside","label":"Archetipi","href":"/admin/archetypes","page":"archetypes"}', 'dada422b69258f15afb1b1d082846fa08bfd26d0', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(8, 'logeon.archetypes', 'menu_entry', 'public:navbar:/archetypes', 'public', '{"module_id":"logeon.archetypes","channel":"public","slot":"navbar","label":"Archetipi","href":"/archetypes","page":""}', '3a7590f5c9f61f1cae200e0f611907507fbf34ac', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(9, 'logeon.attributes', 'entrypoint', 'bootstrap:bootstrap.php', 'runtime', '{"module_id":"logeon.attributes","entrypoint":"bootstrap","path":"bootstrap.php"}', 'fc2670a48a7e4d41078ab9569bf98fa6d7a7717b', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(10, 'logeon.attributes', 'entrypoint', 'routes:routes.php', 'runtime', '{"module_id":"logeon.attributes","entrypoint":"routes","path":"routes.php"}', 'c82d524048522676194cd608d04db658aa2fa2b6', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(11, 'logeon.attributes', 'asset_js', 'admin:dist/admin.js', 'admin', '{"module_id":"logeon.attributes","channel":"admin","asset_type":"js","path":"dist/admin.js"}', 'df39b31105ac47569ceb1a846ea970015efe19bb', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(12, 'logeon.attributes', 'menu_entry', 'admin:aside:/admin/character-attributes', 'admin', '{"module_id":"logeon.attributes","channel":"admin","slot":"aside","label":"Attributi personaggio","href":"/admin/character-attributes","page":"character-attributes"}', '06fb3e0fc74bf2ce4dc7d734ca9fda3c98fddf7d', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(13, 'logeon.factions', 'entrypoint', 'bootstrap:bootstrap.php', 'runtime', '{"module_id":"logeon.factions","entrypoint":"bootstrap","path":"bootstrap.php"}', 'ef08100dc614f274a77508b4c0172d397738dcb8', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(14, 'logeon.factions', 'entrypoint', 'routes:routes.php', 'runtime', '{"module_id":"logeon.factions","entrypoint":"routes","path":"routes.php"}', 'f786775211b2a3c3502fe5ae1ad67582eaba8f36', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(15, 'logeon.factions', 'asset_js', 'game:dist/game.js', 'game', '{"module_id":"logeon.factions","channel":"game","asset_type":"js","path":"dist/game.js"}', '7df464d31c7e15b1fa9be18a5cefe50a67993632', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(16, 'logeon.factions', 'asset_js', 'admin:dist/admin.js', 'admin', '{"module_id":"logeon.factions","channel":"admin","asset_type":"js","path":"dist/admin.js"}', 'd3ee28908e8a341d3b59ad393d9f1838e1e402b7', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(17, 'logeon.factions', 'menu_entry', 'game:organizations_dropdown:/game/factions', 'game', '{"module_id":"logeon.factions","channel":"game","slot":"organizations_dropdown","label":"Fazioni","href":"/game/factions","page":""}', 'bc84313828476bd4f7119065cabbff1226b946d0', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(18, 'logeon.factions', 'menu_entry', 'game:organizations_offcanvas:/game/factions', 'game', '{"module_id":"logeon.factions","channel":"game","slot":"organizations_offcanvas","label":"Fazioni","href":"/game/factions","page":""}', '48b06677d64ca53d0d4089769661fc7cedb7cece', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(19, 'logeon.factions', 'menu_entry', 'admin:aside:/admin/factions', 'admin', '{"module_id":"logeon.factions","channel":"admin","slot":"aside","label":"Fazioni","href":"/admin/factions","page":"factions"}', '34929096de1e4f494a99d1eeb32ce62740891cfa', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(20, 'logeon.multi-currency', 'entrypoint', 'bootstrap:bootstrap.php', 'runtime', '{"module_id":"logeon.multi-currency","entrypoint":"bootstrap","path":"bootstrap.php"}', '21fba6a104d704d0eda78b2fcdb7abc6d3de710a', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(21, 'logeon.multi-currency', 'entrypoint', 'routes:routes.php', 'runtime', '{"module_id":"logeon.multi-currency","entrypoint":"routes","path":"routes.php"}', '6e23e78200b4b0b6ad6b9a91424b896fb6573dba', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(22, 'logeon.novelty', 'entrypoint', 'bootstrap:bootstrap.php', 'runtime', '{"module_id":"logeon.novelty","entrypoint":"bootstrap","path":"bootstrap.php"}', '70b81abc08ff4cc5ff50698682fbe44f120851b3', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(23, 'logeon.novelty', 'entrypoint', 'routes:routes.php', 'runtime', '{"module_id":"logeon.novelty","entrypoint":"routes","path":"routes.php"}', 'f282886e55481c7bd7800d2a3e3c619278341a12', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(24, 'logeon.novelty', 'menu_entry', 'admin:aside:/admin/news', 'admin', '{"module_id":"logeon.novelty","channel":"admin","slot":"aside","label":"News","href":"/admin/news","page":"news"}', 'd0b12a5c8d831985de8fb8301c0520df25c8ada2', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(25, 'logeon.quests', 'entrypoint', 'bootstrap:bootstrap.php', 'runtime', '{"module_id":"logeon.quests","entrypoint":"bootstrap","path":"bootstrap.php"}', 'ee4e9cfe708b84262ce8758b7c7eaa8eff433825', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(26, 'logeon.quests', 'entrypoint', 'routes:routes.php', 'runtime', '{"module_id":"logeon.quests","entrypoint":"routes","path":"routes.php"}', '36c5cd1d489e58f3d53ec0ca66277c3616850f83', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(27, 'logeon.quests', 'asset_js', 'game:dist/game.js', 'game', '{"module_id":"logeon.quests","channel":"game","asset_type":"js","path":"dist/game.js"}', '59b21927c55f99a1e859e33d7eecd1047e0823ce', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(28, 'logeon.quests', 'asset_js', 'admin:dist/admin.js', 'admin', '{"module_id":"logeon.quests","channel":"admin","asset_type":"js","path":"dist/admin.js"}', 'd7ba4a23787b174ba61594ebd998407c733f9f48', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(29, 'logeon.quests', 'menu_entry', 'admin:aside:/admin/quests', 'admin', '{"module_id":"logeon.quests","channel":"admin","slot":"aside","label":"Quest","href":"/admin/quests","page":"quests"}', 'af9104e71ec40a3a6a2c5ea9699432e90753ef7d', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(30, 'logeon.social-status', 'entrypoint', 'bootstrap:bootstrap.php', 'runtime', '{"module_id":"logeon.social-status","entrypoint":"bootstrap","path":"bootstrap.php"}', '47c35cdd1e6551261867ee4118237465d974bdeb', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(31, 'logeon.social-status', 'entrypoint', 'routes:routes.php', 'runtime', '{"module_id":"logeon.social-status","entrypoint":"routes","path":"routes.php"}', 'ea0fd237e7714221bcac78edafa17eebb1369450', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(32, 'logeon.social-status', 'asset_js', 'admin:dist/admin.js', 'admin', '{"module_id":"logeon.social-status","channel":"admin","asset_type":"js","path":"dist/admin.js"}', '9c0a51b22cf2705e8ed89235c5e1a7f352742e14', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(33, 'logeon.social-status', 'menu_entry', 'admin:aside:/admin/social-status', 'admin', '{"module_id":"logeon.social-status","channel":"admin","slot":"aside","label":"Stati sociali","href":"/admin/social-status","page":"social-status"}', 'e155e6b577849fe3c14de0e051da310aa3ea4d6d', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(34, 'logeon.weather', 'entrypoint', 'bootstrap:bootstrap.php', 'runtime', '{"module_id":"logeon.weather","entrypoint":"bootstrap","path":"bootstrap.php"}', 'f5d20bcd0a0799e7d8c1a9346e0f65baab50356f', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(35, 'logeon.weather', 'entrypoint', 'routes:routes.php', 'runtime', '{"module_id":"logeon.weather","entrypoint":"routes","path":"routes.php"}', '5103e555ba03d1c65b2630bb9c189eb9d3798dc9', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(36, 'logeon.weather', 'asset_js', 'admin:dist/admin.js', 'admin', '{"module_id":"logeon.weather","channel":"admin","asset_type":"js","path":"dist/admin.js"}', '61a6007e92364950214fc565ad8b4b77e11a2395', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(37, 'logeon.weather', 'menu_entry', 'admin:aside:/admin/weather-overview', 'admin', '{"module_id":"logeon.weather","channel":"admin","slot":"aside","label":"Panoramica meteo","href":"/admin/weather-overview","page":"weather-overview"}', '76a2a1f0232db506e34c8ce582c595a94da46e3d', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(38, 'logeon.weather', 'menu_entry', 'admin:aside:/admin/weather-catalogs', 'admin', '{"module_id":"logeon.weather","channel":"admin","slot":"aside","label":"Cataloghi meteo","href":"/admin/weather-catalogs","page":"weather-catalogs"}', 'b93352abc924887133b34d0aff5ac470e334f3fe', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(39, 'logeon.weather', 'menu_entry', 'admin:aside:/admin/weather-profiles', 'admin', '{"module_id":"logeon.weather","channel":"admin","slot":"aside","label":"Profili e assegnazioni","href":"/admin/weather-profiles","page":"weather-profiles"}', '1201848eb95c211271727ca8091495f055eb121a', '2026-04-26 15:46:01', '2026-04-26 15:43:00'),
	(40, 'logeon.weather', 'menu_entry', 'admin:aside:/admin/weather-overrides', 'admin', '{"module_id":"logeon.weather","channel":"admin","slot":"aside","label":"Override e legacy","href":"/admin/weather-overrides","page":"weather-overrides"}', '4c643dd64b2040e2cf52218274b57e4d6042c7cb', '2026-04-26 15:46:01', '2026-04-26 15:43:00');

-- Dump della struttura di tabella logeon_db.narrative_capabilities
DROP TABLE IF EXISTS `narrative_capabilities`;
CREATE TABLE IF NOT EXISTS `narrative_capabilities` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL COMMENT 'capability identifier, e.g. narrative.event.create',
  `label` varchar(255) NOT NULL DEFAULT '',
  `max_impact_allowed` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0=zero, 1=limited, 2=high — ceiling for what this capability can ever be granted at',
  `staff_only` tinyint(1) NOT NULL DEFAULT 0,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_capability_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.narrative_capabilities: ~6 rows (circa)
DELETE FROM `narrative_capabilities`;
INSERT INTO `narrative_capabilities` (`id`, `name`, `label`, `max_impact_allowed`, `staff_only`, `date_created`) VALUES
	(1, 'narrative.event.create', 'Crea evento narrativo', 1, 0, '2026-04-14 17:44:50'),
	(2, 'narrative.scene.manage', 'Gestisci scena narrativa', 1, 0, '2026-04-14 17:44:50'),
	(3, 'narrative.npc.spawn', 'Inserisci PNG di scena', 1, 0, '2026-04-14 17:44:50'),
	(4, 'narrative.message.emit', 'Invia messaggio narrativo', 0, 0, '2026-04-14 17:44:50'),
	(5, 'narrative.outcome.execute', 'Esegui esito narrativo', 2, 0, '2026-04-14 17:44:50'),
	(6, 'narrative.reward.assign', 'Assegna ricompense narrative', 2, 1, '2026-04-14 17:44:50');

-- Dump della struttura di tabella logeon_db.narrative_capability_grants
DROP TABLE IF EXISTS `narrative_capability_grants`;
CREATE TABLE IF NOT EXISTS `narrative_capability_grants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `grantee_type` enum('guild_role','faction_role','user_role') NOT NULL,
  `grantee_ref` varchar(120) NOT NULL COMMENT 'symbolic role name',
  `capability` varchar(120) NOT NULL COMMENT 'references narrative_capabilities.name',
  `max_impact_level` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0=zero, 1=limited, 2=high — ceiling for this specific grant',
  `scope_restriction` varchar(60) DEFAULT NULL COMMENT 'optional: restrict to scope (guild, faction, local…). NULL = no restriction',
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grant` (`grantee_type`,`grantee_ref`,`capability`),
  KEY `idx_capability` (`capability`),
  KEY `idx_grantee` (`grantee_type`,`grantee_ref`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.narrative_capability_grants: ~10 rows (circa)
DELETE FROM `narrative_capability_grants`;
INSERT INTO `narrative_capability_grants` (`id`, `grantee_type`, `grantee_ref`, `capability`, `max_impact_level`, `scope_restriction`, `date_created`) VALUES
	(1, 'guild_role', 'leader', 'narrative.event.create', 0, NULL, '2026-04-14 17:45:39'),
	(2, 'guild_role', 'leader', 'narrative.scene.manage', 0, NULL, '2026-04-14 17:45:39'),
	(3, 'guild_role', 'leader', 'narrative.message.emit', 0, NULL, '2026-04-14 17:45:39'),
	(4, 'guild_role', 'leader', 'narrative.npc.spawn', 0, NULL, '2026-04-14 17:45:39'),
	(5, 'guild_role', 'officer', 'narrative.message.emit', 0, NULL, '2026-04-14 17:45:39'),
	(6, 'faction_role', 'leader', 'narrative.event.create', 0, NULL, '2026-04-14 17:45:39'),
	(7, 'faction_role', 'leader', 'narrative.scene.manage', 0, NULL, '2026-04-14 17:45:39'),
	(8, 'faction_role', 'leader', 'narrative.message.emit', 0, NULL, '2026-04-14 17:45:39'),
	(9, 'faction_role', 'leader', 'narrative.npc.spawn', 0, NULL, '2026-04-14 17:45:39'),
	(10, 'faction_role', 'advisor', 'narrative.message.emit', 0, NULL, '2026-04-14 17:45:39');

-- Dump della struttura di tabella logeon_db.narrative_ephemeral_npcs
DROP TABLE IF EXISTS `narrative_ephemeral_npcs`;
CREATE TABLE IF NOT EXISTS `narrative_ephemeral_npcs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(500) DEFAULT NULL,
  `location_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ephemeral_npcs_event` (`event_id`),
  KEY `idx_ephemeral_npcs_location` (`location_id`),
  CONSTRAINT `fk_ephemeral_npcs_event` FOREIGN KEY (`event_id`) REFERENCES `narrative_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.narrative_ephemeral_npcs: ~0 rows (circa)
DELETE FROM `narrative_ephemeral_npcs`;

-- Dump della struttura di tabella logeon_db.narrative_events
DROP TABLE IF EXISTS `narrative_events`;
CREATE TABLE IF NOT EXISTS `narrative_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `event_type` varchar(80) NOT NULL DEFAULT 'manual',
  `event_mode` enum('point','scene') NOT NULL DEFAULT 'point',
  `status` enum('open','closed') NOT NULL DEFAULT 'closed',
  `closed_at` datetime DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `scope` enum('local','regional','global','guild','faction') NOT NULL DEFAULT 'local',
  `impact_level` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0=zero impact, 1=limited, 2=high (staff only)',
  `description` text DEFAULT NULL,
  `entity_refs` longtext DEFAULT NULL COMMENT 'JSON array: [{entity_type, entity_id, role}]',
  `location_id` int(11) DEFAULT NULL,
  `visibility` enum('public','private','staff_only','hidden') NOT NULL DEFAULT 'public',
  `tags` varchar(255) DEFAULT NULL,
  `source_system` varchar(80) DEFAULT NULL COMMENT 'combat|conflict|manual|lifecycle|etc.',
  `source_ref_id` int(11) DEFAULT NULL COMMENT 'PK of the originating record in source_system',
  `meta_json` longtext DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'character_id of staff who created it manually',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_narrative_events_type` (`event_type`),
  KEY `idx_narrative_events_mode_status` (`event_mode`,`status`),
  KEY `idx_narrative_events_scope` (`scope`),
  KEY `idx_narrative_events_visibility` (`visibility`),
  KEY `idx_narrative_events_location` (`location_id`),
  KEY `idx_narrative_events_source` (`source_system`,`source_ref_id`),
  KEY `idx_narrative_events_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.narrative_events: ~0 rows (circa)
DELETE FROM `narrative_events`;

-- Dump della struttura di tabella logeon_db.narrative_npcs
DROP TABLE IF EXISTS `narrative_npcs`;
CREATE TABLE IF NOT EXISTS `narrative_npcs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(512) DEFAULT NULL,
  `group_type` enum('guild','faction','none') NOT NULL DEFAULT 'none',
  `group_id` int(10) unsigned DEFAULT NULL COMMENT 'FK a guilds.id o factions.id in base a group_type',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL COMMENT 'character_id del creatore',
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_group` (`group_type`,`group_id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.narrative_npcs: ~0 rows (circa)
DELETE FROM `narrative_npcs`;

-- Dump della struttura di tabella logeon_db.narrative_states
DROP TABLE IF EXISTS `narrative_states`;
CREATE TABLE IF NOT EXISTS `narrative_states` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(120) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(80) DEFAULT NULL,
  `scope` enum('character','scene','both') NOT NULL DEFAULT 'character',
  `stack_mode` enum('replace','stack','refresh') NOT NULL DEFAULT 'replace',
  `max_stacks` int(11) NOT NULL DEFAULT 1,
  `conflict_group` varchar(80) DEFAULT NULL,
  `priority` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `visible_to_players` tinyint(1) NOT NULL DEFAULT 1,
  `metadata_json` longtext DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_narrative_states_code` (`code`),
  KEY `idx_narrative_states_active` (`is_active`,`visible_to_players`),
  KEY `idx_narrative_states_conflict` (`conflict_group`,`priority`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.narrative_states: ~8 rows (circa)
DELETE FROM `narrative_states`;
INSERT INTO `narrative_states` (`id`, `code`, `name`, `description`, `category`, `scope`, `stack_mode`, `max_stacks`, `conflict_group`, `priority`, `is_active`, `visible_to_players`, `metadata_json`, `date_created`, `date_updated`) VALUES
	(1, 'stance_guard', 'Postura - Guardia', 'Postura difensiva che privilegia protezione e controllo.', 'Postura', 'character', 'replace', 1, 'stance', 20, 1, 1, '{}', '2026-03-30 15:32:14', NULL),
	(2, 'stance_assault', 'Postura - Assalto', 'Postura aggressiva che privilegia iniziativa e pressione.', 'Postura', 'character', 'replace', 1, 'stance', 20, 1, 1, '{}', '2026-03-30 15:32:14', NULL),
	(3, 'pressure_rising', 'Pressione crescente', 'La tensione narrativa cresce e condiziona la scena.', 'Pressione', 'scene', 'refresh', 1, 'scene_pressure', 10, 1, 1, '{}', '2026-03-30 15:32:14', NULL),
	(4, 'movement_hindered', 'Movimento ostacolato', 'Il personaggio ha il movimento limitato nella scena.', 'Movimento', 'character', 'refresh', 1, 'mobility', 15, 1, 1, '{}', '2026-03-30 15:32:14', NULL),
	(5, 'perception_focused', 'Percezione focalizzata', 'Focus sui dettagli ambientali e segnali narrativi.', 'Percezione', 'character', 'refresh', 1, 'perception', 15, 1, 1, '{}', '2026-03-30 15:32:14', NULL),
	(6, 'focus_broken', 'Focus spezzato', 'Interruzione della concentrazione con perdita di precisione narrativa.', 'Focus', 'character', 'replace', 1, 'focus', 20, 1, 1, '{}', '2026-03-30 15:32:14', NULL),
	(7, 'scene_fog', 'Nebbia di scena', 'Visibilita ridotta e percezione ambientale alterata.', 'Stati di scena', 'scene', 'refresh', 1, 'scene_visibility', 12, 1, 1, '{}', '2026-03-30 15:32:14', NULL),
	(8, 'scene_darkness', 'Oscurita di scena', 'Illuminazione minima con forte impatto narrativo sulle azioni.', 'Stati di scena', 'scene', 'refresh', 1, 'scene_visibility', 14, 1, 1, '{}', '2026-03-30 15:32:14', NULL);

-- Dump della struttura di tabella logeon_db.narrative_tag_assignments
DROP TABLE IF EXISTS `narrative_tag_assignments`;
CREATE TABLE IF NOT EXISTS `narrative_tag_assignments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tag_id` int(10) unsigned NOT NULL,
  `entity_type` varchar(40) NOT NULL,
  `entity_id` int(10) unsigned NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tag_assignment` (`tag_id`,`entity_type`,`entity_id`),
  KEY `idx_tag_assignments_entity` (`entity_type`,`entity_id`),
  KEY `idx_tag_assignments_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.narrative_tag_assignments: ~0 rows (circa)
DELETE FROM `narrative_tag_assignments`;

-- Dump della struttura di tabella logeon_db.narrative_tags
DROP TABLE IF EXISTS `narrative_tags`;
CREATE TABLE IF NOT EXISTS `narrative_tags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(80) NOT NULL,
  `label` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `category` varchar(60) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `date_updated` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_narrative_tags_slug` (`slug`),
  KEY `idx_narrative_tags_active` (`is_active`),
  KEY `idx_narrative_tags_category` (`category`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.narrative_tags: ~18 rows (circa)
DELETE FROM `narrative_tags`;
INSERT INTO `narrative_tags` (`id`, `slug`, `label`, `description`, `category`, `is_active`, `created_by`, `updated_by`, `date_created`, `date_updated`) VALUES
	(1, 'political', 'Politico', NULL, 'tono', 1, NULL, NULL, '2026-03-25 11:26:59', '2026-03-25 11:26:59'),
	(2, 'mystery', 'Mistero', NULL, 'tono', 1, NULL, NULL, '2026-03-25 11:26:59', '2026-03-25 11:26:59'),
	(3, 'war', 'Guerra', NULL, 'tono', 1, NULL, NULL, '2026-03-25 11:26:59', '2026-03-25 11:26:59'),
	(4, 'diplomacy', 'Diplomazia', NULL, 'tono', 1, NULL, NULL, '2026-03-25 11:26:59', '2026-03-25 11:26:59'),
	(5, 'ritual', 'Rituale', NULL, 'tono', 1, NULL, NULL, '2026-03-25 11:26:59', '2026-03-25 11:26:59'),
	(6, 'public', 'Pubblico', NULL, 'contesto', 1, NULL, NULL, '2026-03-25 11:26:59', '2026-03-25 11:26:59'),
	(7, 'secret', 'Segreto', NULL, 'contesto', 1, NULL, NULL, '2026-03-25 11:26:59', '2026-03-25 11:26:59'),
	(8, 'criminal', 'Criminale', NULL, 'tono', 1, NULL, NULL, '2026-03-25 11:26:59', '2026-03-25 11:26:59'),
	(9, 'mercantile', 'Mercantile', NULL, 'tono', 1, NULL, NULL, '2026-03-25 11:26:59', '2026-03-25 11:26:59'),
	(10, 'religious', 'Religioso', NULL, 'tono', 1, NULL, NULL, '2026-03-25 11:26:59', '2026-03-25 11:26:59'),
	(11, 'social', 'Sociale', NULL, 'tono', 1, NULL, NULL, '2026-03-25 11:26:59', '2026-03-25 11:26:59'),
	(12, 'investigation', 'Investigazione', NULL, 'tono', 1, NULL, NULL, '2026-03-25 11:26:59', '2026-03-25 11:26:59'),
	(13, 'military', 'Militare', NULL, 'tono', 1, NULL, NULL, '2026-03-25 11:26:59', '2026-03-25 11:26:59'),
	(14, 'noble', 'Nobiliare', NULL, 'tono', 1, NULL, NULL, '2026-03-25 11:26:59', '2026-03-25 11:26:59'),
	(15, 'discovery', 'Scoperta', NULL, 'tono', 1, NULL, NULL, '2026-03-25 11:26:59', '2026-03-25 11:26:59'),
	(16, 'crisis', 'Crisi', NULL, 'tono', 1, NULL, NULL, '2026-03-25 11:26:59', '2026-03-25 11:26:59'),
	(17, 'ceremonial', 'Cerimoniale', NULL, 'contesto', 1, NULL, NULL, '2026-03-25 11:26:59', '2026-03-25 11:26:59'),
	(18, 'private', 'Privato', NULL, 'contesto', 1, NULL, NULL, '2026-03-25 11:26:59', '2026-03-25 11:26:59');

-- Dump della struttura di tabella logeon_db.nationalities
DROP TABLE IF EXISTS `nationalities`;
CREATE TABLE IF NOT EXISTS `nationalities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `name_male` varchar(160) NOT NULL,
  `name_female` varchar(160) NOT NULL,
  `description` text NOT NULL,
  `image` varchar(255) NOT NULL,
  `icon` varchar(255) NOT NULL,
  `is_visibled` tinyint(1) NOT NULL,
  `is_subscribed` tinyint(1) NOT NULL,
  `date_created` timestamp NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.nationalities: ~0 rows (circa)
DELETE FROM `nationalities`;

-- Dump della struttura di tabella logeon_db.news
DROP TABLE IF EXISTS `news`;
CREATE TABLE IF NOT EXISTS `news` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `excerpt` varchar(255) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `type` tinyint(2) NOT NULL DEFAULT 0,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `author_id` int(11) NOT NULL,
  `date_created` timestamp NULL DEFAULT current_timestamp(),
  `date_published` timestamp NULL DEFAULT NULL,
  `date_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_news_published` (`is_published`,`type`,`date_published`),
  KEY `idx_news_pinned` (`is_pinned`,`date_published`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.news: ~0 rows (circa)
DELETE FROM `news`;

-- Dump della struttura di tabella logeon_db.notifications
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `recipient_user_id` int(10) unsigned NOT NULL,
  `recipient_character_id` int(10) unsigned DEFAULT NULL,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `actor_character_id` int(10) unsigned DEFAULT NULL,
  `kind` varchar(30) NOT NULL COMMENT 'action_required|decision_result|system_update',
  `topic` varchar(50) NOT NULL,
  `priority` varchar(10) NOT NULL DEFAULT 'normal' COMMENT 'low|normal|high',
  `title` varchar(160) NOT NULL,
  `message` varchar(1000) DEFAULT NULL,
  `action_status` varchar(10) NOT NULL DEFAULT 'none' COMMENT 'none|pending|resolved',
  `action_decision` varchar(10) DEFAULT NULL COMMENT 'accepted|rejected|expired|cancelled',
  `action_url` varchar(255) DEFAULT NULL,
  `source_type` varchar(40) DEFAULT NULL,
  `source_id` int(10) unsigned DEFAULT NULL,
  `source_meta_json` text DEFAULT NULL,
  `dedup_key` varchar(190) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `date_created` datetime NOT NULL,
  `date_updated` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_notifications_dedup` (`recipient_user_id`,`dedup_key`),
  KEY `idx_notifications_recipient_created` (`recipient_user_id`,`recipient_character_id`,`date_created`),
  KEY `idx_notifications_recipient_read` (`recipient_user_id`,`is_read`,`date_created`),
  KEY `idx_notifications_action_pending` (`recipient_user_id`,`action_status`,`date_created`),
  KEY `idx_notifications_source` (`source_type`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.notifications: ~0 rows (circa)
DELETE FROM `notifications`;

-- Dump della struttura di tabella logeon_db.password_resets
DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `password_resets_token_unique` (`token_hash`),
  KEY `password_resets_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.password_resets: ~0 rows (circa)
DELETE FROM `password_resets`;

-- Dump della struttura di tabella logeon_db.quest_closure_reports
DROP TABLE IF EXISTS `quest_closure_reports`;
CREATE TABLE IF NOT EXISTS `quest_closure_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_instance_id` int(11) NOT NULL,
  `closure_type` enum('success','partial_success','failure','cancelled','unresolved') NOT NULL DEFAULT 'success',
  `summary_public` text DEFAULT NULL,
  `summary_private` longtext DEFAULT NULL,
  `outcome_label` varchar(120) NOT NULL DEFAULT 'Obiettivo completato',
  `closed_by` int(11) DEFAULT NULL,
  `closed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `player_visible` tinyint(1) NOT NULL DEFAULT 1,
  `staff_notes` longtext DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quest_closure_reports_instance` (`quest_instance_id`),
  KEY `idx_quest_closure_reports_closed_at` (`closed_at`),
  KEY `idx_quest_closure_reports_player_visible` (`player_visible`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.quest_closure_reports: ~0 rows (circa)
DELETE FROM `quest_closure_reports`;

-- Dump della struttura di tabella logeon_db.quest_conditions
DROP TABLE IF EXISTS `quest_conditions`;
CREATE TABLE IF NOT EXISTS `quest_conditions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_definition_id` int(11) DEFAULT NULL,
  `quest_step_definition_id` int(11) DEFAULT NULL,
  `condition_type` varchar(80) NOT NULL,
  `operator` varchar(20) NOT NULL DEFAULT 'eq',
  `condition_payload` longtext DEFAULT NULL,
  `evaluation_mode` enum('all_required','any_required','blocking','optional') NOT NULL DEFAULT 'all_required',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_quest_conditions_definition` (`quest_definition_id`),
  KEY `idx_quest_conditions_step` (`quest_step_definition_id`),
  KEY `idx_quest_conditions_type` (`condition_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.quest_conditions: ~0 rows (circa)
DELETE FROM `quest_conditions`;

-- Dump della struttura di tabella logeon_db.quest_definitions
DROP TABLE IF EXISTS `quest_definitions`;
CREATE TABLE IF NOT EXISTS `quest_definitions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(120) NOT NULL,
  `title` varchar(255) NOT NULL,
  `summary` text DEFAULT NULL,
  `description` longtext DEFAULT NULL,
  `quest_type` varchar(80) NOT NULL DEFAULT 'personal',
  `intensity_level` varchar(20) NOT NULL DEFAULT 'STANDARD',
  `intensity_visibility` varchar(20) NOT NULL DEFAULT 'visible',
  `visibility` enum('public','private','staff_only','hidden') NOT NULL DEFAULT 'public',
  `scope_type` varchar(40) NOT NULL DEFAULT 'character',
  `scope_id` int(11) DEFAULT NULL,
  `availability_type` varchar(40) NOT NULL DEFAULT 'automatic_unlock',
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `meta_json` longtext DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quest_definitions_slug` (`slug`),
  KEY `idx_quest_definitions_status` (`status`),
  KEY `idx_quest_definitions_scope` (`scope_type`,`scope_id`),
  KEY `idx_quest_definitions_visibility` (`visibility`),
  KEY `idx_quest_definitions_availability` (`availability_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.quest_definitions: ~0 rows (circa)
DELETE FROM `quest_definitions`;

-- Dump della struttura di tabella logeon_db.quest_event_links
DROP TABLE IF EXISTS `quest_event_links`;
CREATE TABLE IF NOT EXISTS `quest_event_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_definition_id` int(11) DEFAULT NULL,
  `quest_instance_id` int(11) DEFAULT NULL,
  `narrative_event_id` int(11) DEFAULT NULL,
  `system_event_id` int(11) DEFAULT NULL,
  `link_type` varchar(40) NOT NULL DEFAULT 'contextualized_by',
  `meta_json` longtext DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_quest_event_link_definition` (`quest_definition_id`),
  KEY `idx_quest_event_link_instance` (`quest_instance_id`),
  KEY `idx_quest_event_link_narrative` (`narrative_event_id`),
  KEY `idx_quest_event_link_system` (`system_event_id`),
  KEY `idx_quest_event_link_type` (`link_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.quest_event_links: ~0 rows (circa)
DELETE FROM `quest_event_links`;

-- Dump della struttura di tabella logeon_db.quest_instances
DROP TABLE IF EXISTS `quest_instances`;
CREATE TABLE IF NOT EXISTS `quest_instances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_definition_id` int(11) NOT NULL,
  `assignee_type` varchar(40) NOT NULL DEFAULT 'character',
  `assignee_id` int(11) DEFAULT NULL,
  `current_status` enum('locked','available','active','completed','failed','cancelled','expired') NOT NULL DEFAULT 'available',
  `intensity_level` varchar(20) DEFAULT NULL,
  `current_branch` varchar(80) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `failed_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `source_type` varchar(60) NOT NULL DEFAULT 'manual',
  `source_id` int(11) DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `last_activity_at` datetime DEFAULT NULL,
  `meta_json` longtext DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_quest_instances_definition_status` (`quest_definition_id`,`current_status`),
  KEY `idx_quest_instances_assignee` (`assignee_type`,`assignee_id`,`current_status`),
  KEY `idx_quest_instances_source` (`source_type`,`source_id`),
  KEY `idx_quest_instances_expire` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.quest_instances: ~0 rows (circa)
DELETE FROM `quest_instances`;

-- Dump della struttura di tabella logeon_db.quest_outcomes
DROP TABLE IF EXISTS `quest_outcomes`;
CREATE TABLE IF NOT EXISTS `quest_outcomes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_definition_id` int(11) NOT NULL,
  `trigger_type` varchar(40) NOT NULL,
  `outcome_type` varchar(80) NOT NULL,
  `outcome_payload` longtext DEFAULT NULL,
  `visibility` enum('public','private','staff_only','hidden') NOT NULL DEFAULT 'hidden',
  `requires_staff_confirmation` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_quest_outcomes_definition` (`quest_definition_id`,`trigger_type`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.quest_outcomes: ~0 rows (circa)
DELETE FROM `quest_outcomes`;

-- Dump della struttura di tabella logeon_db.quest_progress_logs
DROP TABLE IF EXISTS `quest_progress_logs`;
CREATE TABLE IF NOT EXISTS `quest_progress_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_instance_id` int(11) NOT NULL,
  `step_instance_id` int(11) DEFAULT NULL,
  `log_type` varchar(60) NOT NULL,
  `source_type` varchar(60) NOT NULL DEFAULT 'system',
  `source_id` int(11) DEFAULT NULL,
  `payload` longtext DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_quest_progress_logs_instance` (`quest_instance_id`,`date_created`),
  KEY `idx_quest_progress_logs_step` (`step_instance_id`),
  KEY `idx_quest_progress_logs_source` (`source_type`,`source_id`)
) ENGINE=InnoDB AUTO_INCREMENT=253 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.quest_progress_logs: ~0 rows (circa)
DELETE FROM `quest_progress_logs`;

-- Dump della struttura di tabella logeon_db.quest_reward_assignments
DROP TABLE IF EXISTS `quest_reward_assignments`;
CREATE TABLE IF NOT EXISTS `quest_reward_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_instance_id` int(11) NOT NULL,
  `recipient_type` varchar(40) NOT NULL DEFAULT 'character',
  `recipient_id` int(11) DEFAULT NULL,
  `reward_type` varchar(60) NOT NULL,
  `reward_reference_id` int(11) DEFAULT NULL,
  `reward_value` decimal(14,2) DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `visibility` enum('public','player_private','staff_only') NOT NULL DEFAULT 'public',
  `notes` text DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_quest_reward_assignments_instance` (`quest_instance_id`),
  KEY `idx_quest_reward_assignments_recipient` (`recipient_type`,`recipient_id`),
  KEY `idx_quest_reward_assignments_visibility` (`visibility`),
  KEY `idx_quest_reward_assignments_assigned_at` (`assigned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.quest_reward_assignments: ~0 rows (circa)
DELETE FROM `quest_reward_assignments`;

-- Dump della struttura di tabella logeon_db.quest_step_definitions
DROP TABLE IF EXISTS `quest_step_definitions`;
CREATE TABLE IF NOT EXISTS `quest_step_definitions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_definition_id` int(11) NOT NULL,
  `step_key` varchar(120) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` longtext DEFAULT NULL,
  `step_type` varchar(80) NOT NULL DEFAULT 'narrative_action',
  `order_index` int(11) NOT NULL DEFAULT 0,
  `is_optional` tinyint(1) NOT NULL DEFAULT 0,
  `completion_mode` varchar(40) NOT NULL DEFAULT 'automatic',
  `branch_on_success` varchar(80) DEFAULT NULL,
  `branch_on_failure` varchar(80) DEFAULT NULL,
  `visibility_mode` varchar(40) NOT NULL DEFAULT 'visible',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `meta_json` longtext DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quest_step_definition_key` (`quest_definition_id`,`step_key`),
  KEY `idx_quest_step_definition_order` (`quest_definition_id`,`order_index`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.quest_step_definitions: ~0 rows (circa)
DELETE FROM `quest_step_definitions`;

-- Dump della struttura di tabella logeon_db.quest_step_instances
DROP TABLE IF EXISTS `quest_step_instances`;
CREATE TABLE IF NOT EXISTS `quest_step_instances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_instance_id` int(11) NOT NULL,
  `quest_step_definition_id` int(11) NOT NULL,
  `progress_status` enum('pending','active','completed','failed','skipped','locked') NOT NULL DEFAULT 'locked',
  `progress_value` decimal(12,2) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `failed_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `internal_notes` text DEFAULT NULL,
  `meta_json` longtext DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quest_step_instance` (`quest_instance_id`,`quest_step_definition_id`),
  KEY `idx_quest_step_instance_status` (`quest_instance_id`,`progress_status`),
  KEY `idx_quest_step_instance_definition` (`quest_step_definition_id`)
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.quest_step_instances: ~1 rows (circa)
DELETE FROM `quest_step_instances`;

-- Dump della struttura di tabella logeon_db.rules
DROP TABLE IF EXISTS `rules`;
CREATE TABLE IF NOT EXISTS `rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `article` int(11) NOT NULL,
  `subarticle` int(11) NOT NULL,
  `title` varchar(75) NOT NULL,
  `body` mediumtext NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rules_article_subarticle` (`article`,`subarticle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.rules: ~0 rows (circa)
DELETE FROM `rules`;

-- Dump della struttura di tabella logeon_db.seasons
DROP TABLE IF EXISTS `seasons`;
CREATE TABLE IF NOT EXISTS `seasons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(80) NOT NULL,
  `description` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `starts_at_month` tinyint(3) unsigned DEFAULT NULL,
  `starts_at_day` tinyint(3) unsigned DEFAULT NULL,
  `ends_at_month` tinyint(3) unsigned DEFAULT NULL,
  `ends_at_day` tinyint(3) unsigned DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seasons_slug` (`slug`),
  KEY `idx_seasons_active_sort` (`is_active`,`sort_order`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.seasons: ~4 rows (circa)
DELETE FROM `seasons`;
INSERT INTO `seasons` (`id`, `name`, `slug`, `description`, `sort_order`, `is_active`, `starts_at_month`, `starts_at_day`, `ends_at_month`, `ends_at_day`, `date_created`, `date_updated`) VALUES
	(1, 'Primavera', 'spring', 'Stagione primaverile', 10, 1, 3, 21, 6, 20, '2026-04-02 12:10:33', '2026-04-02 12:10:33'),
	(2, 'Estate', 'summer', 'Stagione estiva', 20, 1, 6, 21, 9, 22, '2026-04-02 12:10:33', '2026-04-02 12:10:33'),
	(3, 'Autunno', 'autumn', 'Stagione autunnale', 30, 1, 9, 23, 12, 20, '2026-04-02 12:10:33', '2026-04-02 12:10:33'),
	(4, 'Inverno', 'winter', 'Stagione invernale', 40, 1, 12, 21, 3, 20, '2026-04-02 12:10:33', '2026-04-02 12:10:33');

-- Dump della struttura di tabella logeon_db.shop_inventory
DROP TABLE IF EXISTS `shop_inventory`;
CREATE TABLE IF NOT EXISTS `shop_inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shop_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `price` int(11) NOT NULL DEFAULT 0,
  `stock` int(11) DEFAULT NULL,
  `per_character_limit` int(11) DEFAULT NULL,
  `per_day_limit` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_promo` tinyint(1) NOT NULL DEFAULT 0,
  `promo_discount` int(11) NOT NULL DEFAULT 0,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `shop_item_unique` (`shop_id`,`item_id`),
  KEY `shop_id` (`shop_id`),
  KEY `item_id` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.shop_inventory: ~0 rows (circa)
DELETE FROM `shop_inventory`;

-- Dump della struttura di tabella logeon_db.shop_purchases
DROP TABLE IF EXISTS `shop_purchases`;
CREATE TABLE IF NOT EXISTS `shop_purchases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shop_id` int(11) NOT NULL,
  `character_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `total_price` int(11) NOT NULL DEFAULT 0,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `shop_id` (`shop_id`),
  KEY `character_id` (`character_id`),
  KEY `item_id` (`item_id`),
  KEY `idx_shop_limits` (`shop_id`,`character_id`,`item_id`,`date_created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.shop_purchases: ~0 rows (circa)
DELETE FROM `shop_purchases`;

-- Dump della struttura di tabella logeon_db.shop_sales
DROP TABLE IF EXISTS `shop_sales`;
CREATE TABLE IF NOT EXISTS `shop_sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shop_id` int(11) DEFAULT NULL,
  `character_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` int(11) NOT NULL DEFAULT 0,
  `total_price` int(11) NOT NULL DEFAULT 0,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `shop_id` (`shop_id`),
  KEY `character_id` (`character_id`),
  KEY `item_id` (`item_id`),
  KEY `idx_shop_sales` (`shop_id`,`character_id`,`item_id`,`date_created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.shop_sales: ~0 rows (circa)
DELETE FROM `shop_sales`;

-- Dump della struttura di tabella logeon_db.shops
DROP TABLE IF EXISTS `shops`;
CREATE TABLE IF NOT EXISTS `shops` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'global',
  `location_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `location_id` (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.shops: ~0 rows (circa)
DELETE FROM `shops`;

-- Dump della struttura di tabella logeon_db.social_status
DROP TABLE IF EXISTS `social_status`;
CREATE TABLE IF NOT EXISTS `social_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `description` tinytext NOT NULL,
  `icon` varchar(255) NOT NULL DEFAULT 'http://via.placeholder.com/48x48',
  `shop_discount` tinyint(3) NOT NULL DEFAULT 0,
  `unlock_home` tinyint(1) NOT NULL DEFAULT 0,
  `quest_tier` tinyint(2) NOT NULL DEFAULT 0,
  `min` int(11) NOT NULL,
  `max` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.social_status: ~5 rows (circa)
DELETE FROM `social_status`;
INSERT INTO `social_status` (`id`, `name`, `description`, `icon`, `shop_discount`, `unlock_home`, `quest_tier`, `min`, `max`) VALUES
	(1, 'Sconosciuto', 'Il personaggio Ã¨ pelopiÃ¹ ignoto alla comunitÃ  e non ha alcuna rilevanza a livello sociale. Non sarÃ  ricordato o riconosciuto dalla popolazione.', '/assets/imgs/defaults-images/default-icon.png', 0, 0, 0, 0, 19),
	(2, 'Riconosciuto', 'Il nome del personaggio Ã¨ diventato familiare nel settore in cui opera, riuscendo a vantare una piccola notorietÃ  a livello locale per le sue qualitÃ . Comincia ad avere una certa rilevanza sociale.', '/assets/imgs/defaults-images/default-icon.png', 0, 0, 0, 20, 49),
	(3, 'Famoso', 'Una figura che Ã¨ si Ã¨ posta in prima linea. Il personaggio vanta notorietÃ  per le sue imprese e le sue qualitÃ , divenendo una figura di rilievo in una cittÃ , Ã¨ difficile che passi inosservato.', '/assets/imgs/defaults-images/default-icon.png', 0, 0, 0, 50, 69),
	(4, 'Celebrità', 'Un personaggio il cui nome Ã¨ risaputo nei confini della propria nazione. SarÃ  difficile restare in incognito.', '/assets/imgs/defaults-images/default-icon.png', 0, 0, 0, 70, 99),
	(5, 'Leggenda Vivente', 'Personaggio che ha importanza internazionale. Questa figura Ã¨ nota a livello mondiale per le sue qualitÃ  e le sue imprese, sia in positivo che in negativo. In tutte le terre si potrÃ  sentire parlare di lui.', '/assets/imgs/defaults-images/default-icon.png', 0, 0, 0, 100, 9000);

-- Dump della struttura di tabella logeon_db.storyboards
DROP TABLE IF EXISTS `storyboards`;
CREATE TABLE IF NOT EXISTS `storyboards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chapter` int(2) NOT NULL,
  `subchapter` int(2) NOT NULL,
  `title` varchar(30) NOT NULL,
  `body` mediumtext NOT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_storyboards_chapter_subchapter` (`chapter`,`subchapter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.storyboards: ~0 rows (circa)
DELETE FROM `storyboards`;

-- Dump della struttura di tabella logeon_db.sys_configs
DROP TABLE IF EXISTS `sys_configs`;
CREATE TABLE IF NOT EXISTS `sys_configs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` varchar(255) NOT NULL,
  `type` char(50) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `CONFIG_KEY_UNIQUE` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=632 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.sys_configs: ~64 rows (circa)
DELETE FROM `sys_configs`;
INSERT INTO `sys_configs` (`id`, `key`, `value`, `type`, `date_created`, `date_updated`) VALUES
	(1, 'money_name', 'Coin', 'text', '2023-06-01 12:22:45', '2026-02-08 15:44:00'),
	(2, 'name_change_cooldown_days', '30', 'number', '2026-02-10 16:37:58', NULL),
	(3, 'session_version_check_seconds', '60', 'number', '2026-02-10 16:37:58', NULL),
	(4, 'availability_seed_interval_seconds', '300', 'number', '2026-02-10 19:27:05', NULL),
	(5, 'availability_idle_minutes', '20', 'number', '2026-02-10 22:05:22', NULL),
	(6, 'onlines_auto_toast', '0', 'number', '2026-02-10 22:42:38', NULL),
	(7, 'maps_view_mode', 'cards', 'string', '2026-02-11 09:05:58', NULL),
	(8, 'location_invite_expiry_hours', '48', 'number', '2026-02-11 15:50:54', NULL),
	(9, 'location_invite_max_active', '10', 'number', '2026-02-11 15:50:54', NULL),
	(10, 'weather_global_key', '', 'string', '2026-02-12 16:35:31', '2026-04-17 19:25:25'),
	(11, 'weather_global_degrees', '', 'number', '2026-02-12 16:35:31', '2026-04-17 19:25:25'),
	(12, 'weather_global_moon_phase', '', 'string', '2026-02-12 16:35:31', '2026-04-17 19:25:25'),
	(13, 'weather_render_mode', 'animated', 'string', '2026-02-12 20:58:57', NULL),
	(14, 'weather_image_base_url', '', 'string', '2026-02-12 20:58:57', NULL),
	(16, 'rate_auth_signin_limit', '10', 'number', '2026-02-13 12:18:15', NULL),
	(17, 'rate_auth_signin_window_seconds', '300', 'number', '2026-02-13 12:18:15', NULL),
	(18, 'rate_auth_reset_limit', '5', 'number', '2026-02-13 12:18:15', NULL),
	(19, 'rate_auth_reset_window_seconds', '900', 'number', '2026-02-13 12:18:15', NULL),
	(20, 'rate_auth_reset_confirm_limit', '10', 'number', '2026-02-13 12:18:15', NULL),
	(21, 'rate_auth_reset_confirm_window_seconds', '900', 'number', '2026-02-13 12:18:15', NULL),
	(22, 'rate_dm_send_limit', '8', 'number', '2026-02-13 12:18:15', NULL),
	(23, 'rate_dm_send_window_seconds', '30', 'number', '2026-02-13 12:18:15', NULL),
	(24, 'rate_location_chat_limit', '12', 'number', '2026-02-13 12:18:15', NULL),
	(25, 'rate_location_chat_window_seconds', '15', 'number', '2026-02-13 12:18:15', NULL),
	(26, 'rate_location_whisper_limit', '6', 'number', '2026-02-13 12:18:15', NULL),
	(27, 'rate_location_whisper_window_seconds', '20', 'number', '2026-02-13 12:18:15', NULL),
	(28, 'loanface_change_cooldown_days', '90', 'number', '2026-03-07 12:28:51', NULL),
	(29, 'character_attributes_enabled', '0', 'number', '2026-03-14 22:48:39', NULL),
	(31, 'presence_resume_last_position_on_signin', '0', 'number', '2026-03-15 17:44:15', NULL),
	(32, 'conflict_resolution_mode', 'narrative', 'text', '2026-03-16 09:54:54', NULL),
	(33, 'conflict_margin_narrow_max', '2', 'number', '2026-03-16 09:54:54', NULL),
	(34, 'conflict_margin_clear_max', '5', 'number', '2026-03-16 09:54:54', NULL),
	(35, 'conflict_critical_failure_value', '1', 'number', '2026-03-16 09:54:54', NULL),
	(36, 'conflict_critical_success_value', '0', 'number', '2026-03-16 09:54:54', NULL),
	(42, 'conflict_overlap_policy', 'warn_only', 'text', '2026-03-16 12:24:39', NULL),
	(43, 'conflict_inactivity_warning_hours', '72', 'number', '2026-03-16 12:24:39', NULL),
	(44, 'conflict_inactivity_archive_days', '7', 'number', '2026-03-16 12:24:39', NULL),
	(45, 'conflict_chat_compact_events', '1', 'number', '2026-03-16 12:24:39', NULL),
	(179, 'system_events_enabled', '1', 'number', '2026-03-19 21:58:17', NULL),
	(180, 'system_events_maintenance_interval_minutes', '5', 'number', '2026-03-19 21:58:17', NULL),
	(181, 'system_events_default_visibility', 'public', 'text', '2026-03-19 21:58:17', NULL),
	(182, 'system_events_auto_notify', '1', 'number', '2026-03-19 21:58:17', NULL),
	(183, 'quests_enabled', '1', 'number', '2026-03-20 00:28:19', NULL),
	(184, 'quests_maintenance_interval_minutes', '5', 'number', '2026-03-20 00:28:19', NULL),
	(185, 'quests_auto_notify', '1', 'number', '2026-03-20 00:28:19', NULL),
	(186, 'narrative_tags_max_per_entity', '8', 'int', '2026-03-30 21:31:26', NULL),
	(196, 'weather_climate_enabled', '1', 'number', '2026-04-02 12:10:33', NULL),
	(197, 'weather_season_mode', 'auto', 'string', '2026-04-02 12:10:33', NULL),
	(198, 'weather_active_season_id', '', 'number', '2026-04-02 12:10:33', NULL),
	(199, 'weather_fallback_scope_type', 'world', 'string', '2026-04-02 12:10:33', NULL),
	(200, 'weather_fallback_scope_id', '1', 'number', '2026-04-02 12:10:33', NULL),
	(224, 'presence_restore_last_position_on_signin', '0', 'number', '2026-04-02 20:01:27', NULL),
	(225, 'narrative_delegation_enabled', '0', 'number', '2026-04-12 00:00:00', '2026-04-23 10:33:18'),
	(226, 'narrative_delegation_level', '2', 'number', '2026-04-12 00:00:00', '2026-04-23 10:33:04'),
	(479, 'auth_google_enabled', '0', 'number', '2026-04-23 10:33:04', NULL),
	(480, 'auth_google_client_id', '', 'string', '2026-04-23 10:33:04', NULL),
	(481, 'auth_google_client_secret', '', 'string', '2026-04-23 10:33:04', NULL),
	(482, 'auth_google_redirect_uri', '', 'string', '2026-04-23 10:33:04', NULL),
	(483, 'multi_character_enabled', '0', 'number', '2026-04-23 10:33:04', NULL),
	(484, 'multi_character_max_per_user', '1', 'number', '2026-04-23 10:33:04', NULL),
	(564, 'storyboard_view_mode', 'monolithic', 'string', '2026-04-24 20:07:06', '2026-04-24 20:26:29'),
	(565, 'rules_view_mode', 'monolithic', 'string', '2026-04-24 20:07:06', '2026-04-24 20:26:29'),
	(566, 'how_to_play_view_mode', 'monolithic', 'string', '2026-04-24 20:07:06', '2026-04-24 20:26:29'),
	(625, 'archetypes_view_mode', 'monolithic', 'string', '2026-04-24 21:27:02', NULL),
	(628, 'theme_system_enabled', '0', NULL, '2026-04-26 15:01:19', '2026-04-26 15:01:50'),
	(629, 'active_theme', '', NULL, '2026-04-26 15:01:19', '2026-04-26 15:01:50');

-- Dump della struttura di tabella logeon_db.sys_logs
DROP TABLE IF EXISTS `sys_logs`;
CREATE TABLE IF NOT EXISTS `sys_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `author` int(11) DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `area` varchar(255) DEFAULT NULL,
  `module` varchar(255) DEFAULT NULL,
  `action` varchar(255) DEFAULT NULL,
  `data` longtext DEFAULT NULL,
  `date_created` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.sys_logs: ~0 rows (circa)
DELETE FROM `sys_logs`;

-- Dump della struttura di tabella logeon_db.sys_module_migrations
DROP TABLE IF EXISTS `sys_module_migrations`;
CREATE TABLE IF NOT EXISTS `sys_module_migrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module_id` varchar(120) NOT NULL,
  `migration_key` varchar(180) NOT NULL,
  `checksum_sha256` char(64) DEFAULT NULL,
  `executed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_module_migration` (`module_id`,`migration_key`),
  KEY `idx_module_exec` (`module_id`,`executed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.sys_module_migrations: ~0 rows (circa)
DELETE FROM `sys_module_migrations`;

-- Dump della struttura di tabella logeon_db.sys_module_settings
DROP TABLE IF EXISTS `sys_module_settings`;
CREATE TABLE IF NOT EXISTS `sys_module_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module_id` varchar(120) NOT NULL,
  `setting_key` varchar(120) NOT NULL,
  `setting_value` longtext DEFAULT NULL,
  `value_type` varchar(30) NOT NULL DEFAULT 'string',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_module_setting` (`module_id`,`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.sys_module_settings: ~0 rows (circa)
DELETE FROM `sys_module_settings`;

-- Dump della struttura di tabella logeon_db.sys_modules
DROP TABLE IF EXISTS `sys_modules`;
CREATE TABLE IF NOT EXISTS `sys_modules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module_id` varchar(120) NOT NULL,
  `name` varchar(120) NOT NULL,
  `vendor` varchar(80) NOT NULL,
  `version` varchar(40) NOT NULL,
  `status` enum('installed','active','inactive','error') NOT NULL DEFAULT 'installed',
  `install_path` varchar(255) NOT NULL,
  `checksum_sha256` char(64) DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `date_installed` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_activated` timestamp NULL DEFAULT NULL,
  `date_deactivated` timestamp NULL DEFAULT NULL,
  `date_updated` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `module_id` (`module_id`)
) ENGINE=InnoDB AUTO_INCREMENT=136 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.sys_modules: ~8 rows (circa)
DELETE FROM `sys_modules`;
INSERT INTO `sys_modules` (`id`, `module_id`, `name`, `vendor`, `version`, `status`, `install_path`, `checksum_sha256`, `last_error`, `date_installed`, `date_activated`, `date_deactivated`, `date_updated`) VALUES
	(128, 'logeon.multi-currency', 'Logeon Multi Currency', 'Logeon', '0.1.0', 'inactive', 'C:\\xampp\\htdocs\\logeon-vNext/modules/logeon.multi-currency', NULL, NULL, '2026-04-24 09:56:01', '2026-04-25 11:50:52', '2026-04-25 11:50:53', '2026-04-25 11:50:53'),
	(129, 'logeon.archetypes', 'Logeon Archetypes', 'Logeon', '0.1.0', 'inactive', 'C:\\xampp\\htdocs\\logeon-vNext/modules/logeon.archetypes', NULL, NULL, '2026-04-24 09:57:33', '2026-04-24 21:41:14', '2026-04-24 21:41:18', '2026-04-24 21:41:18'),
	(130, 'logeon.social-status', 'Logeon Social Status', 'Logeon', '0.1.0', 'inactive', 'C:\\xampp\\htdocs\\logeon-vNext/modules/logeon.social-status', NULL, NULL, '2026-04-24 09:59:40', '2026-04-25 11:50:50', '2026-04-25 11:50:50', '2026-04-25 11:50:50'),
	(131, 'logeon.factions', 'Logeon Factions', 'Logeon', '0.1.0', 'inactive', 'C:\\xampp\\htdocs\\logeon-vNext/modules/logeon.factions', NULL, NULL, '2026-04-24 10:15:42', '2026-04-25 11:50:51', '2026-04-25 11:50:51', '2026-04-25 11:50:51'),
	(132, 'logeon.attributes', 'Logeon Attributes', 'Logeon', '0.1.0', 'inactive', 'C:\\xampp\\htdocs\\logeon-vNext/modules/logeon.attributes', NULL, NULL, '2026-04-24 10:18:32', '2026-04-25 11:50:51', '2026-04-25 11:50:52', '2026-04-25 11:50:52'),
	(133, 'logeon.novelty', 'Logeon Novelty', 'Logeon', '0.1.0', 'inactive', 'C:\\xampp\\htdocs\\logeon-vNext/modules/logeon.novelty', NULL, NULL, '2026-04-24 10:20:43', '2026-04-25 11:50:54', '2026-04-25 11:50:54', '2026-04-25 11:50:54'),
	(134, 'logeon.weather', 'Logeon Weather', 'Logeon', '0.1.0', 'inactive', 'C:\\xampp\\htdocs\\logeon-vNext/modules/logeon.weather', NULL, NULL, '2026-04-24 10:20:52', '2026-04-25 11:50:53', '2026-04-25 11:50:53', '2026-04-25 11:50:53'),
	(135, 'logeon.quests', 'Logeon Quests', 'Logeon', '0.1.0', 'inactive', 'C:\\xampp\\htdocs\\logeon-vNext/modules/logeon.quests', NULL, NULL, '2026-04-24 10:21:41', '2026-04-25 11:50:54', '2026-04-25 11:50:55', '2026-04-25 11:50:55');

-- Dump della struttura di tabella logeon_db.sys_settings
DROP TABLE IF EXISTS `sys_settings`;
CREATE TABLE IF NOT EXISTS `sys_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL,
  `value` varchar(255) DEFAULT NULL,
  `date_created` timestamp NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_key` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.sys_settings: ~7 rows (circa)
DELETE FROM `sys_settings`;
INSERT INTO `sys_settings` (`id`, `key`, `value`, `date_created`, `date_updated`) VALUES
	(1, 'upload_max_mb', '5', '2026-02-09 21:26:09', NULL),
	(2, 'upload_max_concurrency', '3', '2026-02-09 22:07:24', NULL),
	(3, 'upload_max_avatar_mb', '2', '2026-02-09 22:22:59', NULL),
	(5, 'inventory_capacity_max', '30', '2026-03-06 20:06:53', NULL),
	(6, 'inventory_stack_max', '50', '2026-03-06 20:06:53', NULL),
	(10, 'location_chat_history_hours', '3', '2026-04-02 20:01:27', NULL),
	(11, 'location_whisper_retention_hours', '24', '2026-04-02 20:01:27', NULL);

-- Dump della struttura di tabella logeon_db.system_event_effects
DROP TABLE IF EXISTS `system_event_effects`;
CREATE TABLE IF NOT EXISTS `system_event_effects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `system_event_id` int(11) NOT NULL,
  `effect_type` enum('currency_reward') NOT NULL DEFAULT 'currency_reward',
  `currency_id` int(11) DEFAULT NULL,
  `amount` int(11) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `meta_json` longtext DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_system_event_effects_event` (`system_event_id`),
  KEY `idx_system_event_effects_type` (`effect_type`)
) ENGINE=InnoDB AUTO_INCREMENT=86 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.system_event_effects: ~0 rows (circa)
DELETE FROM `system_event_effects`;

-- Dump della struttura di tabella logeon_db.system_event_participations
DROP TABLE IF EXISTS `system_event_participations`;
CREATE TABLE IF NOT EXISTS `system_event_participations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `system_event_id` int(11) NOT NULL,
  `participant_mode` enum('character','faction') NOT NULL DEFAULT 'character',
  `character_id` int(11) DEFAULT NULL,
  `faction_id` int(11) DEFAULT NULL,
  `status` enum('joined','left','removed') NOT NULL DEFAULT 'joined',
  `joined_by_character_id` int(11) DEFAULT NULL,
  `date_joined` datetime NOT NULL DEFAULT current_timestamp(),
  `date_left` datetime DEFAULT NULL,
  `meta_json` longtext DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_system_event_participant_character` (`system_event_id`,`character_id`),
  UNIQUE KEY `uq_system_event_participant_faction` (`system_event_id`,`faction_id`),
  KEY `idx_system_event_participations_event_status` (`system_event_id`,`status`),
  KEY `idx_system_event_participations_mode` (`participant_mode`)
) ENGINE=InnoDB AUTO_INCREMENT=112 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.system_event_participations: ~0 rows (circa)
DELETE FROM `system_event_participations`;

-- Dump della struttura di tabella logeon_db.system_event_quest_links
DROP TABLE IF EXISTS `system_event_quest_links`;
CREATE TABLE IF NOT EXISTS `system_event_quest_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `system_event_id` int(11) NOT NULL,
  `quest_id` int(11) NOT NULL,
  `link_type` enum('primary','secondary') NOT NULL DEFAULT 'primary',
  `meta_json` longtext DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_system_event_quest_link` (`system_event_id`,`quest_id`),
  KEY `idx_system_event_quest_quest` (`quest_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.system_event_quest_links: ~0 rows (circa)
DELETE FROM `system_event_quest_links`;

-- Dump della struttura di tabella logeon_db.system_event_reward_logs
DROP TABLE IF EXISTS `system_event_reward_logs`;
CREATE TABLE IF NOT EXISTS `system_event_reward_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `system_event_id` int(11) NOT NULL,
  `participation_id` int(11) DEFAULT NULL,
  `character_id` int(11) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `amount` int(11) NOT NULL,
  `source` varchar(80) NOT NULL DEFAULT 'manual',
  `meta_json` longtext DEFAULT NULL,
  `awarded_by_character_id` int(11) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_system_event_reward_event` (`system_event_id`),
  KEY `idx_system_event_reward_character` (`character_id`),
  KEY `idx_system_event_reward_currency` (`currency_id`)
) ENGINE=InnoDB AUTO_INCREMENT=86 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.system_event_reward_logs: ~0 rows (circa)
DELETE FROM `system_event_reward_logs`;

-- Dump della struttura di tabella logeon_db.system_events
DROP TABLE IF EXISTS `system_events`;
CREATE TABLE IF NOT EXISTS `system_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `type` varchar(80) NOT NULL DEFAULT 'general',
  `status` enum('draft','scheduled','active','completed','cancelled') NOT NULL DEFAULT 'draft',
  `visibility` enum('public','staff_only','private') NOT NULL DEFAULT 'public',
  `show_on_homepage_feed` tinyint(1) NOT NULL DEFAULT 0,
  `scope_type` enum('global','map','location','faction','character') NOT NULL DEFAULT 'global',
  `scope_id` int(11) DEFAULT NULL,
  `participant_mode` enum('character','faction') NOT NULL DEFAULT 'character',
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `recurrence` enum('none','daily','weekly','monthly') NOT NULL DEFAULT 'none',
  `next_run_at` datetime DEFAULT NULL,
  `last_activity_at` datetime DEFAULT NULL,
  `meta_json` longtext DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_system_events_status_time` (`status`,`starts_at`,`ends_at`),
  KEY `idx_system_events_scope` (`scope_type`,`scope_id`),
  KEY `idx_system_events_mode` (`participant_mode`),
  KEY `idx_system_events_next_run` (`next_run_at`),
  KEY `idx_system_events_last_activity` (`last_activity_at`),
  KEY `idx_system_events_home_feed` (`show_on_homepage_feed`,`status`,`visibility`)
) ENGINE=InnoDB AUTO_INCREMENT=282 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.system_events: ~0 rows (circa)
DELETE FROM `system_events`;

-- Dump della struttura di tabella logeon_db.upload_chunks
DROP TABLE IF EXISTS `upload_chunks`;
CREATE TABLE IF NOT EXISTS `upload_chunks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `upload_id` int(11) NOT NULL,
  `chunk_index` int(11) NOT NULL,
  `chunk_hash` char(64) NOT NULL,
  `chunk_size` int(11) NOT NULL,
  `received` tinyint(1) NOT NULL DEFAULT 0,
  `received_bytes` int(11) NOT NULL DEFAULT 0,
  `date_created` timestamp NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_upload_chunk` (`upload_id`,`chunk_index`),
  KEY `idx_upload` (`upload_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.upload_chunks: ~0 rows (circa)
DELETE FROM `upload_chunks`;

-- Dump della struttura di tabella logeon_db.uploads
DROP TABLE IF EXISTS `uploads`;
CREATE TABLE IF NOT EXISTS `uploads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `character_id` int(11) DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size` bigint(20) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_hash` char(64) NOT NULL,
  `chunk_size` int(11) NOT NULL,
  `chunks_total` int(11) NOT NULL,
  `chunks_received` int(11) NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `final_path` varchar(255) DEFAULT NULL,
  `date_created` timestamp NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `date_completed` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_hash` (`file_hash`),
  KEY `idx_user` (`user_id`),
  KEY `idx_character` (`character_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.uploads: ~0 rows (circa)
DELETE FROM `uploads`;

-- Dump della struttura di tabella logeon_db.users
DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varbinary(255) NOT NULL,
  `google_sub` varchar(191) DEFAULT NULL,
  `google_avatar` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `gender` tinyint(1) DEFAULT 1,
  `is_administrator` tinyint(1) DEFAULT 0,
  `is_superuser` tinyint(1) NOT NULL DEFAULT 0,
  `is_moderator` tinyint(1) NOT NULL DEFAULT 0,
  `is_master` tinyint(1) NOT NULL DEFAULT 0,
  `date_last_pass` timestamp NULL DEFAULT NULL,
  `session_version` int(11) NOT NULL DEFAULT 1,
  `date_sessions_revoked` timestamp NULL DEFAULT NULL,
  `date_actived` timestamp NULL DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_last_signin` timestamp NULL DEFAULT NULL,
  `date_last_signout` timestamp NULL DEFAULT NULL,
  `date_last_seed` timestamp NULL DEFAULT NULL,
  `superuser_unique_guard` tinyint(4) GENERATED ALWAYS AS (case when `is_superuser` = 1 then 1 else NULL end) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `USER_EMAIL_UNIQUE` (`email`),
  UNIQUE KEY `uq_users_superuser_unique_guard` (`superuser_unique_guard`),
  UNIQUE KEY `uq_users_google_sub` (`google_sub`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump della struttura di tabella logeon_db.weather_overrides
DROP TABLE IF EXISTS `weather_overrides`;
CREATE TABLE IF NOT EXISTS `weather_overrides` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `scope_type` varchar(32) NOT NULL,
  `scope_id` int(11) NOT NULL,
  `weather_type_id` int(11) DEFAULT NULL,
  `temperature_override` decimal(6,2) DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `starts_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_weather_overrides_scope` (`scope_type`,`scope_id`,`is_active`),
  KEY `idx_weather_overrides_time` (`starts_at`,`expires_at`,`is_active`),
  KEY `idx_weather_overrides_weather` (`weather_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.weather_overrides: ~0 rows (circa)
DELETE FROM `weather_overrides`;

-- Dump della struttura di tabella logeon_db.weather_types
DROP TABLE IF EXISTS `weather_types`;
CREATE TABLE IF NOT EXISTS `weather_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(80) NOT NULL,
  `description` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `visual_group` varchar(32) DEFAULT NULL,
  `is_precipitation` tinyint(1) NOT NULL DEFAULT 0,
  `is_snow` tinyint(1) NOT NULL DEFAULT 0,
  `is_storm` tinyint(1) NOT NULL DEFAULT 0,
  `reduces_visibility` tinyint(1) NOT NULL DEFAULT 0,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_weather_types_slug` (`slug`),
  KEY `idx_weather_types_active_sort` (`is_active`,`sort_order`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dump dei dati della tabella logeon_db.weather_types: ~0 rows (circa)
DELETE FROM `weather_types`;

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
