-- Map hierarchy — v1
-- Aggiunge la relazione padre/figlio tra mappe per supportare sottomappe navigabili.

SET @db_name = DATABASE();

SET @has_parent_map_id = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'maps'
      AND COLUMN_NAME = 'parent_map_id'
);
SET @sql = IF(
    @has_parent_map_id = 0,
    'ALTER TABLE `maps` ADD COLUMN `parent_map_id` INT(11) DEFAULT NULL AFTER `position`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_parent_idx = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'maps'
      AND INDEX_NAME = 'idx_maps_parent_map_id'
);
SET @sql = IF(
    @has_parent_idx = 0,
    'ALTER TABLE `maps` ADD KEY `idx_maps_parent_map_id` (`parent_map_id`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;