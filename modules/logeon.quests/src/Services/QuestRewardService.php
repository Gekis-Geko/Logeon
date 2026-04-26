<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class QuestRewardService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var CharacterProfileService */
    private $profileService;
    /** @var InventoryService */
    private $inventoryService;
    /** @var array<string,bool> */
    private $tableExistsCache = [];

    public function __construct(
        DbAdapterInterface $db = null,
        CharacterProfileService $profileService = null,
        InventoryService $inventoryService = null,
    ) {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->profileService = $profileService ?: new CharacterProfileService($this->db);
        $this->inventoryService = $inventoryService ?: new InventoryService($this->db);
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

    private function rowToArray($row): array
    {
        if (is_object($row)) {
            return (array) $row;
        }
        return is_array($row) ? $row : [];
    }

    private function tableExists(string $table): bool
    {
        $table = trim($table);
        if ($table === '') {
            return false;
        }
        if (array_key_exists($table, $this->tableExistsCache)) {
            return $this->tableExistsCache[$table];
        }

        $row = $this->firstPrepared(
            'SELECT 1 AS ok
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?
             LIMIT 1',
            [$table],
        );
        $exists = !empty($row);
        $this->tableExistsCache[$table] = $exists;
        return $exists;
    }

    public function ensureSchema(): void
    {
        if (!$this->tableExists('quest_reward_assignments')) {
            throw AppError::validation(
                'Tabella ricompense quest non disponibile. Allinea il database con database/logeon_db_core.sql.',
                [],
                'quest_reward_invalid',
            );
        }
    }

    private function ensureInstanceExists(int $instanceId): array
    {
        $row = $this->firstPrepared(
            'SELECT *
             FROM quest_instances
             WHERE id = ?
             LIMIT 1',
            [$instanceId],
        );
        if (empty($row)) {
            throw AppError::notFound('Istanza quest non trovata', [], 'quest_not_found');
        }
        return $this->rowToArray($row);
    }

    private function normalizeVisibility(string $value): string
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['public', 'player_private', 'staff_only'], true)) {
            return $value;
        }
        return 'public';
    }

    private function normalizeRecipientType(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === 'group') {
            $value = 'guild';
        }
        $allowed = ['character', 'guild', 'faction', 'group'];
        return in_array($value, $allowed, true) ? $value : 'character';
    }

    private function normalizeRewardType(string $value): string
    {
        $value = strtolower(trim($value));
        $allowed = [
            'experience',
            'item',
            'currency',
            'reputation',
            'faction_influence',
            'narrative_unlock',
            'state_assignment',
            'custom',
        ];
        if (in_array($value, $allowed, true)) {
            return $value;
        }
        return 'custom';
    }

    private function parsePositiveDecimal($value): float
    {
        $raw = trim(str_replace(',', '.', (string) $value));
        if ($raw === '' || !is_numeric($raw)) {
            return 0.0;
        }
        $out = round((float) $raw, 2);
        return $out > 0 ? $out : 0.0;
    }

    private function parsePositiveInt($value): int
    {
        if (!is_numeric((string) $value)) {
            return 0;
        }
        $n = (int) $value;
        return $n > 0 ? $n : 0;
    }

    private function resolveActorUserId(int $actorCharacterId): int
    {
        if ($actorCharacterId <= 0) {
            return 0;
        }
        $row = $this->firstPrepared(
            'SELECT user_id
             FROM characters
             WHERE id = ?
             LIMIT 1',
            [$actorCharacterId],
        );
        if (empty($row) || !isset($row->user_id)) {
            return 0;
        }
        return (int) ($row->user_id ?? 0);
    }

    private function rewardLabel(array $row): string
    {
        $type = strtolower(trim((string) ($row['reward_type'] ?? '')));
        if ($type === 'experience') {
            $value = (float) ($row['reward_value'] ?? 0);
            return number_format($value, 2, ',', '.') . ' EXP';
        }

        if ($type === 'item') {
            $value = (int) ($row['reward_value'] ?? 0);
            if ($value <= 0) {
                $value = 1;
            }
            $itemName = trim((string) ($row['item_name'] ?? ''));
            if ($itemName === '') {
                $itemId = (int) ($row['reward_reference_id'] ?? 0);
                $itemName = $itemId > 0 ? ('Oggetto #' . $itemId) : 'Oggetto';
            }
            return $value . 'x ' . $itemName;
        }

        $typeLabel = trim((string) ($row['reward_type'] ?? 'ricompensa'));
        $value = (string) ($row['reward_value'] ?? '');
        if ($value !== '') {
            return ucfirst($typeLabel) . ': ' . $value;
        }
        return ucfirst($typeLabel);
    }

    private function decodeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $recipientName = trim((string) ($item['recipient_character_name'] ?? '') . ' ' . (string) ($item['recipient_character_surname'] ?? ''));
            if ($recipientName === '') {
                $recipientType = (string) ($item['recipient_type'] ?? 'character');
                $recipientId = (int) ($item['recipient_id'] ?? 0);
                $recipientName = ucfirst($recipientType) . ($recipientId > 0 ? (' #' . $recipientId) : '');
            }
            $item['recipient_label'] = $recipientName;

            $assignedByName = trim((string) ($item['assigned_by_name'] ?? '') . ' ' . (string) ($item['assigned_by_surname'] ?? ''));
            $item['assigned_by_label'] = $assignedByName !== '' ? $assignedByName : null;
            $item['reward_label'] = $this->rewardLabel($item);

            $out[] = $item;
        }
        return $out;
    }

    private function insertRewardRow(
        int $instanceId,
        string $recipientType,
        int $recipientId,
        string $rewardType,
        ?int $rewardReferenceId,
        ?float $rewardValue,
        int $actorCharacterId,
        string $visibility,
        ?string $notes,
    ): int {
        $recipientIdOrNull = $recipientId > 0 ? $recipientId : null;
        $rewardReferenceIdOrNull = ($rewardReferenceId !== null && $rewardReferenceId > 0) ? $rewardReferenceId : null;
        $rewardValueOrNull = $rewardValue !== null ? (float) number_format($rewardValue, 2, '.', '') : null;
        $assignedByOrNull = $actorCharacterId > 0 ? $actorCharacterId : null;
        $notesOrNull = ($notes !== null && trim($notes) !== '') ? trim($notes) : null;

        $this->execPrepared(
            'INSERT INTO quest_reward_assignments
                (quest_instance_id, recipient_type, recipient_id, reward_type, reward_reference_id, reward_value, assigned_by, assigned_at, visibility, notes)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)',
            [
                $instanceId,
                $recipientType,
                $recipientIdOrNull,
                $rewardType,
                $rewardReferenceIdOrNull,
                $rewardValueOrNull,
                $assignedByOrNull,
                $visibility,
                $notesOrNull,
            ],
        );

        return $this->db->lastInsertId();
    }

    public function assign(array $payload, int $actorCharacterId = 0, bool $applyRuntime = true): array
    {
        $this->ensureSchema();

        $instanceId = (int) ($payload['quest_instance_id'] ?? $payload['instance_id'] ?? 0);
        if ($instanceId <= 0) {
            throw AppError::validation('Istanza quest non valida', [], 'quest_reward_invalid');
        }
        $this->ensureInstanceExists($instanceId);

        $recipientType = $this->normalizeRecipientType((string) ($payload['recipient_type'] ?? 'character'));
        $recipientId = $this->parsePositiveInt($payload['recipient_id'] ?? 0);
        if ($recipientType === 'character' && $recipientId <= 0) {
            throw AppError::validation('Destinatario ricompensa non valido', [], 'quest_reward_invalid');
        }

        $rewardType = $this->normalizeRewardType((string) ($payload['reward_type'] ?? ''));
        $visibility = $this->normalizeVisibility((string) ($payload['visibility'] ?? 'public'));
        $notes = isset($payload['notes']) ? trim((string) $payload['notes']) : null;
        if ($notes === '') {
            $notes = null;
        }

        $rewardReferenceId = null;
        $rewardValue = null;
        $runtimeResult = [];

        if ($rewardType === 'experience') {
            if ($recipientType !== 'character') {
                throw AppError::validation('La ricompensa esperienza supporta solo destinatario personaggio', [], 'quest_reward_unsupported');
            }
            $amount = $this->parsePositiveDecimal($payload['reward_value'] ?? ($payload['amount'] ?? 0));
            if ($amount <= 0) {
                throw AppError::validation('Importo esperienza non valido', [], 'quest_reward_invalid');
            }

            if ($applyRuntime) {
                $authorUserId = $this->resolveActorUserId($actorCharacterId);
                $reason = trim((string) ($payload['reason'] ?? $notes ?? ('Ricompensa quest #' . $instanceId)));
                if ($reason === '') {
                    $reason = 'Ricompensa quest #' . $instanceId;
                }
                $runtimeResult = $this->profileService->assignExperience(
                    $recipientId,
                    $authorUserId,
                    $amount,
                    $reason,
                    'quest_reward',
                );
            }

            $rewardValue = $amount;
        } elseif ($rewardType === 'item') {
            if ($recipientType !== 'character') {
                throw AppError::validation('La ricompensa oggetto supporta solo destinatario personaggio', [], 'quest_reward_unsupported');
            }
            $itemId = $this->parsePositiveInt($payload['reward_reference_id'] ?? ($payload['item_id'] ?? 0));
            $quantity = $this->parsePositiveInt($payload['reward_value'] ?? ($payload['quantity'] ?? 1));
            if ($quantity <= 0) {
                $quantity = 1;
            }
            if ($itemId <= 0) {
                throw AppError::validation('Oggetto ricompensa non valido', [], 'quest_reward_invalid');
            }

            if ($applyRuntime) {
                try {
                    $runtimeResult = $this->inventoryService->grantItemReward($recipientId, $itemId, $quantity);
                } catch (\Throwable $runtimeError) {
                    $message = strtolower((string) $runtimeError->getMessage());
                    if (strpos($message, 'uniq_inventory_items_owner_item') === false) {
                        throw $runtimeError;
                    }

                    // Fallback difensivo: se la grant_item legacy fallisce su unique key,
                    // forza incremento della riga inventory_items gia esistente.
                    $this->execPrepared(
                        'UPDATE inventory_items SET
                            quantity = quantity + ?,
                            updated_at = NOW()
                         WHERE owner_id = ?
                           AND item_id = ?
                         LIMIT 1',
                        [$quantity, $recipientId, $itemId],
                    );

                    $affected = $this->firstPrepared('SELECT ROW_COUNT() AS n');
                    $count = !empty($affected) ? (int) ($affected->n ?? 0) : 0;
                    $runtimeResult = [
                        'effect' => 'grant_item',
                        'applied' => ($count > 0),
                        'item_id' => $itemId,
                        'quantity' => $quantity,
                        'fallback' => ($count > 0 ? 'inventory_items_update' : 'duplicate_conflict_unresolved'),
                    ];
                }
            }

            $rewardReferenceId = $itemId;
            $rewardValue = (float) $quantity;
        } else {
            throw AppError::validation('Tipo ricompensa non supportato in v1', [], 'quest_reward_unsupported');
        }

        $rewardId = $this->insertRewardRow(
            $instanceId,
            $recipientType,
            $recipientId,
            $rewardType,
            $rewardReferenceId,
            $rewardValue,
            $actorCharacterId,
            $visibility,
            $notes,
        );

        $record = $this->getById($rewardId);
        $record['runtime_result'] = $runtimeResult;
        return $record;
    }

    public function remove(int $rewardId, int $instanceId = 0): array
    {
        $this->ensureSchema();
        $rewardId = (int) $rewardId;
        if ($rewardId <= 0) {
            throw AppError::validation('Ricompensa non valida', [], 'quest_reward_invalid');
        }
        $row = $this->getById($rewardId);
        if ($instanceId > 0 && (int) ($row['quest_instance_id'] ?? 0) !== (int) $instanceId) {
            throw AppError::validation('Ricompensa non valida per questa istanza', [], 'quest_reward_invalid');
        }

        $this->execPrepared(
            'DELETE FROM quest_reward_assignments
             WHERE id = ?
             LIMIT 1',
            [$rewardId],
        );

        return [
            'deleted' => 1,
            'reward_id' => $rewardId,
            'warning' => 'La rimozione elimina solo il tracciamento, non annulla gli effetti gia assegnati.',
        ];
    }

    public function getById(int $rewardId): array
    {
        $this->ensureSchema();
        $rewardId = (int) $rewardId;
        if ($rewardId <= 0) {
            throw AppError::validation('Ricompensa non valida', [], 'quest_reward_invalid');
        }

        $row = $this->firstPrepared(
            'SELECT r.*,
                    rc.name AS recipient_character_name,
                    rc.surname AS recipient_character_surname,
                    ac.name AS assigned_by_name,
                    ac.surname AS assigned_by_surname,
                    i.name AS item_name
             FROM quest_reward_assignments r
             LEFT JOIN characters rc ON rc.id = r.recipient_id AND r.recipient_type = "character"
             LEFT JOIN characters ac ON ac.id = r.assigned_by
             LEFT JOIN items i ON i.id = r.reward_reference_id AND r.reward_type = "item"
             WHERE r.id = ?
             LIMIT 1',
            [$rewardId],
        );

        if (empty($row)) {
            throw AppError::notFound('Ricompensa non trovata', [], 'quest_reward_invalid');
        }

        $items = $this->decodeRows([$row]);
        return $items[0] ?? [];
    }

    public function listByInstance(int $instanceId, int $viewerCharacterId = 0, bool $isStaff = false, int $limit = 200, int $page = 1): array
    {
        $this->ensureSchema();
        $instanceId = (int) $instanceId;
        $limit = max(1, min(200, (int) $limit));
        $page = max(1, (int) $page);
        if ($instanceId <= 0) {
            return ['total' => 0, 'page' => $page, 'limit' => $limit, 'rows' => []];
        }

        $where = ['r.quest_instance_id = ?'];
        $params = [$instanceId];
        if (!$isStaff) {
            $viewerCharacterId = (int) $viewerCharacterId;
            if ($viewerCharacterId > 0) {
                $where[] = '(r.visibility = "public" OR (r.visibility = "player_private" AND r.recipient_type = "character" AND r.recipient_id = ?))';
                $params[] = $viewerCharacterId;
            } else {
                $where[] = 'r.visibility = "public"';
            }
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $offset = ($page - 1) * $limit;

        $totalRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n
             FROM quest_reward_assignments r
             ' . $whereSql,
            $params,
        );
        $total = (int) ($totalRow->n ?? 0);

        $rows = $this->fetchPrepared(
            'SELECT r.*,
                    rc.name AS recipient_character_name,
                    rc.surname AS recipient_character_surname,
                    ac.name AS assigned_by_name,
                    ac.surname AS assigned_by_surname,
                    i.name AS item_name
             FROM quest_reward_assignments r
             LEFT JOIN characters rc ON rc.id = r.recipient_id AND r.recipient_type = "character"
             LEFT JOIN characters ac ON ac.id = r.assigned_by
             LEFT JOIN items i ON i.id = r.reward_reference_id AND r.reward_type = "item"
             ' . $whereSql . '
             ORDER BY r.id DESC
             LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset]),
        );

        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'rows' => $this->decodeRows($rows),
        ];
    }
}
