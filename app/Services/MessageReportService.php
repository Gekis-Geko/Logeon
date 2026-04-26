<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class MessageReportService
{
    public const REASON_CODES = [
        'offensive_language',
        'harassment',
        'spam_flood',
        'inappropriate_content',
        'rule_violation',
        'other',
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_ARCHIVED = 'archived';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';

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

    private function rowToArray($row): array
    {
        if (is_object($row)) {
            return (array) $row;
        }
        return is_array($row) ? $row : [];
    }

    // ── Creazione report ──────────────────────────────────────────────────

    public function createReport(
        int $reporterUserId,
        int $reporterCharacterId,
        int $messageId,
        string $reasonCode,
        string $reasonText,
    ): array {
        // Valida reason_code
        if (!in_array($reasonCode, self::REASON_CODES, true)) {
            throw AppError::validation('Motivo non valido', [], 'message_report_invalid_reason');
        }

        // reason_text obbligatorio per "other"
        $reasonText = trim($reasonText);
        if ($reasonCode === 'other' && $reasonText === '') {
            throw AppError::validation('Descrizione obbligatoria per il motivo selezionato', [], 'message_report_reason_text_required');
        }

        // Recupera il messaggio
        $msg = $this->firstPrepared(
            'SELECT id, character_id, location_id, body, date_created, type
             FROM locations_messages
             WHERE id = ?
             LIMIT 1',
            [$messageId],
        );

        if (empty($msg)) {
            throw AppError::notFound('Messaggio non trovato', [], 'message_not_found');
        }
        $msg = $this->rowToArray($msg);

        // Non si può segnalare se stessi
        if ((int) ($msg['character_id'] ?? 0) === $reporterCharacterId) {
            throw AppError::validation('Non puoi segnalare un tuo messaggio', [], 'message_report_forbidden');
        }

        // Deduplica: stesso reporter + stesso messaggio + report ancora aperto
        $dup = $this->firstPrepared(
            'SELECT id FROM message_reports
             WHERE reporter_user_id = ?
               AND reported_message_id = ?
               AND status NOT IN (?, ?, ?)
             LIMIT 1',
            [$reporterUserId, $messageId, self::STATUS_RESOLVED, self::STATUS_DISMISSED, self::STATUS_ARCHIVED],
        );

        if (!empty($dup)) {
            throw AppError::validation('Hai gia segnalato questo messaggio', [], 'message_report_duplicate');
        }

        // Rate limit: max 5 segnalazioni per utente nell'ultima ora
        $recentCount = $this->firstPrepared(
            'SELECT COUNT(*) AS n FROM message_reports
             WHERE reporter_user_id = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            [$reporterUserId],
        );
        if ((int) ($recentCount->n ?? 0) >= 5) {
            throw AppError::validation('Troppe segnalazioni recenti, riprova tra poco', [], 'message_report_rate_limited');
        }

        // Recupera autore messaggio
        $authorCharId = (int) ($msg['character_id'] ?? 0);
        $authorUserId = 0;
        if ($authorCharId > 0) {
            $authorRow = $this->firstPrepared(
                'SELECT user_id FROM characters WHERE id = ? LIMIT 1',
                [$authorCharId],
            );
            $authorUserId = (int) ($authorRow->user_id ?? 0);
        }

        // Determina priorità: medium se autore ha già segnalazioni recenti aperte
        $priority = self::PRIORITY_LOW;
        if ($authorUserId > 0) {
            $existingReports = $this->firstPrepared(
                'SELECT COUNT(*) AS n FROM message_reports
                 WHERE reported_message_author_user_id = ?
                   AND status IN (?, ?)
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
                [$authorUserId, self::STATUS_OPEN, self::STATUS_IN_REVIEW],
            );
            if ((int) ($existingReports->n ?? 0) >= 2) {
                $priority = self::PRIORITY_MEDIUM;
            }
        }

        // Snapshot
        $snapshotMeta = json_encode([
            'message_id' => $messageId,
            'character_id' => $authorCharId,
            'location_id' => (int) ($msg['location_id'] ?? 0),
            'type' => (int) ($msg['type'] ?? 0),
            'date_created' => (string) ($msg['date_created'] ?? ''),
        ]);

        $locationId = (int) ($msg['location_id'] ?? 0);

        $this->execPrepared(
            'INSERT INTO message_reports
             (reported_message_id, reported_message_author_character_id, reported_message_author_user_id,
              reporter_character_id, reporter_user_id, location_id,
              reason_code, reason_text, status, priority,
              message_snapshot_text, message_snapshot_meta_json,
              created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $messageId,
                $authorCharId > 0 ? $authorCharId : null,
                $authorUserId > 0 ? $authorUserId : null,
                $reporterCharacterId,
                $reporterUserId,
                $locationId > 0 ? $locationId : null,
                $reasonCode,
                $reasonText !== '' ? mb_substr($reasonText, 0, 1000) : null,
                self::STATUS_OPEN,
                $priority,
                mb_substr((string) ($msg['body'] ?? ''), 0, 2000),
                $snapshotMeta,
            ],
        );

        $reportId = (int) $this->db->lastInsertId();

        // Notifica staff
        $this->notifyStaff($reportId, $reasonCode, $locationId, $authorCharId);

        return [
            'report_id' => $reportId,
            'status' => self::STATUS_OPEN,
            'message_id' => $messageId,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    // ── Notifica staff ────────────────────────────────────────────────────

    private function notifyStaff(int $reportId, string $reasonCode, int $locationId, int $authorCharId): void
    {
        try {
            $staffRows = $this->fetchPrepared(
                'SELECT id FROM users
                 WHERE is_active = 1
                   AND (is_administrator = 1 OR is_moderator = 1)
                 LIMIT 50',
            );

            if (empty($staffRows)) {
                return;
            }

            $notifService = new \App\Services\NotificationService();
            $reasonLabels = [
                'offensive_language' => 'Linguaggio offensivo',
                'harassment' => 'Molestia',
                'spam_flood' => 'Spam / flood',
                'inappropriate_content' => 'Contenuto inappropriato',
                'rule_violation' => 'Violazione del regolamento',
                'other' => 'Altro',
            ];
            $reasonLabel = $reasonLabels[$reasonCode] ?? $reasonCode;

            $locationName = '';
            if ($locationId > 0) {
                $loc = $this->firstPrepared('SELECT name FROM locations WHERE id = ? LIMIT 1', [$locationId]);
                $locationName = (string) ($loc->name ?? '');
            }

            $title = 'Nuova segnalazione messaggio';
            $message = 'Motivo: ' . $reasonLabel;
            if ($locationName !== '') {
                $message .= ' — Luogo: ' . $locationName;
            }
            $actionUrl = '/admin/message-reports';

            foreach ($staffRows as $staffRow) {
                $staffUserId = (int) ($staffRow->id ?? 0);
                if ($staffUserId <= 0) {
                    continue;
                }
                $notifService->create(
                    $staffUserId,
                    null,
                    \App\Services\NotificationService::KIND_ACTION_REQUIRED,
                    'message_report',
                    $title,
                    [
                        'message' => $message,
                        'priority' => 'normal',
                        'source_type' => 'message_report',
                        'source_id' => $reportId,
                        'action_url' => $actionUrl,
                    ],
                );
            }
        } catch (\Throwable $e) {
            // La notifica non deve bloccare la segnalazione
        }
    }

    // ── Admin: lista ──────────────────────────────────────────────────────

    public function adminList(array $filters = [], int $limit = 25, int $page = 1, string $sort = 'created_at|DESC'): array
    {
        $limit = max(1, min(100, (int) $limit));
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $limit;

        $where = ['1=1'];
        $params = [];

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'mr.status = ?';
            $params[] = $status;
        }

        $priority = trim((string) ($filters['priority'] ?? ''));
        if ($priority !== '') {
            $where[] = 'mr.priority = ?';
            $params[] = $priority;
        }

        $locationId = (int) ($filters['location_id'] ?? 0);
        if ($locationId > 0) {
            $where[] = 'mr.location_id = ?';
            $params[] = $locationId;
        }

        $reportedCharId = (int) ($filters['reported_character_id'] ?? 0);
        if ($reportedCharId > 0) {
            $where[] = 'mr.reported_message_author_character_id = ?';
            $params[] = $reportedCharId;
        }

        $reporterCharId = (int) ($filters['reporter_character_id'] ?? 0);
        if ($reporterCharId > 0) {
            $where[] = 'mr.reporter_character_id = ?';
            $params[] = $reporterCharId;
        }

        $chunks = explode('|', $sort);
        $sortField = in_array($chunks[0], ['id', 'created_at', 'status', 'priority', 'updated_at'], true)
            ? 'mr.' . $chunks[0]
            : 'mr.created_at';
        $sortDir = strtoupper((string) ($chunks[1] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $totalRow = $this->firstPrepared('SELECT COUNT(*) AS n FROM message_reports mr ' . $whereSql, $params);
        $total = (int) ($totalRow->n ?? 0);

        $rows = $this->fetchPrepared(
            'SELECT mr.*,
                    rc.name AS reporter_name, rc.surname AS reporter_surname,
                    ac.name AS reported_name, ac.surname AS reported_surname,
                    l.name  AS location_name
             FROM message_reports mr
             LEFT JOIN characters rc ON rc.id = mr.reporter_character_id
             LEFT JOIN characters ac ON ac.id = mr.reported_message_author_character_id
             LEFT JOIN locations  l  ON l.id  = mr.location_id
             ' . $whereSql . '
             ORDER BY ' . $sortField . ' ' . $sortDir . '
             LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset]),
        );

        $dataset = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $item['id'] = (int) ($item['id'] ?? 0);
            $dataset[] = $item;
        }

        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'rows' => $dataset,
        ];
    }

    // ── Admin: dettaglio ──────────────────────────────────────────────────

    public function adminGet(int $reportId): array
    {
        $row = $this->firstPrepared(
            'SELECT mr.*,
                    rc.name AS reporter_name, rc.surname AS reporter_surname,
                    ac.name AS reported_name, ac.surname AS reported_surname,
                    l.name  AS location_name,
                    (SELECT CONCAT(c.name, IF(c.surname IS NOT NULL AND c.surname != \'\', CONCAT(\' \', c.surname), \'\'))
                     FROM characters c WHERE c.user_id = mr.assigned_to_user_id LIMIT 1) AS assigned_username,
                    (SELECT CONCAT(c.name, IF(c.surname IS NOT NULL AND c.surname != \'\', CONCAT(\' \', c.surname), \'\'))
                     FROM characters c WHERE c.user_id = mr.reviewed_by_user_id LIMIT 1) AS reviewed_username
             FROM message_reports mr
             LEFT JOIN characters rc ON rc.id = mr.reporter_character_id
             LEFT JOIN characters ac ON ac.id = mr.reported_message_author_character_id
             LEFT JOIN locations  l  ON l.id  = mr.location_id
             WHERE mr.id = ?
             LIMIT 1',
            [$reportId],
        );

        if (empty($row)) {
            throw AppError::notFound('Segnalazione non trovata', [], 'message_report_not_found');
        }

        return $this->rowToArray($row);
    }

    // ── Admin: aggiorna stato ─────────────────────────────────────────────

    public function adminUpdateStatus(int $reportId, string $status, int $reviewerUserId, string $reviewNote = '', string $resolutionCode = ''): array
    {
        $validStatuses = [self::STATUS_IN_REVIEW, self::STATUS_RESOLVED, self::STATUS_DISMISSED, self::STATUS_ARCHIVED];
        if (!in_array($status, $validStatuses, true)) {
            throw AppError::validation('Stato non valido', [], 'message_report_invalid_status');
        }

        $report = $this->adminGet($reportId);

        $isClosed = in_array($status, [self::STATUS_RESOLVED, self::STATUS_DISMISSED, self::STATUS_ARCHIVED], true);
        $closedAt = $isClosed ? date('Y-m-d H:i:s') : null;

        $reviewNote = trim($reviewNote);
        $resolutionCode = trim($resolutionCode);

        $this->execPrepared(
            'UPDATE message_reports SET
                status = ?,
                reviewed_by_user_id = ?,
                review_note = ?,
                resolution_code = ?,
                reviewed_at = NOW(),
                closed_at = ?,
                updated_at = NOW()
             WHERE id = ?
             LIMIT 1',
            [
                $status,
                $reviewerUserId,
                $reviewNote !== '' ? mb_substr($reviewNote, 0, 2000) : null,
                $resolutionCode !== '' ? $resolutionCode : null,
                $closedAt,
                $reportId,
            ],
        );

        return $this->adminGet($reportId);
    }

    // ── Admin: assegna ────────────────────────────────────────────────────

    public function adminAssign(int $reportId, int $assignedToUserId): array
    {
        $this->adminGet($reportId); // verifica esiste

        $this->execPrepared(
            'UPDATE message_reports SET
                assigned_to_user_id = ?,
                status = CASE WHEN status = ? THEN ? ELSE status END,
                updated_at = NOW()
             WHERE id = ?
             LIMIT 1',
            [
                $assignedToUserId > 0 ? $assignedToUserId : null,
                self::STATUS_OPEN,
                self::STATUS_IN_REVIEW,
                $reportId,
            ],
        );

        return $this->adminGet($reportId);
    }
}
