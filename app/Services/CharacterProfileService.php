<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\HtmlSanitizer;
use Core\Http\AppError;

class CharacterProfileService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var NotificationService */
    private $notifService;
    /** @var array<string,string> */
    private $requestReviewerColumnCache = [];

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->notifService = new NotificationService($this->db);
    }

    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    private function fetchPrepared(string $sql, array $params = []): array
    {
        return $this->db->fetchAllPrepared($sql, $params);
    }

    private function execPrepared(string $sql, array $params = []): bool
    {
        return $this->db->executePrepared($sql, $params);
    }

    private function begin(): void
    {
        $this->db->query('START TRANSACTION');
    }

    private function commit(): void
    {
        $this->db->query('COMMIT');
    }

    private function rollback(): void
    {
        try {
            $this->db->query('ROLLBACK');
        } catch (\Throwable $e) {
            // no-op: rollback best effort
        }
    }

    private function hasValue($value): bool
    {
        if ($value === null) {
            return false;
        }

        return trim((string) $value) !== '';
    }

    private function normalizeString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return ($value === '') ? null : $value;
    }

    private function validateUrl($value): ?string
    {
        $value = $this->normalizeString($value);
        if ($value === null) {
            return null;
        }

        if (strpos($value, 'http://') === 0 || strpos($value, 'https://') === 0 || strpos($value, '/') === 0) {
            return $value;
        }

        throw AppError::validation('URL non valido', [], 'profile_url_invalid');
    }

    private function compareLower(string $a, string $b): bool
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($a, 'UTF-8') === mb_strtolower($b, 'UTF-8');
        }

        return strtolower($a) === strtolower($b);
    }

    private function stringLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }

    private function isSafeSqlIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1;
    }

    private function tableHasColumn(string $tableName, string $columnName): bool
    {
        if (!$this->isSafeSqlIdentifier($tableName) || !$this->isSafeSqlIdentifier($columnName)) {
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT 1 AS n
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1',
            [$tableName, $columnName],
        );

        return !empty($row);
    }

    private function resolveRequestReviewerColumn(string $tableName): string
    {
        if (isset($this->requestReviewerColumnCache[$tableName])) {
            return (string) $this->requestReviewerColumnCache[$tableName];
        }

        $column = '';
        if ($this->tableHasColumn($tableName, 'reviewed_by')) {
            $column = 'reviewed_by';
        } elseif ($this->tableHasColumn($tableName, 'moderator_id')) {
            $column = 'moderator_id';
        }

        $this->requestReviewerColumnCache[$tableName] = $column;
        return $column;
    }

    public function updateProfile(int $characterId, object $data): array
    {
        $current = $this->firstPrepared(
            'SELECT surname, loanface, height, weight, eyes, hair, skin, particular_signs
            FROM characters
            WHERE id = ?',
            [$characterId],
        );

        if (empty($current)) {
            throw AppError::notFound('Personaggio non trovato', [], 'character_not_found');
        }

        $updates = [];
        $params = [];

        if (!$this->hasValue($current->surname)) {
            $value = $this->normalizeString($data->surname ?? null);
            if ($value !== null) {
                $updates[] = 'surname = ?';
                $params[] = $value;
            }
        }

        if (!$this->hasValue($current->loanface)) {
            $value = $this->normalizeString($data->loanface ?? null);
            if ($value !== null) {
                $updates[] = 'loanface = ?';
                $params[] = $value;
            }
        }

        if (!$this->hasValue($current->height)) {
            $value = $this->normalizeString($data->height ?? null);
            if ($value !== null) {
                $normalized = str_replace(',', '.', $value);
                if (!is_numeric($normalized)) {
                    throw AppError::validation('Altezza non valida', [], 'profile_height_invalid');
                }

                $numeric = (float) $normalized;
                if ($numeric < 0.5 || $numeric > 3.0) {
                    throw AppError::validation('Altezza non valida', [], 'profile_height_invalid');
                }

                $updates[] = 'height = ?';
                $params[] = $numeric;
            }
        }

        if (!$this->hasValue($current->weight)) {
            $value = $this->normalizeString($data->weight ?? null);
            if ($value !== null) {
                $normalized = str_replace(',', '.', $value);
                if (!is_numeric($normalized)) {
                    throw AppError::validation('Peso non valido', [], 'profile_weight_invalid');
                }

                $numeric = (float) $normalized;
                if ($numeric < 20 || $numeric > 500) {
                    throw AppError::validation('Peso non valido', [], 'profile_weight_invalid');
                }

                $updates[] = 'weight = ?';
                $params[] = (int) $numeric;
            }
        }

        if (!$this->hasValue($current->eyes)) {
            $value = $this->normalizeString($data->eyes ?? null);
            if ($value !== null) {
                $updates[] = 'eyes = ?';
                $params[] = $value;
            }
        }

        if (!$this->hasValue($current->hair)) {
            $value = $this->normalizeString($data->hair ?? null);
            if ($value !== null) {
                $updates[] = 'hair = ?';
                $params[] = $value;
            }
        }

        if (!$this->hasValue($current->skin)) {
            $value = $this->normalizeString($data->skin ?? null);
            if ($value !== null) {
                $updates[] = 'skin = ?';
                $params[] = $value;
            }
        }

        if (!$this->hasValue($current->particular_signs)) {
            $value = $this->normalizeString($data->particular_signs ?? null);
            if ($value !== null) {
                $updates[] = 'particular_signs = ?';
                $params[] = $value;
            }
        }

        if (property_exists($data, 'description_body')) {
            $value = $this->normalizeString($data->description_body ?? null);
            if ($value !== null) {
                $value = HtmlSanitizer::sanitize($value, ['allow_images' => true]);
            }
            $updates[] = 'description_body = ?';
            $params[] = $value;
        }

        if (property_exists($data, 'description_temper')) {
            $value = $this->normalizeString($data->description_temper ?? null);
            if ($value !== null) {
                $value = HtmlSanitizer::sanitize($value, ['allow_images' => true]);
            }
            $updates[] = 'description_temper = ?';
            $params[] = $value;
        }

        if (property_exists($data, 'background_story')) {
            $value = $this->normalizeString($data->background_story ?? null);
            if ($value !== null) {
                $value = HtmlSanitizer::sanitize($value, ['allow_images' => true]);
            }
            $updates[] = 'background_story = ?';
            $params[] = $value;
        }

        if (property_exists($data, 'avatar')) {
            $updates[] = 'avatar = ?';
            $params[] = $this->validateUrl($data->avatar ?? null);
        }

        if (property_exists($data, 'cover')) {
            $updates[] = 'cover = ?';
            $params[] = $this->validateUrl($data->cover ?? null);
        }

        if (property_exists($data, 'background_music_url')) {
            $updates[] = 'background_music_url = ?';
            $params[] = $this->validateUrl($data->background_music_url ?? null);
        }

        if (empty($updates)) {
            return ['updated' => false];
        }

        $params[] = $characterId;
        $this->execPrepared(
            'UPDATE characters SET
                ' . implode(",\n                ", $updates) . '
            WHERE id = ?',
            $params,
        );

        return ['updated' => true];
    }
    private function isSafeIdentifier(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $name) === 1;
    }

    private function tableExists(string $table): bool
    {
        $table = trim($table);
        if ($table === '' || !$this->isSafeIdentifier($table)) {
            return false;
        }

        try {
            $row = $this->firstPrepared(
                'SELECT 1 AS n
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                 LIMIT 1',
                [$table],
            );
            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $table = trim($table);
        $column = trim($column);
        if ($table === '' || $column === '' || !$this->isSafeIdentifier($table) || !$this->isSafeIdentifier($column)) {
            return false;
        }

        return $this->tableHasColumn($table, $column);
    }

    private function normalizeDecimalValue($value, string $label, string $errorCode, bool $allowZero = true): float
    {
        $raw = trim(str_replace(',', '.', (string) ($value ?? '')));
        if ($raw === '' || !is_numeric($raw)) {
            throw AppError::validation(ucfirst($label) . ' non valida', [], $errorCode);
        }

        $number = round((float) $raw, 2);
        if ($allowZero) {
            if ($number < 0) {
                throw AppError::validation(ucfirst($label) . ' non valida', [], $errorCode);
            }
        } else {
            if ($number <= 0) {
                throw AppError::validation(ucfirst($label) . ' non valida', [], $errorCode);
            }
        }

        return $number;
    }

    private function resolveCharacterUserId(int $characterId): ?int
    {
        $row = $this->firstPrepared(
            'SELECT user_id
             FROM characters
             WHERE id = ?
             LIMIT 1',
            [$characterId],
        );

        if (empty($row) || !isset($row->user_id)) {
            return null;
        }

        return (int) $row->user_id;
    }

    private function normalizePage(int $page): int
    {
        if ($page <= 0) {
            return 1;
        }

        return $page;
    }

    private function normalizeResults(int $results, int $fallback = 10, int $max = 100): int
    {
        if ($results <= 0) {
            $results = $fallback;
        }
        if ($results > $max) {
            $results = $max;
        }

        return $results;
    }

    private function normalizeOrderBy($orderBy, array $allowedFields, string $defaultField, string $defaultDirection = 'DESC'): array
    {
        if (is_array($orderBy) && !empty($orderBy)) {
            $orderBy = reset($orderBy);
        }

        $raw = trim((string) $orderBy);
        if ($raw === '') {
            $raw = $defaultField . '|' . $defaultDirection;
        }

        $parts = explode('|', $raw, 2);
        $field = strtolower(trim((string) $parts[0]));
        if ($field === '' || !array_key_exists($field, $allowedFields)) {
            $field = $defaultField;
        }

        $direction = strtoupper(trim((string) ($parts[1] ?? $defaultDirection)));
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            $direction = strtoupper($defaultDirection) === 'ASC' ? 'ASC' : 'DESC';
        }

        return [
            'field' => $field,
            'direction' => $direction,
            'sql' => $allowedFields[$field] . ' ' . $direction,
            'orderBy' => $field . '|' . $direction,
        ];
    }

    private function buildPagedResult(array $dataset, int $count, int $page, int $results, string $orderBy): array
    {
        return [
            'count' => (object) ['count' => max(0, (int) $count)],
            'dataset' => $dataset,
            'page' => $this->normalizePage($page),
            'results' => $this->normalizeResults($results),
            'orderBy' => $orderBy,
        ];
    }

    private function emptyPagedResult(int $page, int $results, string $orderBy): array
    {
        return $this->buildPagedResult([], 0, $page, $results, $orderBy);
    }

    public function listExperienceLogs(int $characterId, int $page = 1, int $results = 10, $orderBy = 'date_created|DESC'): array
    {
        $page = $this->normalizePage($page);
        $results = $this->normalizeResults($results, 10, 100);
        $order = $this->normalizeOrderBy($orderBy, [
            'date_created' => 'el.date_created',
            'id' => 'el.id',
        ], 'date_created', 'DESC');

        if (!$this->tableExists('experience_logs')) {
            return $this->emptyPagedResult($page, $results, $order['orderBy']);
        }

        $offset = ($page - 1) * $results;

        try {
            $countRow = $this->firstPrepared(
                'SELECT COUNT(*) AS count
                 FROM experience_logs el
                 WHERE el.character_id = ?',
                [$characterId],
            );
            $totalCount = isset($countRow->count) ? (int) $countRow->count : 0;

            $rows = $this->fetchPrepared(
                'SELECT el.id,
                        el.character_id,
                        el.experience_before,
                        el.experience_after,
                        el.delta,
                        el.reason,
                        el.source,
                        el.author_id,
                        el.date_created,
                        (SELECT c2.name
                         FROM characters c2
                         WHERE c2.user_id = el.author_id
                         ORDER BY c2.id ASC
                         LIMIT 1) AS author_username
                 FROM experience_logs el
                 WHERE el.character_id = ?
                 ORDER BY ' . $order['sql'] . ', el.id ' . $order['direction'] . '
                 LIMIT ? OFFSET ?',
                [$characterId, $results, $offset],
            );

            return $this->buildPagedResult($this->enrichExperienceLogs($rows), $totalCount, $page, $results, $order['orderBy']);
        } catch (\Throwable $e) {
            return $this->emptyPagedResult($page, $results, $order['orderBy']);
        }
    }

    public function listEconomyLogs(int $characterId, int $page = 1, int $results = 10, $orderBy = 'date_created|DESC'): array
    {
        $page = $this->normalizePage($page);
        $results = $this->normalizeResults($results, 10, 100);
        $order = $this->normalizeOrderBy($orderBy, [
            'date_created' => 'cl.date_created',
            'id' => 'cl.id',
        ], 'date_created', 'DESC');

        if (!$this->tableExists('currency_logs')) {
            return $this->emptyPagedResult($page, $results, $order['orderBy']);
        }

        $offset = ($page - 1) * $results;

        try {
            $countRow = $this->firstPrepared(
                'SELECT COUNT(*) AS count
                 FROM currency_logs cl
                 WHERE cl.character_id = ?',
                [$characterId],
            );
            $totalCount = isset($countRow->count) ? (int) $countRow->count : 0;

            $rows = $this->fetchPrepared(
                'SELECT cl.id,
                        cl.character_id,
                        cl.currency_id,
                        cl.account,
                        cl.amount,
                        cl.balance_before,
                        cl.balance_after,
                        cl.source,
                        cl.meta,
                        cl.date_created,
                        c.name AS currency_name,
                        c.code AS currency_code,
                        c.symbol AS currency_symbol,
                        c.image AS currency_image
                 FROM currency_logs cl
                 LEFT JOIN currencies c ON c.id = cl.currency_id
                 WHERE cl.character_id = ?
                 ORDER BY ' . $order['sql'] . ', cl.id ' . $order['direction'] . '
                 LIMIT ? OFFSET ?',
                [$characterId, $results, $offset],
            );
        } catch (\Throwable $e) {
            return $this->emptyPagedResult($page, $results, $order['orderBy']);
        }

        return $this->buildPagedResult($this->enrichEconomyLogs($rows), $totalCount, $page, $results, $order['orderBy']);
    }

    private function enrichExperienceLogs(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        foreach ($rows as $row) {
            $row->source_label = $this->translateExperienceSource($row->source ?? null);
        }

        return $rows;
    }

    private function translateExperienceSource($source): string
    {
        $value = strtolower(trim((string) ($source ?? '')));
        if ($value === 'staff_assignment') {
            return 'assegnazione staff';
        }
        if ($value === 'manual') {
            return 'manuale';
        }
        if ($value === '') {
            return '-';
        }

        return $value;
    }

    private function enrichEconomyLogs(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $metaByIndex = [];
        $shopIds = [];
        $itemIds = [];

        foreach ($rows as $index => $row) {
            $meta = $this->decodeMetaPayload($row->meta ?? null);
            $metaByIndex[$index] = $meta;

            if (isset($meta['shop_id']) && is_numeric($meta['shop_id'])) {
                $shopId = (int) $meta['shop_id'];
                if ($shopId > 0) {
                    $shopIds[$shopId] = true;
                }
            }

            if (isset($meta['item_id']) && is_numeric($meta['item_id'])) {
                $itemId = (int) $meta['item_id'];
                if ($itemId > 0) {
                    $itemIds[$itemId] = true;
                }
            }
        }

        $shopNames = $this->resolveShopNames(array_keys($shopIds));
        $itemNames = $this->resolveItemNames(array_keys($itemIds));

        foreach ($rows as $index => $row) {
            $meta = $metaByIndex[$index] ?? [];
            $row->source_label = $this->translateEconomySource($row->source ?? null);
            $row->currency_label = !empty($row->currency_name) ? (string) $row->currency_name : '-';
            $row->meta_label = $this->buildEconomyMetaLabel($meta, $shopNames, $itemNames);
        }

        return $rows;
    }

    private function decodeMetaPayload($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (!is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function resolveShopNames(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $ids = array_values(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        }));

        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->fetchPrepared(
            'SELECT id, name
             FROM shops
             WHERE id IN (' . $placeholders . ')',
            $ids,
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->id] = (string) $row->name;
        }

        return $map;
    }

    private function resolveItemNames(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $ids = array_values(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        }));

        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->fetchPrepared(
            'SELECT id, name
             FROM items
             WHERE id IN (' . $placeholders . ')',
            $ids,
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->id] = (string) $row->name;
        }

        return $map;
    }

    private function translateEconomySource($source): string
    {
        $value = strtolower(trim((string) ($source ?? '')));
        if ($value === 'shop_buy') {
            return 'acquisto';
        }
        if ($value === 'shop_sell') {
            return 'vendita';
        }
        if ($value === '') {
            return '-';
        }

        return $value;
    }

    private function formatMetaNumber($value): string
    {
        if (!is_numeric($value)) {
            return '-';
        }

        $numeric = (float) $value;
        $decimals = (floor($numeric) == $numeric) ? 0 : 2;

        return number_format($numeric, $decimals, ',', '.');
    }

    private function buildEconomyMetaLabel(array $meta, array $shopNames, array $itemNames): string
    {
        if (empty($meta)) {
            return '-';
        }

        $parts = [];

        if (isset($meta['shop_id']) && is_numeric($meta['shop_id'])) {
            $shopId = (int) $meta['shop_id'];
            $shopName = $shopNames[$shopId] ?? ('#' . $shopId);
            $parts[] = 'Negozio: <b>' . $shopName . '</b>';
        }

        if (isset($meta['item_id']) && is_numeric($meta['item_id'])) {
            $itemId = (int) $meta['item_id'];
            $itemName = $itemNames[$itemId] ?? ('#' . $itemId);
            $quantity = 1;
            if (isset($meta['quantity']) && is_numeric($meta['quantity']) && (int) $meta['quantity'] > 0) {
                $quantity = (int) $meta['quantity'];
            }
            $parts[] = 'Oggetto: <b>' . $itemName . ' x' . $this->formatMetaNumber($quantity) . '</b>';
        }

        if (isset($meta['unit_price']) && is_numeric($meta['unit_price'])) {
            $parts[] = 'Prezzo unità: <b>' . $this->formatMetaNumber($meta['unit_price']) . '</b>';
        }

        if (empty($parts)) {
            return '-';
        }

        return implode('<br>', $parts);
    }

    public function listSigninSignoutLogs(int $characterId, int $page = 1, int $results = 10, $orderBy = 'date_created|DESC'): array
    {
        $page = $this->normalizePage($page);
        $results = $this->normalizeResults($results, 10, 100);
        $order = $this->normalizeOrderBy($orderBy, [
            'date_created' => 'sl.date_created',
            'id' => 'sl.id',
        ], 'date_created', 'DESC');

        if (!$this->tableExists('sys_logs')) {
            return $this->emptyPagedResult($page, $results, $order['orderBy']);
        }

        $userId = $this->resolveCharacterUserId($characterId);
        if ($userId === null || $userId <= 0) {
            throw AppError::notFound('Personaggio non trovato', [], 'character_not_found');
        }

        $offset = ($page - 1) * $results;

        try {
            $countRow = $this->firstPrepared(
                'SELECT COUNT(*) AS count
                 FROM sys_logs sl
                 WHERE sl.author = ?
                   AND sl.module = "system"
                   AND sl.action IN ("signin", "signout")',
                [$userId],
            );
            $totalCount = isset($countRow->count) ? (int) $countRow->count : 0;

            $rows = $this->fetchPrepared(
                'SELECT sl.id,
                        sl.author,
                        sl.url,
                        sl.area,
                        sl.module,
                        sl.action,
                        sl.data,
                        sl.date_created
                 FROM sys_logs sl
                 WHERE sl.author = ?
                   AND sl.module = "system"
                   AND sl.action IN ("signin", "signout")
                 ORDER BY ' . $order['sql'] . ', sl.id ' . $order['direction'] . '
                 LIMIT ? OFFSET ?',
                [$userId, $results, $offset],
            );

            return $this->buildPagedResult($rows, $totalCount, $page, $results, $order['orderBy']);
        } catch (\Throwable $e) {
            return $this->emptyPagedResult($page, $results, $order['orderBy']);
        }
    }

    public function updateHealth(int $characterId, $health, $healthMax = null): array
    {
        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        if (!$this->columnExists('characters', 'health') || !$this->columnExists('characters', 'health_max')) {
            throw AppError::validation('Colonne salute non disponibili. Allinea il database con database/logeon_db_core.sql.', [], 'profile_health_columns_missing');
        }

        $current = $this->firstPrepared(
            'SELECT id, health, health_max
             FROM characters
             WHERE id = ?
             LIMIT 1',
            [$characterId],
        );

        if (empty($current)) {
            throw AppError::notFound('Personaggio non trovato', [], 'character_not_found');
        }

        if ($health === null || trim((string) $health) === '') {
            $nextHealth = $this->normalizeDecimalValue($current->health ?? 100, 'salute', 'profile_health_invalid', true);
        } else {
            $nextHealth = $this->normalizeDecimalValue($health, 'salute', 'profile_health_invalid', true);
        }
        if ($healthMax === null || trim((string) $healthMax) === '') {
            $nextHealthMax = $this->normalizeDecimalValue($current->health_max ?? 100, 'salute massima', 'profile_health_max_invalid', false);
        } else {
            $nextHealthMax = $this->normalizeDecimalValue($healthMax, 'salute massima', 'profile_health_max_invalid', false);
        }

        if ($nextHealth > $nextHealthMax) {
            throw AppError::validation('La salute attuale non puo superare la salute massima', [], 'profile_health_above_max');
        }

        $previousHealth = round((float) ($current->health ?? 0), 2);
        $previousHealthMax = round((float) ($current->health_max ?? 100), 2);

        $updated = (abs($previousHealth - $nextHealth) > 0.0001) || (abs($previousHealthMax - $nextHealthMax) > 0.0001);
        if ($updated) {
            $this->execPrepared(
                'UPDATE characters SET
                    health = ?,
                    health_max = ?
                 WHERE id = ?',
                [number_format($nextHealth, 2, '.', ''), number_format($nextHealthMax, 2, '.', ''), $characterId],
            );
        }

        return [
            'character_id' => $characterId,
            'health' => $nextHealth,
            'health_max' => $nextHealthMax,
            'previous_health' => $previousHealth,
            'previous_health_max' => $previousHealthMax,
            'updated' => $updated,
        ];
    }

    public function assignExperience(int $characterId, int $authorUserId, $delta, $reason, string $source = 'manual'): array
    {
        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        if (!$this->tableExists('experience_logs')) {
            throw AppError::validation('Tabella experience_logs non disponibile. Allinea il database con database/logeon_db_core.sql.', [], 'experience_logs_missing');
        }

        $deltaValue = $this->normalizeDecimalValue($delta, 'quantita esperienza', 'profile_experience_delta_invalid', false);
        $reasonValue = $this->normalizeString($reason);
        if ($reasonValue === null) {
            throw AppError::validation('Motivazione non valida', [], 'profile_experience_reason_invalid');
        }
        if ($this->stringLength($reasonValue) > 255) {
            throw AppError::validation('Motivazione troppo lunga', [], 'profile_experience_reason_too_long');
        }

        $sourceValue = $this->normalizeString($source);
        if ($sourceValue === null) {
            $sourceValue = 'manual';
        }

        $this->begin();
        try {
            $current = $this->firstPrepared(
                'SELECT id, experience
                 FROM characters
                 WHERE id = ?
                 LIMIT 1
                 FOR UPDATE',
                [$characterId],
            );

            if (empty($current)) {
                throw AppError::notFound('Personaggio non trovato', [], 'character_not_found');
            }

            $experienceBefore = round((float) ($current->experience ?? 0), 2);
            $experienceAfter = round($experienceBefore + $deltaValue, 2);

            $this->execPrepared(
                'UPDATE characters SET
                    experience = ?
                 WHERE id = ?',
                [number_format($experienceAfter, 2, '.', ''), $characterId],
            );

            $this->execPrepared(
                'INSERT INTO experience_logs
                    (character_id, experience_before, experience_after, delta, reason, source, author_id, date_created)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $characterId,
                    number_format($experienceBefore, 2, '.', ''),
                    number_format($experienceAfter, 2, '.', ''),
                    number_format($deltaValue, 2, '.', ''),
                    $reasonValue,
                    $sourceValue,
                    ($authorUserId > 0 ? $authorUserId : null),
                ],
            );

            $this->commit();

            return [
                'character_id' => $characterId,
                'delta' => $deltaValue,
                'reason' => $reasonValue,
                'source' => $sourceValue,
                'experience_before' => $experienceBefore,
                'experience_after' => $experienceAfter,
                'updated' => true,
            ];
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function updateMasterNotes(int $characterId, $modStatus): array
    {
        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $current = $this->firstPrepared(
            'SELECT id, mod_status
             FROM characters
             WHERE id = ?
             LIMIT 1',
            [$characterId],
        );

        if (empty($current)) {
            throw AppError::notFound('Personaggio non trovato', [], 'character_not_found');
        }

        $normalized = $this->normalizeString($modStatus);
        if ($normalized !== null && $this->stringLength($normalized) > 5000) {
            throw AppError::validation('Note del master troppo lunghe', [], 'profile_master_notes_too_long');
        }

        $previous = $this->normalizeString($current->mod_status ?? null);
        $updated = $previous !== $normalized;

        if ($updated) {
            $this->execPrepared(
                'UPDATE characters SET
                    mod_status = ?
                 WHERE id = ?',
                [$normalized, $characterId],
            );
        }

        return [
            'character_id' => $characterId,
            'mod_status' => $normalized,
            'previous_mod_status' => $previous,
            'updated' => $updated,
        ];
    }
    public function setAvailability(int $characterId, object $data): void
    {
        if (!isset($data->availability)) {
            throw AppError::validation('Dati mancanti', [], 'payload_missing');
        }

        $availability = (int) $data->availability;
        if (!in_array($availability, [0, 1, 2, 3], true)) {
            throw AppError::validation('Disponibilita non valida', [], 'availability_invalid');
        }

        $this->execPrepared(
            'UPDATE characters SET
                availability = ?,
                date_last_seed = NOW()
            WHERE id = ?',
            [$availability, $characterId],
        );
    }

    public function updateSettings(int $characterId, object $data): void
    {
        $dmPolicy = isset($data->dm_policy) ? (int) $data->dm_policy : 0;
        if (!in_array($dmPolicy, [0, 1, 2], true)) {
            throw AppError::validation('Valore DM non valido', [], 'dm_policy_invalid');
        }

        $invitePolicy = isset($data->invite_policy) ? (int) $data->invite_policy : 0;
        if (!in_array($invitePolicy, [0, 1, 2], true)) {
            throw AppError::validation('Valore inviti non valido', [], 'invite_policy_invalid');
        }

        $notifyMessages = !empty($data->notify_messages) ? 1 : 0;
        $notifyInvites = !empty($data->notify_invites) ? 1 : 0;
        $notifyNews = !empty($data->notify_news) ? 1 : 0;

        $this->execPrepared(
            'UPDATE characters SET
                dm_policy = ?,
                invite_policy = ?,
                notify_messages = ?,
                notify_invites = ?,
                notify_news = ?,
                date_last_seed = NOW()
            WHERE id = ?',
            [$dmPolicy, $invitePolicy, $notifyMessages, $notifyInvites, $notifyNews, $characterId],
        );
    }

    public function requestNameChange(int $characterId, int $userId, string $newName, ?string $reason, int $cooldownDays): void
    {
        $newName = trim($newName);
        $reason = $this->normalizeString($reason);
        if ($newName === '') {
            throw AppError::validation('Nome non valido', [], 'character_name_invalid');
        }

        if ($this->stringLength($newName) > 25) {
            throw AppError::validation('Nome troppo lungo', [], 'character_name_too_long');
        }

        if ($cooldownDays <= 0) {
            $cooldownDays = 30;
        }

        $this->begin();
        try {
            $current = $this->firstPrepared(
                'SELECT name
                FROM characters
                WHERE id = ?
                LIMIT 1
                FOR UPDATE',
                [$characterId],
            );

            if (empty($current)) {
                throw AppError::notFound('Personaggio non trovato', [], 'character_not_found');
            }

            if ($this->compareLower((string) $current->name, $newName)) {
                throw AppError::validation('Il nome inserito e uguale a quello attuale', [], 'character_name_same_as_current');
            }

            $exists = $this->firstPrepared(
                'SELECT id
                FROM characters
                WHERE LOWER(name) = LOWER(?)
                  AND id <> ?
                LIMIT 1',
                [$newName, $characterId],
            );
            if (!empty($exists)) {
                throw AppError::validation('Nome gia in uso', [], 'character_name_already_used');
            }

            $pending = $this->firstPrepared(
                'SELECT id
                FROM character_name_requests
                WHERE character_id = ?
                  AND status = "pending"
                LIMIT 1
                FOR UPDATE',
                [$characterId],
            );
            if (!empty($pending)) {
                throw AppError::validation('Esiste gia una richiesta in attesa', [], 'character_name_request_pending');
            }

            $lastApproved = $this->firstPrepared(
                'SELECT date_resolved
                FROM character_name_requests
                WHERE character_id = ?
                  AND status = "approved"
                ORDER BY date_resolved DESC
                LIMIT 1',
                [$characterId],
            );

            if (!empty($lastApproved) && !empty($lastApproved->date_resolved)) {
                $cooldown = $this->firstPrepared(
                    'SELECT DATEDIFF(NOW(), ?) AS days',
                    [$lastApproved->date_resolved],
                );

                if (!empty($cooldown) && (int) $cooldown->days < $cooldownDays) {
                    throw AppError::validation(
                        'Devi attendere ' . $cooldownDays . ' giorni prima di richiedere un nuovo cambio nome',
                        [],
                        'character_name_request_cooldown',
                    );
                }
            }

            $this->execPrepared(
                'INSERT INTO character_name_requests
                    (character_id, user_id, current_name, new_name, reason, status, date_created)
                 VALUES
                    (?, ?, ?, ?, ?, "pending", NOW())',
                [$characterId, $userId, (string) $current->name, $newName, $reason],
            );

            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function requestLoanfaceChange(int $characterId, int $userId, string $newLoanface, ?string $reason, int $cooldownDays): void
    {
        $newLoanface = trim($newLoanface);
        $reason = $this->normalizeString($reason);
        if ($newLoanface === '') {
            throw AppError::validation('Volto prestato non valido', [], 'character_loanface_invalid');
        }

        if ($cooldownDays <= 0) {
            $cooldownDays = 30;
        }

        $this->begin();
        try {
            $current = $this->firstPrepared(
                'SELECT loanface
                FROM characters
                WHERE id = ?
                LIMIT 1
                FOR UPDATE',
                [$characterId],
            );

            if (empty($current)) {
                throw AppError::notFound('Personaggio non trovato', [], 'character_not_found');
            }

            if ($this->compareLower((string) ($current->loanface ?? ''), $newLoanface)) {
                throw AppError::validation('Il volto prestato è uguale a quello attuale', [], 'character_loanface_same_as_current');
            }

            $pending = $this->firstPrepared(
                'SELECT id
                FROM character_loanface_requests
                WHERE character_id = ?
                  AND status = "pending"
                LIMIT 1
                FOR UPDATE',
                [$characterId],
            );
            if (!empty($pending)) {
                throw AppError::validation('Esiste gia una richiesta in attesa', [], 'character_loanface_request_pending');
            }

            $lastApproved = $this->firstPrepared(
                'SELECT date_resolved
                FROM character_loanface_requests
                WHERE character_id = ?
                  AND status = "approved"
                ORDER BY date_resolved DESC
                LIMIT 1',
                [$characterId],
            );

            if (!empty($lastApproved) && !empty($lastApproved->date_resolved)) {
                $cooldown = $this->firstPrepared(
                    'SELECT DATEDIFF(NOW(), ?) AS days',
                    [$lastApproved->date_resolved],
                );

                if (!empty($cooldown) && (int) $cooldown->days < $cooldownDays) {
                    throw AppError::validation(
                        'Devi attendere ' . $cooldownDays . ' giorni prima di richiedere un nuovo cambio volto prestato',
                        [],
                        'character_loanface_request_cooldown',
                    );
                }
            }

            $this->execPrepared(
                'INSERT INTO character_loanface_requests
                    (character_id, user_id, current_loanface, new_loanface, reason, status, date_created)
                 VALUES
                    (?, ?, ?, ?, ?, "pending", NOW())',
                [$characterId, $userId, ($current->loanface ?? null), $newLoanface, $reason],
            );

            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function adminDecideNameChange(int $requestId, int $adminUserId, string $decision): array
    {
        $decision = strtolower(trim($decision));
        if ($decision !== 'approved' && $decision !== 'rejected') {
            throw AppError::validation('Decisione non valida', [], 'decision_invalid');
        }

        $this->begin();
        try {
            $request = $this->firstPrepared(
                'SELECT *
                FROM character_name_requests
                WHERE id = ?
                  AND status = "pending"
                LIMIT 1
                FOR UPDATE',
                [$requestId],
            );

            if (empty($request)) {
                throw AppError::notFound('Richiesta non trovata o gia elaborata', [], 'name_request_not_found');
            }

            if ($decision === 'approved') {
                $this->execPrepared(
                    'UPDATE characters SET name = ?
                     WHERE id = ?',
                    [(string) $request->new_name, (int) $request->character_id],
                );
            }

            $reviewerColumn = $this->resolveRequestReviewerColumn('character_name_requests');
            $sql = 'UPDATE character_name_requests SET status = ?, date_resolved = NOW()';
            $params = [$decision];
            if ($reviewerColumn !== '') {
                $sql .= ', ' . $reviewerColumn . ' = ?';
                $params[] = $adminUserId;
            }
            $sql .= ' WHERE id = ?';
            $params[] = $requestId;
            $this->execPrepared($sql, $params);

            $this->commit();

            // Notify character owner
            $ownerUserId = $this->resolveCharacterUserId((int) $request->character_id);
            if ($ownerUserId !== null && $ownerUserId > 0) {
                $label = ($decision === 'approved') ? 'approvata' : 'rifiutata';
                $this->notifService->create(
                    $ownerUserId,
                    (int) $request->character_id,
                    NotificationService::KIND_DECISION_RESULT,
                    'name_change_result',
                    'Richiesta cambio nome ' . $label,
                    [
                        'source_type' => 'name_request',
                        'source_id' => $requestId,
                        'action_url' => '/game/profile',
                    ],
                );
            }

            return ['request_id' => $requestId, 'decision' => $decision];
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function adminDecideLoanfaceChange(int $requestId, int $adminUserId, string $decision): array
    {
        $decision = strtolower(trim($decision));
        if ($decision !== 'approved' && $decision !== 'rejected') {
            throw AppError::validation('Decisione non valida', [], 'decision_invalid');
        }

        $this->begin();
        try {
            $request = $this->firstPrepared(
                'SELECT *
                FROM character_loanface_requests
                WHERE id = ?
                  AND status = "pending"
                LIMIT 1
                FOR UPDATE',
                [$requestId],
            );

            if (empty($request)) {
                throw AppError::notFound('Richiesta non trovata o gia elaborata', [], 'loanface_request_not_found');
            }

            if ($decision === 'approved') {
                $this->execPrepared(
                    'UPDATE characters SET loanface = ?
                     WHERE id = ?',
                    [(string) $request->new_loanface, (int) $request->character_id],
                );
            }

            $reviewerColumn = $this->resolveRequestReviewerColumn('character_loanface_requests');
            $sql = 'UPDATE character_loanface_requests SET status = ?, date_resolved = NOW()';
            $params = [$decision];
            if ($reviewerColumn !== '') {
                $sql .= ', ' . $reviewerColumn . ' = ?';
                $params[] = $adminUserId;
            }
            $sql .= ' WHERE id = ?';
            $params[] = $requestId;
            $this->execPrepared($sql, $params);

            $this->commit();

            // Notify character owner
            $ownerUserId = $this->resolveCharacterUserId((int) $request->character_id);
            if ($ownerUserId !== null && $ownerUserId > 0) {
                $label = ($decision === 'approved') ? 'approvata' : 'rifiutata';
                $this->notifService->create(
                    $ownerUserId,
                    (int) $request->character_id,
                    NotificationService::KIND_DECISION_RESULT,
                    'loanface_change_result',
                    'Richiesta cambio volto prestato ' . $label,
                    [
                        'source_type' => 'loanface_request',
                        'source_id' => $requestId,
                        'action_url' => '/game/profile',
                    ],
                );
            }

            return ['request_id' => $requestId, 'decision' => $decision];
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Admin: list pending change requests
    // -------------------------------------------------------------------------

    public function adminListNameRequests(string $status = 'pending', int $limit = 50, int $page = 1): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, ($page - 1) * $limit);

        $where = '';
        $params = [];
        if ($status !== 'all') {
            $where = 'WHERE cnr.status = ?';
            $params[] = $status;
        }
        $reviewerColumn = $this->resolveRequestReviewerColumn('character_name_requests');
        $reviewerSelect = ($reviewerColumn !== '')
            ? 'cnr.' . $reviewerColumn . ' AS reviewed_by'
            : 'NULL AS reviewed_by';

        $rows = $this->fetchPrepared(
            'SELECT cnr.id, cnr.character_id, cnr.user_id, cnr.current_name, cnr.new_name,
                    cnr.reason, cnr.status, ' . $reviewerSelect . ', cnr.date_created, cnr.date_resolved,
                    c.name AS character_name
             FROM character_name_requests cnr
             LEFT JOIN characters c ON c.id = cnr.character_id
             ' . $where . '
             ORDER BY cnr.date_created ASC
             LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset]),
        );

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n FROM character_name_requests cnr ' . $where,
            $params,
        );

        return [
            'rows' => !empty($rows) ? $rows : [],
            'total' => (int) ($countRow->n ?? 0),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    public function adminUpdateIdentity(int $characterId, object $data): void
    {
        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $updates = [];
        $params = [];

        $surname = $this->normalizeString($data->surname ?? null);
        if ($surname !== null) {
            $updates[] = 'surname = ?';
            $params[] = $surname;
        }

        $loanface = $this->normalizeString($data->loanface ?? null);
        if ($loanface !== null) {
            $updates[] = 'loanface = ?';
            $params[] = $loanface;
        }

        if (isset($data->height) && trim((string) $data->height) !== '') {
            $h = (float) str_replace(',', '.', trim((string) $data->height));
            if ($h < 0.5 || $h > 3.0) {
                throw AppError::validation('Altezza non valida', [], 'profile_height_invalid');
            }
            $updates[] = 'height = ?';
            $params[] = $h;
        }

        if (isset($data->weight) && trim((string) $data->weight) !== '') {
            $w = (int) trim((string) $data->weight);
            if ($w < 20 || $w > 500) {
                throw AppError::validation('Peso non valido', [], 'profile_weight_invalid');
            }
            $updates[] = 'weight = ?';
            $params[] = $w;
        }

        $eyes = $this->normalizeString($data->eyes ?? null);
        $hair = $this->normalizeString($data->hair ?? null);
        $skin = $this->normalizeString($data->skin ?? null);
        $signs = $this->normalizeString($data->particular_signs ?? null);
        if ($eyes !== null) {
            $updates[] = 'eyes = ?';
            $params[] = $eyes;
        }
        if ($hair !== null) {
            $updates[] = 'hair = ?';
            $params[] = $hair;
        }
        if ($skin !== null) {
            $updates[] = 'skin = ?';
            $params[] = $skin;
        }
        if ($signs !== null) {
            $updates[] = 'particular_signs = ?';
            $params[] = $signs;
        }

        if (!empty($updates)) {
            $params[] = $characterId;
            $this->execPrepared('UPDATE characters SET ' . implode(', ', $updates) . ' WHERE id = ?', $params);
        }
    }

    public function adminUpdateNarrative(int $characterId, object $data): void
    {
        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $updates = [];
        $params = [];
        if (property_exists($data, 'description_body')) {
            $updates[] = 'description_body = ?';
            $params[] = (isset($data->description_body) && trim((string) $data->description_body) !== '' ? (string) $data->description_body : null);
        }
        if (property_exists($data, 'description_temper')) {
            $updates[] = 'description_temper = ?';
            $params[] = (isset($data->description_temper) && trim((string) $data->description_temper) !== '' ? (string) $data->description_temper : null);
        }
        if (property_exists($data, 'background_story')) {
            $updates[] = 'background_story = ?';
            $params[] = (isset($data->background_story) && trim((string) $data->background_story) !== '' ? (string) $data->background_story : null);
        }

        if (!empty($updates)) {
            $params[] = $characterId;
            $this->execPrepared('UPDATE characters SET ' . implode(', ', $updates) . ' WHERE id = ?', $params);
        }
    }

    public function adminUpdateEconomy(int $characterId, object $data): void
    {
        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $updates = [];
        $params = [];

        if (property_exists($data, 'money_delta') && trim((string) $data->money_delta) !== '') {
            $delta = (int) $data->money_delta;
            $updates[] = 'money = GREATEST(0, money + ?)';
            $params[] = $delta;
        }
        if (property_exists($data, 'bank_delta') && trim((string) $data->bank_delta) !== '') {
            $delta = (int) $data->bank_delta;
            $updates[] = 'bank = GREATEST(0, bank + ?)';
            $params[] = $delta;
        }
        if (property_exists($data, 'fame_set') && trim((string) $data->fame_set) !== '') {
            $fame = (float) $data->fame_set;
            $updates[] = 'fame = ?';
            $params[] = $fame;
        }

        if (!empty($updates)) {
            $params[] = $characterId;
            $this->execPrepared('UPDATE characters SET ' . implode(', ', $updates) . ' WHERE id = ?', $params);
        }
    }

    public function adminUpdateRank(int $characterId, int $rank): void
    {
        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }
        if ($rank < 1) {
            $rank = 1;
        }
        $this->execPrepared('UPDATE characters SET rank = ? WHERE id = ?', [$rank, $characterId]);
    }

    public function requestIdentityChange(int $characterId, int $userId, object $data): void
    {
        if ($characterId <= 0 || $userId <= 0) {
            throw AppError::validation('Dati non validi', [], 'identity_request_invalid');
        }

        $fields = ['new_surname', 'new_height', 'new_weight', 'new_eyes', 'new_hair', 'new_skin'];
        $hasAny = false;
        foreach ($fields as $f) {
            $v = isset($data->$f) ? trim((string) $data->$f) : '';
            if ($v !== '') {
                $hasAny = true;
                break;
            }
        }
        if (!$hasAny) {
            throw AppError::validation('Nessun campo da modificare', [], 'identity_request_empty');
        }

        $this->begin();
        try {
            $pending = $this->firstPrepared(
                'SELECT id FROM character_identity_requests
                 WHERE character_id = ?
                   AND status = "pending"
                 LIMIT 1 FOR UPDATE',
                [$characterId],
            );
            if (!empty($pending)) {
                throw AppError::validation('Esiste gia una richiesta in attesa', [], 'identity_request_pending');
            }

            $surname = $this->normalizeString($data->new_surname ?? null);
            $eyes = $this->normalizeString($data->new_eyes ?? null);
            $hair = $this->normalizeString($data->new_hair ?? null);
            $skin = $this->normalizeString($data->new_skin ?? null);
            $reason = $this->normalizeString($data->reason ?? null);

            $heightRaw = isset($data->new_height) ? trim((string) $data->new_height) : '';
            $height = null;
            if ($heightRaw !== '') {
                $heightN = (float) str_replace(',', '.', $heightRaw);
                if ($heightN < 0.5 || $heightN > 3.0) {
                    throw AppError::validation('Altezza non valida', [], 'identity_request_height_invalid');
                }
                $height = $heightN;
            }

            $weightRaw = isset($data->new_weight) ? trim((string) $data->new_weight) : '';
            $weight = null;
            if ($weightRaw !== '') {
                $weightN = (int) $weightRaw;
                if ($weightN < 20 || $weightN > 500) {
                    throw AppError::validation('Peso non valido', [], 'identity_request_weight_invalid');
                }
                $weight = $weightN;
            }

            $this->execPrepared(
                'INSERT INTO character_identity_requests
                    (character_id, user_id, new_surname, new_height, new_weight, new_eyes, new_hair, new_skin, reason, status, date_created)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", NOW())',
                [$characterId, $userId, $surname, $height, $weight, $eyes, $hair, $skin, $reason],
            );

            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function adminDecideIdentityChange(int $requestId, int $reviewerId, string $decision): array
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw AppError::validation('Decisione non valida', [], 'decision_invalid');
        }

        $this->begin();
        try {
            $request = $this->firstPrepared(
                'SELECT * FROM character_identity_requests
                 WHERE id = ?
                   AND status = "pending"
                 LIMIT 1 FOR UPDATE',
                [$requestId],
            );

            if (empty($request)) {
                throw AppError::notFound('Richiesta non trovata o gia elaborata', [], 'identity_request_not_found');
            }

            if ($decision === 'approved') {
                $updates = [];
                $updateParams = [];
                if (!empty($request->new_surname)) {
                    $updates[] = 'surname = ?';
                    $updateParams[] = (string) $request->new_surname;
                }
                if ($request->new_height !== null) {
                    $updates[] = 'height = ?';
                    $updateParams[] = (float) $request->new_height;
                }
                if ($request->new_weight !== null) {
                    $updates[] = 'weight = ?';
                    $updateParams[] = (int) $request->new_weight;
                }
                if (!empty($request->new_eyes)) {
                    $updates[] = 'eyes = ?';
                    $updateParams[] = (string) $request->new_eyes;
                }
                if (!empty($request->new_hair)) {
                    $updates[] = 'hair = ?';
                    $updateParams[] = (string) $request->new_hair;
                }
                if (!empty($request->new_skin)) {
                    $updates[] = 'skin = ?';
                    $updateParams[] = (string) $request->new_skin;
                }
                if (!empty($updates)) {
                    $updateParams[] = (int) $request->character_id;
                    $this->execPrepared(
                        'UPDATE characters SET ' . implode(', ', $updates) .
                        ' WHERE id = ?',
                        $updateParams,
                    );
                }
            }

            $reviewerColumn = $this->resolveRequestReviewerColumn('character_identity_requests');
            $sql = 'UPDATE character_identity_requests SET status = ?, date_resolved = NOW()';
            $params = [$decision];
            if ($reviewerColumn !== '') {
                $sql .= ', ' . $reviewerColumn . ' = ?';
                $params[] = $reviewerId;
            }
            $sql .= ' WHERE id = ?';
            $params[] = $requestId;
            $this->execPrepared($sql, $params);

            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }

        return ['request_id' => $requestId, 'decision' => $decision];
    }

    public function adminListIdentityRequests(string $status = 'pending', int $limit = 50, int $page = 1): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, ($page - 1) * $limit);

        $where = '';
        $params = [];
        if ($status !== 'all') {
            $where = 'WHERE cir.status = ?';
            $params[] = $status;
        }
        $reviewerColumn = $this->resolveRequestReviewerColumn('character_identity_requests');
        $reviewerSelect = ($reviewerColumn !== '')
            ? 'cir.' . $reviewerColumn . ' AS reviewed_by'
            : 'NULL AS reviewed_by';

        $rows = $this->fetchPrepared(
            'SELECT cir.id, cir.character_id, cir.user_id,
                    cir.new_surname, cir.new_height, cir.new_weight,
                    cir.new_eyes, cir.new_hair, cir.new_skin,
                    cir.reason, cir.status, ' . $reviewerSelect . ', cir.date_created, cir.date_resolved,
                    c.name AS character_name,
                    c.surname AS current_surname,
                    c.height AS current_height, c.weight AS current_weight,
                    c.eyes AS current_eyes, c.hair AS current_hair, c.skin AS current_skin
             FROM character_identity_requests cir
             LEFT JOIN characters c ON c.id = cir.character_id
             ' . $where . '
             ORDER BY cir.date_created ASC
             LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset]),
        );

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n FROM character_identity_requests cir ' . $where,
            $params,
        );

        return [
            'rows' => !empty($rows) ? $rows : [],
            'total' => (int) ($countRow->n ?? 0),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    public function adminListLoanfaceRequests(string $status = 'pending', int $limit = 50, int $page = 1): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, ($page - 1) * $limit);

        $where = '';
        $params = [];
        if ($status !== 'all') {
            $where = 'WHERE clr.status = ?';
            $params[] = $status;
        }
        $reviewerColumn = $this->resolveRequestReviewerColumn('character_loanface_requests');
        $reviewerSelect = ($reviewerColumn !== '')
            ? 'clr.' . $reviewerColumn . ' AS reviewed_by'
            : 'NULL AS reviewed_by';

        $rows = $this->fetchPrepared(
            'SELECT clr.id, clr.character_id, clr.user_id, clr.current_loanface, clr.new_loanface,
                    clr.reason, clr.status, ' . $reviewerSelect . ', clr.date_created, clr.date_resolved,
                    c.name AS character_name
             FROM character_loanface_requests clr
             LEFT JOIN characters c ON c.id = clr.character_id
             ' . $where . '
             ORDER BY clr.date_created ASC
             LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset]),
        );

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n FROM character_loanface_requests clr ' . $where,
            $params,
        );

        return [
            'rows' => !empty($rows) ? $rows : [],
            'total' => (int) ($countRow->n ?? 0),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    public function getFriendsKnowledgeHtml(int $characterId): ?string
    {
        $row = $this->firstPrepared(
            'SELECT friends_knowledge_html FROM characters WHERE id = ? LIMIT 1',
            [$characterId],
        );
        if (empty($row)) {
            return null;
        }
        $raw = $row->friends_knowledge_html ?? null;
        return $raw !== null ? (string) $raw : null;
    }

    public function updateFriendsKnowledgeHtml(int $characterId, $html): void
    {
        $value = ($html !== null && trim((string) $html) !== '') ? trim((string) $html) : null;
        $this->execPrepared(
            'UPDATE characters SET friends_knowledge_html = ? WHERE id = ? LIMIT 1',
            [$value, $characterId],
        );
    }
}
