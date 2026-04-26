<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class SystemEventResolverService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var NotificationService */
    private $notificationService;
    /** @var NarrativeEventService */
    private $narrativeEventService;
    /** @var SystemEventRewardService */
    private $rewardService;
    /** @var SystemEventParticipationService */
    private $participationService;
    /** @var SystemEventEffectService */
    private $effectService;

    /** @var int */
    private static $lastMaintenanceRunAt = 0;

    public function __construct(
        DbAdapterInterface $db = null,
        NotificationService $notificationService = null,
        NarrativeEventService $narrativeEventService = null,
        SystemEventRewardService $rewardService = null,
        SystemEventParticipationService $participationService = null,
        SystemEventEffectService $effectService = null,
    ) {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->notificationService = $notificationService ?: new NotificationService($this->db);
        $this->narrativeEventService = $narrativeEventService ?: new NarrativeEventService($this->db);
        $this->rewardService = $rewardService ?: new SystemEventRewardService($this->db);
        $this->participationService = $participationService ?: new SystemEventParticipationService($this->db);
        $this->effectService = $effectService ?: new SystemEventEffectService($this->db);
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

    private function rowToArray($row): array
    {
        if (is_object($row)) {
            return (array) $row;
        }
        return is_array($row) ? $row : [];
    }

    private function decodeEvent(array $row): array
    {
        if (isset($row['meta_json']) && is_string($row['meta_json'])) {
            $decoded = json_decode($row['meta_json'], true);
            $row['meta_json'] = is_array($decoded) ? $decoded : (object) [];
        }
        return $row;
    }

    private function getConfig(string $key, string $fallback = ''): string
    {
        $row = $this->firstPrepared(
            'SELECT `value`
             FROM sys_configs
             WHERE `key` = ?
             LIMIT 1',
            [$key],
        );
        if (empty($row)) {
            return $fallback;
        }
        return trim((string) ($row->value ?? $fallback));
    }

    private function isFeatureEnabled(): bool
    {
        $raw = $this->getConfig('system_events_enabled', '1');
        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    public function runMaintenance(bool $force = false): array
    {
        if (!$this->isFeatureEnabled()) {
            return ['skipped' => 'disabled', 'activated_ids' => [], 'completed_ids' => [], 'generated_ids' => []];
        }

        $intervalMinutes = (int) $this->getConfig('system_events_maintenance_interval_minutes', '5');
        if ($intervalMinutes < 1) {
            $intervalMinutes = 5;
        }

        $now = time();
        if (!$force && self::$lastMaintenanceRunAt > 0) {
            if (($now - self::$lastMaintenanceRunAt) < ($intervalMinutes * 60)) {
                return ['skipped' => 'interval', 'activated_ids' => [], 'completed_ids' => [], 'generated_ids' => []];
            }
        }

        self::$lastMaintenanceRunAt = $now;

        $activatedIds = [];
        $completedIds = [];
        $generatedIds = [];

        $scheduledRows = $this->fetchPrepared(
            "SELECT *
             FROM system_events
             WHERE status = 'scheduled'
               AND starts_at IS NOT NULL
               AND starts_at <= NOW()
             ORDER BY starts_at ASC, id ASC",
        );
        foreach ($scheduledRows as $row) {
            $event = $this->decodeEvent($this->rowToArray($row));
            $eventId = (int) ($event['id'] ?? 0);
            if ($eventId <= 0) {
                continue;
            }
            $this->execPrepared(
                "UPDATE system_events
                 SET status = 'active',
                     last_activity_at = NOW(),
                     next_run_at = NULL
                 WHERE id = ? AND status = 'scheduled'",
                [$eventId],
            );
            $changedRow = $this->firstPrepared('SELECT ROW_COUNT() AS n');
            $changed = (int) ($changedRow->n ?? 0);
            if ($changed > 0) {
                $activatedIds[] = $eventId;
                $result = $this->handleStatusTransition($eventId, 'scheduled', 'active', false);
                if (!empty($result['generated_event_id'])) {
                    $generatedIds[] = (int) $result['generated_event_id'];
                }
            }
        }

        $activeRows = $this->fetchPrepared(
            "SELECT *
             FROM system_events
             WHERE status = 'active'
               AND ends_at IS NOT NULL
               AND ends_at <= NOW()
             ORDER BY ends_at ASC, id ASC",
        );
        foreach ($activeRows as $row) {
            $event = $this->decodeEvent($this->rowToArray($row));
            $eventId = (int) ($event['id'] ?? 0);
            if ($eventId <= 0) {
                continue;
            }
            $this->execPrepared(
                "UPDATE system_events
                 SET status = 'completed',
                     last_activity_at = NOW(),
                     next_run_at = NULL
                 WHERE id = ? AND status = 'active'",
                [$eventId],
            );
            $changedRow = $this->firstPrepared('SELECT ROW_COUNT() AS n');
            $changed = (int) ($changedRow->n ?? 0);
            if ($changed > 0) {
                $completedIds[] = $eventId;
                $result = $this->handleStatusTransition($eventId, 'active', 'completed', false);
                if (!empty($result['generated_event_id'])) {
                    $generatedIds[] = (int) $result['generated_event_id'];
                }
            }
        }

        return [
            'activated_ids' => array_values(array_unique($activatedIds)),
            'completed_ids' => array_values(array_unique($completedIds)),
            'generated_ids' => array_values(array_unique($generatedIds)),
        ];
    }

    public function handleStatusTransition(int $eventId, string $oldStatus, string $newStatus, bool $manual = true): array
    {
        $event = $this->getEvent($eventId);
        $newStatus = strtolower(trim($newStatus));
        $result = [
            'event_id' => $eventId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'manual' => $manual ? 1 : 0,
            'generated_event_id' => 0,
            'reward_logs_count' => 0,
            'notified_count' => 0,
        ];

        if ($newStatus === 'scheduled') {
            $nextRunAt = $this->normalizeDateTime($event['starts_at'] ?? null);
            $this->execPrepared(
                'UPDATE system_events
                 SET next_run_at = ?
                 WHERE id = ?',
                [$nextRunAt, $eventId],
            );
        }

        if (in_array($newStatus, ['active', 'completed'], true)) {
            $this->createNarrativeLifecycleEvent($event, $newStatus);
            $result['notified_count'] = $this->notifyParticipants($event, $newStatus);
        }

        if ($newStatus === 'completed') {
            $result['reward_logs_count'] = $this->applyCompletionEffects($event);
            $generatedId = $this->spawnRecurringOccurrence($event);
            if ($generatedId > 0) {
                $result['generated_event_id'] = $generatedId;
            }
        }

        \Core\Hooks::fire('system_event.status_changed', $eventId, $oldStatus, $newStatus);

        return $result;
    }

    private function getEvent(int $eventId): array
    {
        $row = $this->firstPrepared(
            'SELECT *
             FROM system_events
             WHERE id = ?
             LIMIT 1',
            [(int) $eventId],
        );
        if (empty($row)) {
            return [];
        }
        return $this->decodeEvent($this->rowToArray($row));
    }

    private function normalizeDateTime($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function createNarrativeLifecycleEvent(array $event, string $status): void
    {
        if (empty($event) || empty($event['id'])) {
            return;
        }
        if (!$this->tableExists('narrative_events')) {
            return;
        }

        $eventId = (int) $event['id'];
        $eventType = ($status === 'active') ? 'system_event_activated' : 'system_event_completed';
        $existing = $this->firstPrepared(
            'SELECT id
             FROM narrative_events
             WHERE source_system = ?
               AND source_ref_id = ?
               AND event_type = ?
             LIMIT 1',
            ['system_event', $eventId, $eventType],
        );
        if (!empty($existing)) {
            return;
        }

        $scopeType = strtolower(trim((string) ($event['scope_type'] ?? 'global')));
        $scope = 'global';
        if ($scopeType === 'location') {
            $scope = 'local';
        } elseif (in_array($scopeType, ['map', 'faction', 'character'], true)) {
            $scope = 'regional';
        }

        $entityRefs = [
            ['entity_type' => 'event', 'entity_id' => $eventId, 'role' => 'subject'],
        ];
        $scopeId = (int) ($event['scope_id'] ?? 0);
        if ($scopeId > 0) {
            if ($scopeType === 'location') {
                $entityRefs[] = ['entity_type' => 'location', 'entity_id' => $scopeId, 'role' => 'scope'];
            } elseif ($scopeType === 'faction') {
                $entityRefs[] = ['entity_type' => 'faction', 'entity_id' => $scopeId, 'role' => 'scope'];
            } elseif ($scopeType === 'character') {
                $entityRefs[] = ['entity_type' => 'character', 'entity_id' => $scopeId, 'role' => 'scope'];
            }
        }

        $titlePrefix = ($status === 'active') ? 'Evento di sistema attivato' : 'Evento di sistema completato';
        $title = $titlePrefix . ': ' . (string) ($event['title'] ?? ('#' . $eventId));

        try {
            $this->narrativeEventService->createEvent([
                'title' => $title,
                'event_type' => $eventType,
                'scope' => $scope,
                'description' => (string) ($event['description'] ?? ''),
                'entity_refs' => $entityRefs,
                'location_id' => ($scopeType === 'location' && $scopeId > 0) ? $scopeId : 0,
                'visibility' => 'public',
                'tags' => 'system_event,' . $status,
                'source_system' => 'system_event',
                'source_ref_id' => $eventId,
                'meta_json' => [
                    'event_type' => 'system_event_lifecycle',
                    'status' => $status,
                    'system_event_id' => $eventId,
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId > 0 ? $scopeId : null,
                ],
                'created_by' => (int) ($event['updated_by'] ?? $event['created_by'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            // Non bloccare lifecycle evento se il dominio narrativo non e disponibile.
        }
    }

    private function notifyParticipants(array $event, string $status): int
    {
        if (empty($event) || empty($event['id'])) {
            return 0;
        }
        $autoNotify = $this->getConfig('system_events_auto_notify', '1');
        if (!in_array(strtolower($autoNotify), ['1', 'true', 'yes', 'on'], true)) {
            return 0;
        }

        $eventId = (int) $event['id'];
        $participantMode = strtolower(trim((string) ($event['participant_mode'] ?? 'character')));
        $characterIds = $this->participationService->activeParticipantsForRewards($eventId, $participantMode);
        if (empty($characterIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($characterIds), '?'));
        $rows = $this->fetchPrepared(
            'SELECT id, user_id
             FROM characters
             WHERE id IN (' . $placeholders . ')
               AND user_id IS NOT NULL',
            array_map('intval', $characterIds),
        );
        $count = 0;

        $statusLabelMap = [
            'active' => 'attivato',
            'completed' => 'completato',
            'cancelled' => 'annullato',
            'scheduled' => 'programmato',
            'draft' => 'bozza',
        ];
        $statusLabel = $statusLabelMap[$status] ?? $status;
        $title = 'Evento di sistema ' . $statusLabel . ': ' . (string) ($event['title'] ?? ('#' . $eventId));

        foreach ($rows as $row) {
            $userId = (int) ($row->user_id ?? 0);
            $characterId = (int) ($row->id ?? 0);
            if ($userId <= 0 || $characterId <= 0) {
                continue;
            }

            try {
                $this->notificationService->mergeOrCreateSystemUpdate(
                    $userId,
                    $characterId,
                    'system_event_' . $eventId . '_status_' . $status,
                    $title,
                    [
                        'source_type' => 'system_event',
                        'source_id' => $eventId,
                        'action_url' => '/game',
                    ],
                );
                $count++;
            } catch (\Throwable $e) {
                // Continua notifiche per altri destinatari.
            }
        }

        return $count;
    }

    private function applyCompletionEffects(array $event): int
    {
        if (empty($event) || empty($event['id'])) {
            return 0;
        }
        $eventId = (int) $event['id'];
        $participantMode = strtolower(trim((string) ($event['participant_mode'] ?? 'character')));

        $effects = $this->effectService->listByEvent($eventId);
        if (empty($effects)) {
            return 0;
        }

        $characterIds = $this->participationService->activeParticipantsForRewards($eventId, $participantMode);
        if (empty($characterIds)) {
            return 0;
        }

        $logsCount = 0;
        foreach ($effects as $effect) {
            $effectType = strtolower(trim((string) ($effect['effect_type'] ?? '')));
            $isEnabled = (int) ($effect['is_enabled'] ?? 0) === 1;
            if (!$isEnabled || $effectType !== 'currency_reward') {
                continue;
            }

            $currencyId = (int) ($effect['currency_id'] ?? 0);
            $amount = (int) ($effect['amount'] ?? 0);
            if ($currencyId <= 0 || $amount <= 0) {
                continue;
            }

            foreach ($characterIds as $characterId) {
                $characterId = (int) $characterId;
                if ($characterId <= 0) {
                    continue;
                }
                try {
                    $this->rewardService->assignCurrencyReward(
                        $eventId,
                        $characterId,
                        $currencyId,
                        $amount,
                        0,
                        'auto_effect',
                        [
                            'effect_id' => (int) ($effect['id'] ?? 0),
                            'automatic' => 1,
                        ],
                    );
                    $logsCount++;
                } catch (\Throwable $e) {
                    // Effetto fallito su un destinatario: continua sugli altri.
                }
            }
        }

        return $logsCount;
    }

    private function spawnRecurringOccurrence(array $event): int
    {
        if (empty($event) || empty($event['id'])) {
            return 0;
        }

        $recurrence = strtolower(trim((string) ($event['recurrence'] ?? 'none')));
        if (!in_array($recurrence, ['daily', 'weekly', 'monthly'], true)) {
            return 0;
        }

        $startAt = $this->normalizeDateTime($event['starts_at'] ?? null);
        $endAt = $this->normalizeDateTime($event['ends_at'] ?? null);

        $referenceTs = $startAt !== null ? strtotime($startAt) : time();

        if ($recurrence === 'daily') {
            $nextStartTs = strtotime('+1 day', $referenceTs);
        } elseif ($recurrence === 'weekly') {
            $nextStartTs = strtotime('+1 week', $referenceTs);
        } else {
            $nextStartTs = strtotime('+1 month', $referenceTs);
        }
        $nextStart = date('Y-m-d H:i:s', $nextStartTs);

        $nextEnd = null;
        if ($startAt !== null && $endAt !== null) {
            $duration = strtotime($endAt) - strtotime($startAt);
            if ($duration > 0) {
                $nextEnd = date('Y-m-d H:i:s', $nextStartTs + $duration);
            }
        }

        $meta = $event['meta_json'] ?? [];
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta['generated_from_event_id'] = (int) $event['id'];
        $meta['generated_at'] = date('Y-m-d H:i:s');
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($metaJson)) {
            $metaJson = '{}';
        }

        $this->execPrepared(
            'INSERT INTO system_events
             (title, description, type, status, visibility, scope_type, scope_id, participant_mode, starts_at, ends_at, recurrence, next_run_at, last_activity_at, meta_json, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)',
            [
                (string) ($event['title'] ?? 'Evento ricorrente'),
                ($event['description'] !== null && $event['description'] !== '') ? (string) $event['description'] : null,
                (string) ($event['type'] ?? 'general'),
                'scheduled',
                (string) ($event['visibility'] ?? 'public'),
                (string) ($event['scope_type'] ?? 'global'),
                ((int) ($event['scope_id'] ?? 0) > 0) ? (int) ($event['scope_id'] ?? 0) : null,
                (string) ($event['participant_mode'] ?? 'character'),
                $nextStart,
                $nextEnd,
                $recurrence,
                $nextStart,
                $metaJson,
                ((int) ($event['created_by'] ?? 0) > 0) ? (int) ($event['created_by'] ?? 0) : null,
                ((int) ($event['updated_by'] ?? 0) > 0) ? (int) ($event['updated_by'] ?? 0) : null,
            ],
        );

        $newEventId = (int) $this->db->lastInsertId();
        if ($newEventId <= 0) {
            return 0;
        }

        $this->effectService->copyEffectsToEvent((int) $event['id'], $newEventId, (int) ($event['updated_by'] ?? 0));
        return $newEventId;
    }

    private function tableExists(string $table): bool
    {
        $table = trim($table);
        if ($table === '') {
            return false;
        }
        $row = $this->firstPrepared(
            'SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1',
            [$table],
        );

        return !empty($row);
    }
}
