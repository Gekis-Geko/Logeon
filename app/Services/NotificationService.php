<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class NotificationService
{
    public const KIND_ACTION_REQUIRED = 'action_required';
    public const KIND_DECISION_RESULT = 'decision_result';
    public const KIND_SYSTEM_UPDATE = 'system_update';

    public const ACTION_STATUS_NONE = 'none';
    public const ACTION_STATUS_PENDING = 'pending';
    public const ACTION_STATUS_RESOLVED = 'resolved';

    /** @var DbAdapterInterface */
    private $db;

    /** @var bool|null */
    private $tableExists = null;

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
        }
    }

    private function hasTable(): bool
    {
        if ($this->tableExists !== null) {
            return $this->tableExists;
        }
        $row = $this->firstPrepared(
            'SELECT COUNT(*) AS n
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?',
            ['notifications'],
        );
        $this->tableExists = ((int) ($row->n ?? 0) > 0);
        return $this->tableExists;
    }

    /**
     * Crea una notifica per un utente/personaggio.
     *
     * @param array $options {
     *   message, priority, actor_user_id, actor_character_id,
     *   action_url, source_type, source_id, source_meta_json,
     *   dedup_key, expires_at
     * }
     */
    public function create(
        int $recipientUserId,
        ?int $recipientCharacterId,
        string $kind,
        string $topic,
        string $title,
        array $options = [],
    ): ?int {
        if (!$this->hasTable() || $recipientUserId <= 0) {
            return null;
        }

        $now = date('Y-m-d H:i:s');
        $actionStatus = ($kind === self::KIND_ACTION_REQUIRED)
            ? self::ACTION_STATUS_PENDING
            : self::ACTION_STATUS_NONE;

        $message = isset($options['message']) ? (string) $options['message'] : null;
        $priority = isset($options['priority']) ? (string) $options['priority'] : 'normal';
        $actorUserId = isset($options['actor_user_id']) ? (int) $options['actor_user_id'] : null;
        $actorCharId = isset($options['actor_character_id']) ? (int) $options['actor_character_id'] : null;
        $actionUrl = isset($options['action_url']) ? (string) $options['action_url'] : null;
        $sourceType = isset($options['source_type']) ? (string) $options['source_type'] : null;
        $sourceId = isset($options['source_id']) ? (int) $options['source_id'] : null;
        $sourceMeta = isset($options['source_meta_json']) ? (string) $options['source_meta_json'] : null;
        $dedupKey = isset($options['dedup_key']) ? (string) $options['dedup_key'] : null;
        $expiresAt = isset($options['expires_at']) ? (string) $options['expires_at'] : null;

        $this->execPrepared(
            'INSERT INTO notifications SET
                recipient_user_id      = ?,
                recipient_character_id = ?,
                actor_user_id          = ?,
                actor_character_id     = ?,
                kind                   = ?,
                topic                  = ?,
                priority               = ?,
                title                  = ?,
                message                = ?,
                action_status          = ?,
                action_decision        = NULL,
                action_url             = ?,
                source_type            = ?,
                source_id              = ?,
                source_meta_json       = ?,
                dedup_key              = ?,
                is_read                = 0,
                read_at                = NULL,
                expires_at             = ?,
                date_created           = ?,
                date_updated           = ?',
            [
                $recipientUserId,
                ($recipientCharacterId !== null && $recipientCharacterId > 0) ? $recipientCharacterId : null,
                ($actorUserId !== null && $actorUserId > 0) ? $actorUserId : null,
                ($actorCharId !== null && $actorCharId > 0) ? $actorCharId : null,
                $kind,
                $topic,
                $priority,
                $title,
                $message,
                $actionStatus,
                $actionUrl,
                $sourceType,
                ($sourceId !== null && $sourceId > 0) ? $sourceId : null,
                $sourceMeta,
                $dedupKey,
                $expiresAt,
                $now,
                $now,
            ],
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Merge su dedup_key esistente oppure crea nuova notifica system_update.
     */
    public function mergeOrCreateSystemUpdate(
        int $recipientUserId,
        ?int $recipientCharacterId,
        string $dedupKey,
        string $title,
        array $options = [],
    ): ?int {
        if (!$this->hasTable() || $recipientUserId <= 0) {
            return null;
        }

        $existing = $this->firstPrepared(
            'SELECT id FROM notifications
             WHERE recipient_user_id = ?
               AND dedup_key = ?
             LIMIT 1',
            [$recipientUserId, $dedupKey],
        );

        if (!empty($existing)) {
            $this->execPrepared(
                'UPDATE notifications SET
                    title        = ?,
                    is_read      = 0,
                    read_at      = NULL,
                    date_updated = NOW()
                 WHERE id = ?',
                [$title, (int) $existing->id],
            );
            return (int) $existing->id;
        }

        $options['dedup_key'] = $dedupKey;
        return $this->create(
            $recipientUserId,
            $recipientCharacterId,
            self::KIND_SYSTEM_UPDATE,
            'system_notice',
            $title,
            $options,
        );
    }

    /**
     * Lista notifiche per il destinatario con contatori.
     */
    public function listForRecipient(
        int $recipientUserId,
        ?int $recipientCharacterId,
        array $filters = [],
    ): array {
        if (!$this->hasTable()) {
            return [
                'rows' => [],
                'meta' => ['total' => 0, 'unread_count' => 0, 'pending_count' => 0, 'page' => 1, 'results' => 20],
            ];
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $results = min(50, max(1, (int) ($filters['results'] ?? 20)));
        $offset = ($page - 1) * $results;

        $baseWhereParts = [
            'recipient_user_id = ?',
            '(expires_at IS NULL OR expires_at > NOW())',
        ];
        $baseParams = [$recipientUserId];

        if ($recipientCharacterId && $recipientCharacterId > 0) {
            $baseWhereParts[] = '(recipient_character_id IS NULL OR recipient_character_id = ?)';
            $baseParams[] = $recipientCharacterId;
        }

        $filterWhereParts = $baseWhereParts;
        $filterParams = $baseParams;
        if (!empty($filters['unread_only'])) {
            $filterWhereParts[] = 'is_read = 0';
        }
        if (!empty($filters['kind'])) {
            $filterWhereParts[] = 'kind = ?';
            $filterParams[] = (string) $filters['kind'];
        }
        if (!empty($filters['pending_only'])) {
            $filterWhereParts[] = 'action_status = "pending"';
        }

        $baseWhere = 'WHERE ' . implode(' AND ', $baseWhereParts);
        $filterWhere = 'WHERE ' . implode(' AND ', $filterWhereParts);

        $totalRow = $this->firstPrepared('SELECT COUNT(*) AS n FROM notifications ' . $filterWhere, $filterParams);
        $total = (int) ($totalRow->n ?? 0);

        $unreadRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n FROM notifications ' . $baseWhere . ' AND is_read = 0',
            $baseParams,
        );
        $unread = (int) ($unreadRow->n ?? 0);

        $pendingRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n FROM notifications ' . $baseWhere . ' AND action_status = "pending"',
            $baseParams,
        );
        $pending = (int) ($pendingRow->n ?? 0);

        $rows = $this->fetchPrepared(
            'SELECT * FROM notifications ' . $filterWhere . '
             ORDER BY date_created DESC
             LIMIT ? OFFSET ?',
            array_merge($filterParams, [$results, $offset]),
        );

        return [
            'rows' => $rows ?: [],
            'meta' => [
                'total' => $total,
                'unread_count' => $unread,
                'pending_count' => $pending,
                'page' => $page,
                'results' => $results,
            ],
        ];
    }

    /**
     * Marca una singola notifica come letta.
     */
    public function markRead(int $notificationId, int $recipientUserId): array
    {
        if (!$this->hasTable()) {
            throw AppError::notFound('Notifica non trovata', [], 'notification_not_found');
        }

        $row = $this->firstPrepared(
            'SELECT * FROM notifications WHERE id = ? LIMIT 1',
            [$notificationId],
        );

        if (empty($row)) {
            throw AppError::notFound('Notifica non trovata', [], 'notification_not_found');
        }
        if ((int) $row->recipient_user_id !== $recipientUserId) {
            throw AppError::unauthorized('Operazione non autorizzata', [], 'notification_forbidden');
        }
        if (!(int) $row->is_read) {
            $this->execPrepared(
                'UPDATE notifications SET is_read = 1, read_at = NOW(), date_updated = NOW()
                 WHERE id = ?',
                [$notificationId],
            );
        }

        return [
            'notification_id' => $notificationId,
            'is_read' => 1,
            'read_at' => $row->read_at ?: date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Legge e rimuove una notifica in un'unica azione.
     */
    public function markReadAndDelete(int $notificationId, int $recipientUserId): array
    {
        if (!$this->hasTable()) {
            throw AppError::notFound('Notifica non trovata', [], 'notification_not_found');
        }

        $row = $this->firstPrepared(
            'SELECT * FROM notifications WHERE id = ? LIMIT 1',
            [$notificationId],
        );

        if (empty($row)) {
            throw AppError::notFound('Notifica non trovata', [], 'notification_not_found');
        }
        if ((int) $row->recipient_user_id !== $recipientUserId) {
            throw AppError::unauthorized('Operazione non autorizzata', [], 'notification_forbidden');
        }
        if (
            (string) $row->kind === self::KIND_ACTION_REQUIRED
            && (string) $row->action_status === self::ACTION_STATUS_PENDING
        ) {
            throw AppError::validation('Questa notifica richiede una decisione', [], 'notification_requires_decision');
        }

        $wasUnread = ((int) $row->is_read === 0);

        $this->execPrepared(
            'DELETE FROM notifications
             WHERE id = ?
             LIMIT 1',
            [$notificationId],
        );

        return [
            'notification_id' => $notificationId,
            'deleted' => 1,
            'marked_read' => 1,
            'was_unread' => $wasUnread ? 1 : 0,
        ];
    }

    /**
     * Rimuove una notifica.
     */
    public function delete(int $notificationId, int $recipientUserId): array
    {
        if (!$this->hasTable()) {
            throw AppError::notFound('Notifica non trovata', [], 'notification_not_found');
        }

        $row = $this->firstPrepared(
            'SELECT * FROM notifications WHERE id = ? LIMIT 1',
            [$notificationId],
        );

        if (empty($row)) {
            throw AppError::notFound('Notifica non trovata', [], 'notification_not_found');
        }
        if ((int) $row->recipient_user_id !== $recipientUserId) {
            throw AppError::unauthorized('Operazione non autorizzata', [], 'notification_forbidden');
        }
        if (
            (string) $row->kind === self::KIND_ACTION_REQUIRED
            && (string) $row->action_status === self::ACTION_STATUS_PENDING
        ) {
            throw AppError::validation('Questa notifica richiede una decisione', [], 'notification_requires_decision');
        }

        $wasUnread = ((int) $row->is_read === 0);

        $this->execPrepared(
            'DELETE FROM notifications
             WHERE id = ?
             LIMIT 1',
            [$notificationId],
        );

        return [
            'notification_id' => $notificationId,
            'deleted' => 1,
            'was_unread' => $wasUnread ? 1 : 0,
        ];
    }

    /**
     * Marca tutte le notifiche del destinatario come lette.
     */
    public function markAllRead(int $recipientUserId, array $filters = []): array
    {
        if (!$this->hasTable()) {
            return ['updated' => 0];
        }

        $where = ['recipient_user_id = ?', 'is_read = 0'];
        $params = [$recipientUserId];
        if (!empty($filters['kind'])) {
            $where[] = 'kind = ?';
            $params[] = (string) $filters['kind'];
        }

        $this->execPrepared(
            'UPDATE notifications SET is_read = 1, read_at = NOW(), date_updated = NOW() WHERE ' . implode(' AND ', $where),
            $params,
        );
        $affectedRow = $this->firstPrepared('SELECT ROW_COUNT() AS n');
        $updated = (int) ($affectedRow->n ?? 0);

        return ['updated' => $updated];
    }

    /**
     * Risponde a una notifica action_required e dispatcha all'handler del topic.
     */
    public function respond(
        int $notificationId,
        int $recipientUserId,
        int $recipientCharacterId,
        string $decision,
    ): array {
        if (!$this->hasTable()) {
            throw AppError::notFound('Notifica non trovata', [], 'notification_not_found');
        }

        $decision = strtolower(trim($decision));
        if ($decision !== 'accepted' && $decision !== 'rejected') {
            throw AppError::validation('Decisione non valida', [], 'notification_decision_invalid');
        }

        $this->begin();
        try {
            $row = $this->firstPrepared(
                'SELECT * FROM notifications
                 WHERE id = ?
                 LIMIT 1
                 FOR UPDATE',
                [$notificationId],
            );

            if (empty($row)) {
                throw AppError::notFound('Notifica non trovata', [], 'notification_not_found');
            }
            if ((int) $row->recipient_user_id !== $recipientUserId) {
                throw AppError::unauthorized('Operazione non autorizzata', [], 'notification_forbidden');
            }
            if ((string) $row->kind !== self::KIND_ACTION_REQUIRED) {
                throw AppError::validation('Notifica non azionabile', [], 'notification_not_actionable');
            }
            if ((string) $row->action_status === self::ACTION_STATUS_RESOLVED) {
                throw AppError::validation('Notifica gia risolta', [], 'notification_already_resolved');
            }
            if ((string) $row->action_status !== self::ACTION_STATUS_PENDING) {
                throw AppError::validation('Notifica non azionabile', [], 'notification_not_actionable');
            }

            $sourceResult = $this->dispatchTopicHandler(
                (string) $row->topic,
                $row,
                $decision,
                $recipientCharacterId,
            );

            $this->execPrepared(
                'UPDATE notifications SET
                    action_status   = "resolved",
                    action_decision = ?,
                    is_read         = 1,
                    read_at         = NOW(),
                    date_updated    = NOW()
                 WHERE id = ?',
                [$decision, $notificationId],
            );

            $this->commit();

            return [
                'notification_id' => $notificationId,
                'action_status' => 'resolved',
                'action_decision' => $decision,
                'source_result' => $sourceResult,
            ];
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Segna come resolved qualsiasi notifica pending con source_type/source_id per l'utente.
     * Usato dai path diretti (es. location/invite/respond) per sincronizzare lo stato.
     */
    public function resolveBySource(
        int $recipientUserId,
        string $sourceType,
        int $sourceId,
        string $decision,
    ): void {
        if (!$this->hasTable() || $recipientUserId <= 0 || $sourceId <= 0) {
            return;
        }

        $this->execPrepared(
            'UPDATE notifications SET
                action_status   = "resolved",
                action_decision = ?,
                is_read         = 1,
                read_at         = COALESCE(read_at, NOW()),
                date_updated    = NOW()
             WHERE recipient_user_id = ?
               AND source_type       = ?
               AND source_id         = ?
               AND action_status     = "pending"',
            [$decision, $recipientUserId, $sourceType, $sourceId],
        );
    }

    /**
     * Conta le notifiche non lette per un utente.
     */
    public function getUnreadCount(int $recipientUserId): int
    {
        if (!$this->hasTable() || $recipientUserId <= 0) {
            return 0;
        }

        $row = $this->firstPrepared(
            'SELECT COUNT(*) AS n FROM notifications
             WHERE recipient_user_id = ?
               AND is_read = 0
               AND (expires_at IS NULL OR expires_at > NOW())',
            [$recipientUserId],
        );

        return (int) ($row->n ?? 0);
    }

    // -------------------------------------------------------------------------
    // Topic handlers
    // -------------------------------------------------------------------------

    private function dispatchTopicHandler(
        string $topic,
        $notification,
        string $decision,
        int $responderCharacterId,
    ): array {
        if ($topic === 'bond_request') {
            return $this->handleBondRequest($notification, $decision, $responderCharacterId);
        }
        if ($topic === 'location_invite') {
            return $this->handleLocationInvite($notification, $decision, $responderCharacterId);
        }
        throw AppError::validation('Topic non supportato: ' . $topic, [], 'notification_topic_unsupported');
    }

    private function handleBondRequest($notification, string $decision, int $responderCharacterId): array
    {
        $requestId = (int) ($notification->source_id ?? 0);
        if ($requestId <= 0) {
            throw AppError::validation('Riferimento richiesta legame non valido', [], 'notification_invalid');
        }

        $bondService = new CharacterBondService($this->db);
        $result = $bondService->respondBondRequest($responderCharacterId, $requestId, $decision);

        // Notifica decision_result all'actor (il richiedente)
        $actorCharId = (int) ($notification->actor_character_id ?? 0);
        if ($actorCharId > 0) {
            $requester = $this->firstPrepared(
                'SELECT user_id FROM characters WHERE id = ? LIMIT 1',
                [$actorCharId],
            );
            if (!empty($requester)) {
                $label = ($decision === 'accepted') ? 'accettata' : 'rifiutata';
                $this->create(
                    (int) $requester->user_id,
                    $actorCharId,
                    self::KIND_DECISION_RESULT,
                    'bond_request_result',
                    'Richiesta legame ' . $label,
                    [
                        'actor_character_id' => (int) ($notification->recipient_character_id ?? 0),
                        'source_type' => 'bond_request',
                        'source_id' => $requestId,
                        'priority' => 'normal',
                    ],
                );
            }
        }

        return $result;
    }

    private function handleLocationInvite($notification, string $decision, int $responderCharacterId): array
    {
        $inviteId = (int) ($notification->source_id ?? 0);
        if ($inviteId <= 0) {
            throw AppError::validation('Riferimento invito non valido', [], 'notification_invalid');
        }

        $locationService = new LocationService($this->db);
        $newStatus = ($decision === 'accepted') ? 'accepted' : 'declined';
        $locationService->respondInvite($inviteId, $responderCharacterId, $newStatus);

        // Notifica decision_result all'actor (il proprietario della location)
        $actorCharId = (int) ($notification->actor_character_id ?? 0);
        if ($actorCharId > 0) {
            $owner = $this->firstPrepared(
                'SELECT user_id FROM characters WHERE id = ? LIMIT 1',
                [$actorCharId],
            );
            if (!empty($owner)) {
                $label = ($decision === 'accepted') ? 'accettato' : 'rifiutato';
                $this->create(
                    (int) $owner->user_id,
                    $actorCharId,
                    self::KIND_DECISION_RESULT,
                    'location_invite_result',
                    'Invito location ' . $label,
                    [
                        'actor_character_id' => (int) ($notification->recipient_character_id ?? 0),
                        'source_type' => 'location_invite',
                        'source_id' => $inviteId,
                        'priority' => 'normal',
                    ],
                );
            }
        }

        return ['invite_id' => $inviteId, 'decision' => $decision];
    }
}
