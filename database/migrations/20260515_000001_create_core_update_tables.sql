CREATE TABLE IF NOT EXISTS `core_updates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `from_version` VARCHAR(40) NOT NULL,
  `to_version` VARCHAR(40) NOT NULL,
  `distribution` VARCHAR(40) NOT NULL,
  `status` VARCHAR(40) NOT NULL,
  `started_at` DATETIME NOT NULL,
  `completed_at` DATETIME NULL,
  `error_code` VARCHAR(120) NULL,
  `error_message` TEXT NULL,
  `backup_path` VARCHAR(255) NULL,
  `package_path` VARCHAR(255) NULL,
  `created_by_user_id` INT NULL
);

CREATE TABLE IF NOT EXISTS `core_migrations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `migration` VARCHAR(190) NOT NULL UNIQUE,
  `applied_at` DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS `core_update_events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `update_id` INT NULL,
  `level` VARCHAR(20) NOT NULL,
  `event_key` VARCHAR(120) NOT NULL,
  `message` TEXT NULL,
  `context_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL,
  KEY `idx_core_update_events_update_id` (`update_id`),
  KEY `idx_core_update_events_level` (`level`)
);

