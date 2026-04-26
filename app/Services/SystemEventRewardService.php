<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class SystemEventRewardService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var CurrencyService */
    private $currencyService;

    public function __construct(DbAdapterInterface $db = null, CurrencyService $currencyService = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->currencyService = $currencyService ?: new CurrencyService($this->db);
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

    private function decodeRow(array $row): array
    {
        if (isset($row['meta_json']) && is_string($row['meta_json'])) {
            $decoded = json_decode($row['meta_json'], true);
            $row['meta_json'] = is_array($decoded) ? $decoded : (object) [];
        }
        return $row;
    }

    public function assignCurrencyReward(
        int $eventId,
        int $characterId,
        int $currencyId,
        int $amount,
        int $actorCharacterId = 0,
        string $source = 'manual',
        array $meta = [],
        int $participationId = 0,
    ): array {
        $eventId = (int) $eventId;
        $characterId = (int) $characterId;
        $currencyId = (int) $currencyId;
        $amount = (int) $amount;
        if ($eventId <= 0) {
            throw AppError::notFound('Evento di sistema non trovato', [], 'system_event_not_found');
        }
        if ($characterId <= 0 || $currencyId <= 0 || $amount <= 0) {
            throw AppError::validation('Dati ricompensa non validi', [], 'system_event_reward_invalid');
        }

        $credit = $this->currencyService->credit(
            $characterId,
            $currencyId,
            $amount,
            'system_event_reward_' . trim($source),
            [
                'event_id' => $eventId,
                'source' => $source,
            ],
        );
        if (empty($credit['ok'])) {
            throw AppError::validation('Impossibile assegnare la ricompensa in valuta', $credit, 'system_event_reward_invalid');
        }

        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($metaJson)) {
            $metaJson = '{}';
        }

        $this->execPrepared(
            'INSERT INTO system_event_reward_logs
             (system_event_id, participation_id, character_id, currency_id, amount, source, meta_json, awarded_by_character_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $eventId,
                ($participationId > 0 ? (int) $participationId : null),
                $characterId,
                $currencyId,
                $amount,
                (trim($source) !== '' ? trim($source) : 'manual'),
                $metaJson,
                ($actorCharacterId > 0 ? (int) $actorCharacterId : null),
            ],
        );

        $id = (int) $this->db->lastInsertId();
        return $this->getRewardLog($id);
    }

    public function listRewardLogs(int $eventId, int $limit = 50, int $page = 1): array
    {
        $eventId = (int) $eventId;
        $limit = max(1, min(200, (int) $limit));
        $page = max(1, (int) $page);
        if ($eventId <= 0) {
            return ['total' => 0, 'page' => $page, 'limit' => $limit, 'rows' => []];
        }

        $offset = ($page - 1) * $limit;

        $totalRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n
             FROM system_event_reward_logs
             WHERE system_event_id = ?',
            [$eventId],
        );
        $total = (int) ($totalRow->n ?? 0);

        $rows = $this->fetchPrepared(
            'SELECT l.*,
                    c.name AS character_name,
                    c.surname AS character_surname,
                    cur.name AS currency_name,
                    cur.code AS currency_code
             FROM system_event_reward_logs l
             LEFT JOIN characters c ON c.id = l.character_id
             LEFT JOIN currencies cur ON cur.id = l.currency_id
             WHERE l.system_event_id = ?
             ORDER BY l.id DESC
             LIMIT ? OFFSET ?',
            [$eventId, $limit, $offset],
        );

        $out = [];
        foreach ($rows as $row) {
            $record = $this->decodeRow($this->rowToArray($row));
            $fullName = trim((string) ($record['character_name'] ?? '') . ' ' . (string) ($record['character_surname'] ?? ''));
            $record['character_label'] = $fullName !== '' ? $fullName : ('Personaggio #' . (int) ($record['character_id'] ?? 0));
            $out[] = $record;
        }

        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'rows' => $out,
        ];
    }

    private function getRewardLog(int $id): array
    {
        $row = $this->firstPrepared(
            'SELECT l.*,
                    c.name AS character_name,
                    c.surname AS character_surname,
                    cur.name AS currency_name,
                    cur.code AS currency_code
             FROM system_event_reward_logs l
             LEFT JOIN characters c ON c.id = l.character_id
             LEFT JOIN currencies cur ON cur.id = l.currency_id
             WHERE l.id = ?
             LIMIT 1',
            [(int) $id],
        );
        if (empty($row)) {
            throw AppError::notFound('Log ricompensa non trovato', [], 'system_event_reward_invalid');
        }
        $record = $this->decodeRow($this->rowToArray($row));
        $fullName = trim((string) ($record['character_name'] ?? '') . ' ' . (string) ($record['character_surname'] ?? ''));
        $record['character_label'] = $fullName !== '' ? $fullName : ('Personaggio #' . (int) ($record['character_id'] ?? 0));
        return $record;
    }
}
