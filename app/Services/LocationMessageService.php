<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class LocationMessageService
{
    /** @var DbAdapterInterface */
    private $db;

    /** @var bool|null */
    private $whisperReadStateSupported = null;
    /** @var bool|null */
    private $whisperPolicySupported = null;
    /** @var array */
    private $settingsCache = [];

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

    private function failValidation($message, string $errorCode = 'validation_error'): void
    {
        throw AppError::validation((string) $message, [], $errorCode);
    }

    private function getSettingInt(string $key, int $fallback): int
    {
        if (isset($this->settingsCache[$key])) {
            return $this->settingsCache[$key];
        }

        $value = $fallback;
        try {
            $row = $this->firstPrepared(
                'SELECT value FROM sys_settings WHERE `key` = ? LIMIT 1',
                [$key],
            );
            if (!empty($row) && isset($row->value) && is_numeric((string) $row->value)) {
                $value = (int) $row->value;
            }
        } catch (\Throwable $e) {
            $value = $fallback;
        }

        $this->settingsCache[$key] = $value;
        return $value;
    }

    public function locationChatHistoryHours(): int
    {
        $configFallback = 3;
        if (defined('CONFIG') && isset(CONFIG['location_chat_history_hours'])) {
            $configFallback = (int) CONFIG['location_chat_history_hours'];
        }

        $hours = $this->getSettingInt('location_chat_history_hours', $configFallback);
        if ($hours < 1) {
            $hours = 1;
        }
        if ($hours > 24) {
            $hours = 24;
        }
        return $hours;
    }

    public function locationWhisperRetentionHours(): int
    {
        $configFallback = 24;
        if (defined('CONFIG') && isset(CONFIG['location_whisper_retention_hours'])) {
            $configFallback = (int) CONFIG['location_whisper_retention_hours'];
        }

        $hours = $this->getSettingInt('location_whisper_retention_hours', $configFallback);
        if ($hours < 1) {
            $hours = 1;
        }
        if ($hours > 168) {
            $hours = 168;
        }
        return $hours;
    }

    private function textLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value, 'UTF-8');
        }

        return (int) strlen($value);
    }

    public function normalizeMessageText(
        $value,
        int $maxLength,
        string $emptyMessage,
        string $tooLongMessage,
        string $emptyCode = 'validation_error',
        string $tooLongCode = 'validation_error',
    ): string {
        $text = trim((string) $value);
        if ($text === '') {
            $this->failValidation($emptyMessage, $emptyCode);
        }

        if ($this->textLength($text) > $maxLength) {
            $this->failValidation($tooLongMessage, $tooLongCode);
        }

        return $text;
    }

    public function normalizeTag($tag)
    {
        $tag = trim((string) $tag);
        if ($tag === '') {
            return null;
        }

        if (strlen($tag) > 80) {
            $tag = substr($tag, 0, 80);
        }

        return $tag;
    }

    private function parseSegments(string $raw): array
    {
        $segments = [];
        $pattern = "/\"([^\"]+)\"|\x{00AB}([^\x{00BB}]+)\x{00BB}|\x{201C}([^\x{201D}]+)\x{201D}|<([^>]+)>|(?<![\p{L}\p{N}_])'([^']+)'(?![\p{L}\p{N}_])|\x{2018}([^\x{2019}]+)\x{2019}/u";
        $offset = 0;
        $matches = [];
        if (preg_match_all($pattern, $raw, $matches, PREG_OFFSET_CAPTURE)) {
            for ($i = 0; $i < count($matches[0]); $i++) {
                $full = $matches[0][$i][0];
                $pos = $matches[0][$i][1];
                if ($pos > $offset) {
                    $actionText = substr($raw, $offset, $pos - $offset);
                    if ($actionText !== '') {
                        $segments[] = [
                            'type' => 'action',
                            'text' => $actionText,
                        ];
                    }
                }
                if ($full !== '') {
                    $segments[] = [
                        'type' => 'speech',
                        'text' => $full,
                    ];
                }
                $offset = $pos + strlen($full);
            }
        }

        if ($offset < strlen($raw)) {
            $tail = substr($raw, $offset);
            if ($tail !== '') {
                $segments[] = [
                    'type' => 'action',
                    'text' => $tail,
                ];
            }
        }

        if (empty($segments)) {
            $segments[] = [
                'type' => 'action',
                'text' => $raw,
            ];
        }

        return $segments;
    }

    private function normalizeSpeechSegmentText(string $text): string
    {
        $value = trim($text);
        if ($value === '') {
            return $value;
        }

        $wrappers = [
            '/^"(.+)"$/us',
            '/^\x{00AB}(.+)\x{00BB}$/us',
            '/^\x{201C}(.+)\x{201D}$/us',
            '/^<(.+)>$/us',
            "/^'(.+)'$/us",
            '/^\x{2018}(.+)\x{2019}$/us',
        ];

        foreach ($wrappers as $wrapperPattern) {
            $match = [];
            if (preg_match($wrapperPattern, $value, $match)) {
                return trim((string) ($match[1] ?? ''));
            }
        }

        return $value;
    }

    private function escapeHtmlPreserveSpacing(string $value): string
    {
        return htmlentities($value, ENT_QUOTES, 'utf-8');
    }

    public function renderBody($raw): string
    {
        $segments = $this->parseSegments((string) $raw);
        $html = '';
        foreach ($segments as $segment) {
            if ($segment['type'] === 'speech') {
                $spoken = $this->normalizeSpeechSegmentText((string) $segment['text']);
                $text = $this->escapeHtmlPreserveSpacing($spoken);
                $html .= '<span class="chat-speech"><b>«' . $text . '»</b></span>';
            } else {
                $text = $this->escapeHtmlPreserveSpacing((string) $segment['text']);
                $html .= '<span class="chat-action">' . $text . '</span>';
            }
        }

        return nl2br($html);
    }

    public function buildMessageResponse($row)
    {
        if (empty($row)) {
            return $row;
        }

        $rawText = null;
        if (!empty($row->meta_json)) {
            $meta = json_decode($row->meta_json);
            if (!empty($meta) && isset($meta->raw) && is_string($meta->raw)) {
                $rawText = $meta->raw;
            }
        }

        if ($rawText === null && isset($row->type) && (int) $row->type !== 3 && isset($row->body) && is_string($row->body)) {
            // Legacy fallback: non-system rows may not have meta_json.raw, so we parse body directly.
            $rawText = $row->body;
        }

        if ($rawText !== null) {
            $isWhisper = isset($row->type) && (int) $row->type === 4;
            $row->body_rendered = $isWhisper
                ? $this->escapeHtmlPreserveSpacing($rawText)
                : $this->renderBody($rawText);
        }

        if (empty($row->body_rendered)) {
            $row->body_rendered = $row->body;
        }

        return $row;
    }

    public function fetchMessageById($id)
    {
        return $this->firstPrepared(
            'SELECT lm.*, c.name AS character_name, c.surname AS character_surname, c.avatar AS character_avatar, c.gender AS character_gender
             FROM locations_messages lm
             JOIN characters c ON c.id = lm.character_id
             WHERE lm.id = ?
             LIMIT 1',
            [(int) $id],
        );
    }

    public function listLocationMessages($locationId, $sinceId, $limit, $historyHours, $whisperType): array
    {
        $locationId = (int) $locationId;
        $sinceId = (int) $sinceId;
        $limit = (int) $limit;
        $historyHours = (int) $historyHours;
        $whisperType = (int) $whisperType;

        if ($limit < 1 || $limit > 200) {
            $limit = 50;
        }
        $cutoffAt = date('Y-m-d H:i:s', strtotime('-' . $historyHours . ' hours'));

        $rows = [];
        if ($sinceId > 0) {
            $rows = $this->fetchPrepared(
                'SELECT lm.*, c.name AS character_name, c.surname AS character_surname, c.avatar AS character_avatar, c.gender AS character_gender
                 FROM locations_messages lm
                 JOIN characters c ON c.id = lm.character_id
                 WHERE lm.location_id = ?
                   AND lm.type <> ?
                   AND lm.date_created >= ?
                   AND lm.id > ?
                 ORDER BY lm.id ASC
                 LIMIT ?',
                [$locationId, $whisperType, $cutoffAt, $sinceId, $limit],
            );

            return $rows ?: [];
        }

        $rows = $this->fetchPrepared(
            'SELECT lm.*, c.name AS character_name, c.surname AS character_surname, c.avatar AS character_avatar, c.gender AS character_gender
             FROM locations_messages lm
             JOIN characters c ON c.id = lm.character_id
             WHERE lm.location_id = ?
               AND lm.type <> ?
               AND lm.date_created >= ?
             ORDER BY lm.id DESC
             LIMIT ?',
            [$locationId, $whisperType, $cutoffAt, $limit],
        );

        return array_reverse($rows ?: []);
    }

    public function listWhisperThread($locationId, $characterId, $recipientId, $whisperType, $limit = 100): array
    {
        $locationId = (int) $locationId;
        $characterId = (int) $characterId;
        $recipientId = (int) $recipientId;
        $whisperType = (int) $whisperType;
        $whisperRetentionHours = $this->locationWhisperRetentionHours();
        $limit = (int) $limit;
        if ($limit < 1 || $limit > 200) {
            $limit = 100;
        }
        $cutoffAt = date('Y-m-d H:i:s', strtotime('-' . $whisperRetentionHours . ' hours'));

        $rows = $this->fetchPrepared(
            'SELECT lm.*, c.name AS character_name, c.surname AS character_surname, c.avatar AS character_avatar, c.gender AS character_gender
             FROM locations_messages lm
             JOIN characters c ON c.id = lm.character_id
             WHERE lm.location_id = ?
               AND lm.type = ?
               AND lm.date_created >= ?
               AND (
                 (lm.character_id = ? AND lm.recipient_id = ?)
                 OR
                 (lm.character_id = ? AND lm.recipient_id = ?)
               )
             ORDER BY lm.id DESC
             LIMIT ?',
            [$locationId, $whisperType, $cutoffAt, $characterId, $recipientId, $recipientId, $characterId, $limit],
        );

        return array_reverse($rows ?: []);
    }

    public function hasWhisperReadStateTable(): bool
    {
        if ($this->whisperReadStateSupported !== null) {
            return $this->whisperReadStateSupported;
        }

        try {
            $row = $this->firstPrepared(
                'SELECT 1
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                 LIMIT 1',
                ['location_whisper_reads'],
            );
            $this->whisperReadStateSupported = !empty($row);
        } catch (\Throwable $error) {
            $this->whisperReadStateSupported = false;
        }

        return $this->whisperReadStateSupported;
    }

    public function hasWhisperPolicyTable(): bool
    {
        if ($this->whisperPolicySupported !== null) {
            return $this->whisperPolicySupported;
        }

        try {
            $row = $this->firstPrepared(
                'SELECT 1
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                 LIMIT 1',
                ['character_whisper_policies'],
            );
            $this->whisperPolicySupported = !empty($row);
        } catch (\Throwable $error) {
            $this->whisperPolicySupported = false;
        }

        return $this->whisperPolicySupported;
    }

    public function markWhisperThreadRead($locationId, $readerId, $otherCharacterId, $whisperType): int
    {
        if (!$this->hasWhisperReadStateTable()) {
            return 0;
        }

        $locationId = (int) $locationId;
        $readerId = (int) $readerId;
        $otherCharacterId = (int) $otherCharacterId;
        $whisperType = (int) $whisperType;
        $whisperRetentionHours = $this->locationWhisperRetentionHours();
        $cutoffAt = date('Y-m-d H:i:s', strtotime('-' . $whisperRetentionHours . ' hours'));

        if ($locationId <= 0 || $readerId <= 0 || $otherCharacterId <= 0) {
            return 0;
        }

        $row = $this->firstPrepared(
            'SELECT MAX(id) AS max_id
             FROM locations_messages
             WHERE location_id = ?
               AND type = ?
               AND date_created >= ?
               AND character_id = ?
               AND recipient_id = ?',
            [$locationId, $whisperType, $cutoffAt, $otherCharacterId, $readerId],
        );

        $lastReadMessageId = (int) ($row->max_id ?? 0);
        if ($lastReadMessageId <= 0) {
            return 0;
        }

        $this->execPrepared(
            'INSERT INTO location_whisper_reads SET
                location_id = ?,
                reader_id = ?,
                other_character_id = ?,
                last_read_message_id = ?,
                date_updated = NOW()
             ON DUPLICATE KEY UPDATE
                last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)),
                date_updated = NOW()',
            [$locationId, $readerId, $otherCharacterId, $lastReadMessageId],
        );

        return $lastReadMessageId;
    }

    public function countWhisperUnread($locationId, $readerId, $whisperType, $otherCharacterId = null): int
    {
        if (!$this->hasWhisperReadStateTable()) {
            return 0;
        }

        $locationId = (int) $locationId;
        $readerId = (int) $readerId;
        $whisperType = (int) $whisperType;
        $otherCharacterId = ($otherCharacterId !== null) ? (int) $otherCharacterId : 0;
        $whisperRetentionHours = $this->locationWhisperRetentionHours();
        $cutoffAt = date('Y-m-d H:i:s', strtotime('-' . $whisperRetentionHours . ' hours'));

        if ($locationId <= 0 || $readerId <= 0) {
            return 0;
        }

        $sql = 'SELECT COUNT(*) AS count
             FROM locations_messages lm
             LEFT JOIN location_whisper_reads wr
               ON wr.location_id = lm.location_id
              AND wr.reader_id = ?
              AND wr.other_character_id = lm.character_id
             WHERE lm.location_id = ?
               AND lm.type = ?
               AND lm.recipient_id = ?
               AND lm.date_created >= ?
               AND lm.id > COALESCE(wr.last_read_message_id, 0)';
        $params = [$readerId, $locationId, $whisperType, $readerId, $cutoffAt];

        if ($otherCharacterId > 0) {
            $sql .= ' AND lm.character_id = ?';
            $params[] = $otherCharacterId;
        }

        if ($this->hasWhisperPolicyTable()) {
            $sql .= '
               AND NOT EXISTS (
                    SELECT 1
                    FROM character_whisper_policies cp
                    WHERE cp.character_id = ?
                      AND cp.target_character_id = lm.character_id
                      AND cp.policy IN (\'mute\', \'block\')
               )';
            $params[] = $readerId;
        }

        $row = $this->firstPrepared($sql, $params);

        return (int) ($row->count ?? 0);
    }

    public function listWhisperThreads($locationId, $readerId, $whisperType, $limit = 200): array
    {
        $locationId = (int) $locationId;
        $readerId = (int) $readerId;
        $whisperType = (int) $whisperType;
        $whisperRetentionHours = $this->locationWhisperRetentionHours();
        $limit = (int) $limit;

        if ($locationId <= 0 || $readerId <= 0) {
            return [];
        }
        if ($limit < 1 || $limit > 300) {
            $limit = 200;
        }
        $cutoffAt = date('Y-m-d H:i:s', strtotime('-' . $whisperRetentionHours . ' hours'));

        $threadRows = $this->fetchPrepared(
            'SELECT
                CASE WHEN character_id = ? THEN recipient_id ELSE character_id END AS other_character_id,
                MAX(id) AS last_message_id
             FROM locations_messages
             WHERE location_id = ?
               AND type = ?
               AND date_created >= ?
               AND (character_id = ? OR recipient_id = ?)
             GROUP BY CASE WHEN character_id = ? THEN recipient_id ELSE character_id END
             ORDER BY last_message_id DESC
             LIMIT ?',
            [$readerId, $locationId, $whisperType, $cutoffAt, $readerId, $readerId, $readerId, $limit],
        );
        if (empty($threadRows)) {
            return [];
        }

        $otherIds = [];
        $messageIds = [];
        foreach ($threadRows as $threadRow) {
            $otherId = (int) ($threadRow->other_character_id ?? 0);
            $messageId = (int) ($threadRow->last_message_id ?? 0);
            if ($otherId > 0 && $messageId > 0) {
                $otherIds[] = $otherId;
                $messageIds[] = $messageId;
            }
        }
        $otherIds = array_values(array_unique($otherIds));
        $messageIds = array_values(array_unique($messageIds));
        if (empty($otherIds) || empty($messageIds)) {
            return [];
        }

        $msgPlaceholders = implode(',', array_fill(0, count($messageIds), '?'));
        $messageRows = $this->fetchPrepared(
            'SELECT id AS last_message_id,
                    character_id AS last_character_id,
                    recipient_id AS last_recipient_id,
                    body AS last_message_body,
                    meta_json AS last_meta_json,
                    date_created AS last_message_date
             FROM locations_messages
             WHERE id IN (' . $msgPlaceholders . ')',
            $messageIds,
        );
        $messageById = [];
        foreach ($messageRows as $messageRow) {
            $messageById[(int) ($messageRow->last_message_id ?? 0)] = $messageRow;
        }

        $charPlaceholders = implode(',', array_fill(0, count($otherIds), '?'));
        $characterRows = $this->fetchPrepared(
            'SELECT id AS recipient_id,
                    name AS character_name,
                    surname AS character_surname,
                    avatar AS character_avatar,
                    gender AS character_gender
             FROM characters
             WHERE id IN (' . $charPlaceholders . ')',
            $otherIds,
        );
        $characterById = [];
        foreach ($characterRows as $characterRow) {
            $characterById[(int) ($characterRow->recipient_id ?? 0)] = $characterRow;
        }

        $policyById = [];
        if ($this->hasWhisperPolicyTable()) {
            $policyRows = $this->fetchPrepared(
                'SELECT target_character_id, policy
                 FROM character_whisper_policies
                 WHERE character_id = ?
                   AND target_character_id IN (' . $charPlaceholders . ')',
                array_merge([$readerId], $otherIds),
            );
            foreach ($policyRows as $policyRow) {
                $policyById[(int) ($policyRow->target_character_id ?? 0)] = (string) ($policyRow->policy ?? 'allow');
            }
        }

        $unreadById = [];
        if ($this->hasWhisperReadStateTable()) {
            $unreadSql = 'SELECT lm.character_id AS other_character_id, COUNT(*) AS unread_count
                 FROM locations_messages lm
                 LEFT JOIN location_whisper_reads wr
                   ON wr.location_id = lm.location_id
                  AND wr.reader_id = ?
                  AND wr.other_character_id = lm.character_id
                 WHERE lm.location_id = ?
                   AND lm.type = ?
                   AND lm.recipient_id = ?
                   AND lm.date_created >= ?
                   AND lm.id > COALESCE(wr.last_read_message_id, 0)
                   AND lm.character_id IN (' . $charPlaceholders . ')';
            $unreadParams = array_merge([$readerId, $locationId, $whisperType, $readerId, $cutoffAt], $otherIds);
            if ($this->hasWhisperPolicyTable()) {
                $unreadSql .= '
                   AND NOT EXISTS (
                        SELECT 1
                        FROM character_whisper_policies cp2
                        WHERE cp2.character_id = ?
                          AND cp2.target_character_id = lm.character_id
                          AND cp2.policy IN (\'mute\', \'block\')
                   )';
                $unreadParams[] = $readerId;
            }
            $unreadSql .= '
                 GROUP BY lm.character_id';
            $unreadRows = $this->fetchPrepared($unreadSql, $unreadParams);
            foreach ($unreadRows as $unreadRow) {
                $unreadById[(int) ($unreadRow->other_character_id ?? 0)] = (int) ($unreadRow->unread_count ?? 0);
            }
        }

        $rows = [];
        foreach ($threadRows as $threadRow) {
            $otherId = (int) ($threadRow->other_character_id ?? 0);
            $messageId = (int) ($threadRow->last_message_id ?? 0);
            if ($otherId <= 0 || $messageId <= 0) {
                continue;
            }
            if (!isset($messageById[$messageId]) || !isset($characterById[$otherId])) {
                continue;
            }

            $message = $messageById[$messageId];
            $character = $characterById[$otherId];
            $policy = $this->normalizeWhisperPolicy($policyById[$otherId] ?? 'allow');
            if ($policy === '') {
                $policy = 'allow';
            }
            $unreadCount = (int) ($unreadById[$otherId] ?? 0);
            if ($policy === 'mute' || $policy === 'block') {
                $unreadCount = 0;
            }

            $rows[] = (object) [
                'recipient_id' => $otherId,
                'character_name' => $character->character_name ?? null,
                'character_surname' => $character->character_surname ?? null,
                'character_avatar' => $character->character_avatar ?? null,
                'character_gender' => $character->character_gender ?? null,
                'last_message_id' => $messageId,
                'last_character_id' => $message->last_character_id ?? null,
                'last_recipient_id' => $message->last_recipient_id ?? null,
                'last_message_body' => $message->last_message_body ?? null,
                'last_meta_json' => $message->last_meta_json ?? null,
                'last_message_date' => $message->last_message_date ?? null,
                'policy' => $policy,
                'unread_count' => $unreadCount,
            ];
        }

        return $rows;
    }

    public function purgeExpiredWhispers($locationId, $whisperType, $batchSize = 300): void
    {
        $locationId = (int) $locationId;
        $whisperType = (int) $whisperType;
        $batchSize = (int) $batchSize;
        if ($locationId <= 0 || $whisperType <= 0) {
            return;
        }
        if ($batchSize < 50 || $batchSize > 1000) {
            $batchSize = 300;
        }

        $retentionHours = $this->locationWhisperRetentionHours();
        $cutoffAt = date('Y-m-d H:i:s', strtotime('-' . $retentionHours . ' hours'));
        $this->execPrepared(
            'DELETE FROM locations_messages
             WHERE location_id = ?
               AND type = ?
               AND date_created < ?
             ORDER BY id ASC
             LIMIT ?',
            [$locationId, $whisperType, $cutoffAt, $batchSize],
        );

        if ($this->hasWhisperReadStateTable()) {
            $readsRetentionHours = max(24, $retentionHours * 2);
            $readsCutoffAt = date('Y-m-d H:i:s', strtotime('-' . $readsRetentionHours . ' hours'));
            $this->execPrepared(
                'DELETE FROM location_whisper_reads
                 WHERE location_id = ?
                   AND date_updated < ?
                 LIMIT ?',
                [$locationId, $readsCutoffAt, $batchSize],
            );
        }
    }

    public function normalizeWhisperPolicy($policy): string
    {
        $raw = strtolower(trim((string) $policy));
        if ($raw === '' || $raw === 'allow') {
            return 'allow';
        }
        if ($raw === 'mute') {
            return 'mute';
        }
        if ($raw === 'block') {
            return 'block';
        }
        return '';
    }

    public function setWhisperPolicy($characterId, $targetCharacterId, string $policy): array
    {
        $characterId = (int) $characterId;
        $targetCharacterId = (int) $targetCharacterId;
        $policy = $this->normalizeWhisperPolicy($policy);

        if ($characterId <= 0 || $targetCharacterId <= 0 || $policy === '') {
            return [
                'character_id' => $characterId,
                'target_character_id' => $targetCharacterId,
                'policy' => 'allow',
            ];
        }
        if (!$this->hasWhisperPolicyTable()) {
            return [
                'character_id' => $characterId,
                'target_character_id' => $targetCharacterId,
                'policy' => 'allow',
            ];
        }

        if ($policy === 'allow') {
            $this->execPrepared(
                'DELETE FROM character_whisper_policies
                 WHERE character_id = ?
                   AND target_character_id = ?',
                [$characterId, $targetCharacterId],
            );
            return [
                'character_id' => $characterId,
                'target_character_id' => $targetCharacterId,
                'policy' => 'allow',
            ];
        }

        $this->execPrepared(
            'INSERT INTO character_whisper_policies SET
                character_id = ?,
                target_character_id = ?,
                policy = ?,
                date_created = NOW(),
                date_updated = NOW()
             ON DUPLICATE KEY UPDATE
                policy = VALUES(policy),
                date_updated = NOW()',
            [$characterId, $targetCharacterId, $policy],
        );

        return [
            'character_id' => $characterId,
            'target_character_id' => $targetCharacterId,
            'policy' => $policy,
        ];
    }

    public function getWhisperPolicy($characterId, $targetCharacterId): string
    {
        $characterId = (int) $characterId;
        $targetCharacterId = (int) $targetCharacterId;
        if ($characterId <= 0 || $targetCharacterId <= 0 || !$this->hasWhisperPolicyTable()) {
            return 'allow';
        }

        $row = $this->firstPrepared(
            'SELECT policy
             FROM character_whisper_policies
             WHERE character_id = ?
               AND target_character_id = ?
             LIMIT 1',
            [$characterId, $targetCharacterId],
        );

        if (empty($row) || empty($row->policy)) {
            return 'allow';
        }

        $normalized = $this->normalizeWhisperPolicy($row->policy);
        if ($normalized === '') {
            return 'allow';
        }

        return $normalized;
    }

    public function isWhisperBlocked($senderId, $recipientId): bool
    {
        $senderId = (int) $senderId;
        $recipientId = (int) $recipientId;
        if ($senderId <= 0 || $recipientId <= 0 || !$this->hasWhisperPolicyTable()) {
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT COUNT(*) AS count
             FROM character_whisper_policies
             WHERE policy = \'block\'
               AND (
                    (character_id = ? AND target_character_id = ?)
                    OR
                    (character_id = ? AND target_character_id = ?)
               )',
            [$senderId, $recipientId, $recipientId, $senderId],
        );

        return ((int) ($row->count ?? 0)) > 0;
    }

    public function findCharactersByName(string $targetName, int $locationId = 0): array
    {
        $targetName = trim($targetName);
        if ($targetName === '') {
            return [];
        }

        $locationClause = '';
        $exactParams = [$targetName, $targetName];
        if ($locationId > 0) {
            $locationClause = ' AND last_location = ?';
            $exactParams[] = $locationId;
        }

        $rows = $this->fetchPrepared(
            'SELECT id, name, surname FROM characters
             WHERE (name = ?
                OR CONCAT(name, " ", IFNULL(surname, "")) = ?)'
            . $locationClause . '
             LIMIT 2',
            $exactParams,
        );

        if (!empty($rows)) {
            return $rows;
        }

        $like = '%' . $targetName . '%';
        $likeParams = [$like, $like, $like];
        if ($locationId > 0) {
            $likeParams[] = $locationId;
        }
        $rows = $this->fetchPrepared(
            'SELECT id, name, surname FROM characters
             WHERE (name LIKE ?
                OR surname LIKE ?
                OR CONCAT(name, " ", IFNULL(surname, "")) LIKE ?)'
            . $locationClause . '
             LIMIT 2',
            $likeParams,
        );

        return $rows ?: [];
    }

    public function findCharacterById($characterId)
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT id, last_location FROM characters
             WHERE id = ?
             LIMIT 1',
            [$characterId],
        );
    }

    public function getCharacterNamesInLocation(int $locationId): array
    {
        if ($locationId <= 0) {
            return [];
        }
        $rows = $this->fetchPrepared(
            'SELECT name, surname FROM characters
             WHERE last_location = ?
               AND name != \'\'
             LIMIT 100',
            [$locationId],
        );
        return is_array($rows) ? $rows : [];
    }

    public function insertMessage(
        $locationId,
        $characterId,
        $type,
        $body,
        $metaJson = null,
        $recipientId = null,
        $tagPosition = null,
    ) {
        $locationId = (int) $locationId;
        $characterId = (int) $characterId;
        $type = (int) $type;

        $this->execPrepared(
            'INSERT INTO locations_messages
            (location_id, character_id, type, recipient_id, tag_position, body, meta_json)
            VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $locationId,
                $characterId,
                $type,
                ($recipientId !== null ? (int) $recipientId : null),
                ($tagPosition !== null && $tagPosition !== '' ? $tagPosition : null),
                (string) $body,
                ($metaJson !== null && $metaJson !== '' ? $metaJson : null),
            ],
        );
        $lastId = $this->db->lastInsertId();
        if (empty($lastId)) {
            return null;
        }

        return $this->fetchMessageById($lastId);
    }
}
