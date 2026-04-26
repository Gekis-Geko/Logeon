<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class SystemEventEffectService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    private function fetchPrepared(string $sql, array $params = []): array
    {
        return $this->db->fetchAllPrepared($sql, $params);
    }

    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    private function decodeRow(array $row): array
    {
        if (isset($row['meta_json']) && is_string($row['meta_json'])) {
            $decoded = json_decode($row['meta_json'], true);
            $row['meta_json'] = is_array($decoded) ? $decoded : (object) [];
        }
        return $row;
    }

    public function listByEvent(int $eventId): array
    {
        $eventId = (int) $eventId;
        if ($eventId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT e.*, c.name AS currency_name, c.code AS currency_code
             FROM system_event_effects e
             LEFT JOIN currencies c ON c.id = e.currency_id
             WHERE e.system_event_id = ?
             ORDER BY e.id DESC',
            [$eventId],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->decodeRow(is_object($row) ? (array) $row : $row);
        }
        return $out;
    }

    public function upsert(int $eventId, array $data, int $actorCharacterId = 0): array
    {
        $eventId = (int) $eventId;
        if ($eventId <= 0) {
            throw AppError::notFound('Evento di sistema non trovato', [], 'system_event_not_found');
        }

        $effectId = (int) ($data['id'] ?? $data['effect_id'] ?? 0);
        $effectType = strtolower(trim((string) ($data['effect_type'] ?? 'currency_reward')));
        if ($effectType !== 'currency_reward') {
            throw AppError::validation('Effetto non supportato', [], 'system_event_effect_unsupported');
        }

        $currencyId = (int) ($data['currency_id'] ?? 0);
        $amount = (int) ($data['amount'] ?? 0);
        if ($currencyId <= 0 || $amount <= 0) {
            throw AppError::validation('Valuta e importo sono obbligatori', [], 'system_event_reward_invalid');
        }

        $isEnabled = (int) ($data['is_enabled'] ?? 1) === 1 ? 1 : 0;
        $meta = $data['meta_json'] ?? [];
        $metaJson = json_encode(is_array($meta) ? $meta : (array) $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($metaJson)) {
            $metaJson = '{}';
        }

        if ($effectId > 0) {
            $existing = $this->firstPrepared(
                'SELECT id
                 FROM system_event_effects
                 WHERE id = ? AND system_event_id = ?
                 LIMIT 1',
                [$effectId, $eventId],
            );
            if (empty($existing)) {
                throw AppError::notFound('Effetto evento non trovato', [], 'system_event_not_found');
            }

            $this->execPrepared(
                'UPDATE system_event_effects SET
                    effect_type = ?,
                    currency_id = ?,
                    amount = ?,
                    is_enabled = ?,
                    meta_json = ?,
                    updated_by = ?
                 WHERE id = ?',
                [
                    $effectType,
                    $currencyId,
                    $amount,
                    $isEnabled,
                    $metaJson,
                    $actorCharacterId > 0 ? (int) $actorCharacterId : null,
                    $effectId,
                ],
            );
        } else {
            $this->execPrepared(
                'INSERT INTO system_event_effects
                 (system_event_id, effect_type, currency_id, amount, is_enabled, meta_json, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $eventId,
                    $effectType,
                    $currencyId,
                    $amount,
                    $isEnabled,
                    $metaJson,
                    $actorCharacterId > 0 ? (int) $actorCharacterId : null,
                    $actorCharacterId > 0 ? (int) $actorCharacterId : null,
                ],
            );
            $effectId = (int) $this->db->lastInsertId();
        }

        return $this->getEffect($effectId);
    }

    public function delete(int $effectId, int $eventId = 0): array
    {
        $effectId = (int) $effectId;
        if ($effectId <= 0) {
            throw AppError::validation('Effetto non valido', [], 'system_event_not_found');
        }

        if ($eventId > 0) {
            $this->execPrepared(
                'DELETE FROM system_event_effects
                 WHERE id = ? AND system_event_id = ?',
                [$effectId, (int) $eventId],
            );
        } else {
            $this->execPrepared(
                'DELETE FROM system_event_effects
                 WHERE id = ?',
                [$effectId],
            );
        }

        return ['deleted' => 1];
    }

    public function copyEffectsToEvent(int $sourceEventId, int $targetEventId, int $actorCharacterId = 0): int
    {
        $sourceEventId = (int) $sourceEventId;
        $targetEventId = (int) $targetEventId;
        if ($sourceEventId <= 0 || $targetEventId <= 0) {
            return 0;
        }

        $rows = $this->fetchPrepared(
            'SELECT effect_type, currency_id, amount, is_enabled, meta_json
             FROM system_event_effects
             WHERE system_event_id = ?',
            [$sourceEventId],
        );
        $count = 0;
        foreach ($rows as $row) {
            $data = [
                'effect_type' => (string) ($row->effect_type ?? 'currency_reward'),
                'currency_id' => (int) ($row->currency_id ?? 0),
                'amount' => (int) ($row->amount ?? 0),
                'is_enabled' => (int) ($row->is_enabled ?? 1),
                'meta_json' => json_decode((string) ($row->meta_json ?? '{}'), true),
            ];
            try {
                $this->upsert($targetEventId, $data, $actorCharacterId);
                $count++;
            } catch (\Throwable $e) {
                // Non interrompere la copia se un record non valido.
            }
        }

        return $count;
    }

    private function getEffect(int $effectId): array
    {
        $row = $this->firstPrepared(
            'SELECT e.*, c.name AS currency_name, c.code AS currency_code
             FROM system_event_effects e
             LEFT JOIN currencies c ON c.id = e.currency_id
             WHERE e.id = ?
             LIMIT 1',
            [(int) $effectId],
        );

        if (empty($row)) {
            throw AppError::notFound('Effetto evento non trovato', [], 'system_event_not_found');
        }

        return $this->decodeRow(is_object($row) ? (array) $row : (is_array($row) ? $row : []));
    }
}
