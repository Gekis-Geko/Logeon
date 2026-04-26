<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class ChatArchiveService
{
    private const SOURCE_TYPE_LOCATION = 'location';
    private const WHISPER_TYPE = 4;
    private const RANGE_LIMIT = 500;

    /** @var DbAdapterInterface */
    private $db;
    /** @var bool|null */
    private $archiveMetadataColumnAvailable = null;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    /** @return array<int,mixed> */
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
            // Best effort.
        }
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function generatePublicToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** @param string[] $htmlParts */
    private function computeChecksum(array $htmlParts): string
    {
        return hash('sha256', implode('|', $htmlParts));
    }

    /** @return array<string,mixed> */
    private function decodeJsonObject($json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $payload */
    private function encodeJsonObject(array $payload): ?string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : null;
    }

    private function hydrateArchiveRow($row): ?object
    {
        if ($row === null) {
            return null;
        }

        if (!is_object($row)) {
            $row = (object) $row;
        }

        $row->metadata = $this->decodeJsonObject($row->metadata_json ?? null);

        return $row;
    }

    /** @return array<int,mixed> */
    private function hydrateMessageRows(array $rows): array
    {
        $dataset = [];

        foreach ($rows as $row) {
            if (!is_object($row)) {
                $row = (object) $row;
            }

            $row->metadata = $this->decodeJsonObject($row->metadata_json ?? null);
            $dataset[] = $row;
        }

        return $dataset;
    }

    private function buildCharacterDisplayNameFromRow($row): string
    {
        $name = trim(
            ((string) ($row->character_name ?? ''))
            . ' '
            . ((string) ($row->character_surname ?? ''))
        );

        if ($name !== '') {
            return $name;
        }

        $snapshotName = trim((string) ($row->character_name_snapshot ?? ''));
        if ($snapshotName !== '') {
            return $snapshotName;
        }

        $characterId = isset($row->character_id) ? (int) $row->character_id : 0;

        return $characterId > 0 ? ('Personaggio #' . $characterId) : '';
    }

    private function fetchCharacterDisplayName(int $characterId): string
    {
        if ($characterId <= 0) {
            return '';
        }

        $row = $this->firstPrepared(
            'SELECT name, surname FROM characters WHERE id = ? LIMIT 1',
            [$characterId],
        );

        if ($row === null) {
            return 'Personaggio #' . $characterId;
        }

        $name = trim(
            ((string) ($row->name ?? ''))
            . ' '
            . ((string) ($row->surname ?? ''))
        );

        return $name !== '' ? $name : ('Personaggio #' . $characterId);
    }

    /** @return array<int,array{character_id:int,name:string}> */
    private function collectParticipantsFromRows(array $rows): array
    {
        $participants = [];

        foreach ($rows as $row) {
            $characterId = isset($row->character_id) ? (int) $row->character_id : 0;
            if ($characterId <= 0) {
                continue;
            }

            $name = $this->buildCharacterDisplayNameFromRow($row);
            if ($name === '') {
                continue;
            }

            $participants[$characterId] = [
                'character_id' => $characterId,
                'name' => $name,
            ];
        }

        ksort($participants);

        return $participants;
    }

    /** @return array<int,mixed> */
    private function fetchPublicLocationMessagesInRange(int $locationId, string $startedAt, string $endedAt): array
    {
        if ($locationId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT lm.id, lm.location_id, lm.character_id, lm.type, lm.body, lm.meta_json,
                    lm.date_created, lm.recipient_id,
                    c.name AS character_name, c.surname AS character_surname,
                    c.avatar AS character_avatar, c.gender AS character_gender
             FROM locations_messages lm
             JOIN characters c ON c.id = lm.character_id
             WHERE lm.location_id = ?
               AND lm.date_created >= ?
               AND lm.date_created <= ?
               AND lm.type <> ?
               AND lm.recipient_id IS NULL
             ORDER BY lm.id ASC
             LIMIT ?',
            [$locationId, $startedAt, $endedAt, self::WHISPER_TYPE, self::RANGE_LIMIT],
        );

        return $rows ?: [];
    }

    private function hasArchiveMetadataColumn(): bool
    {
        if ($this->archiveMetadataColumnAvailable !== null) {
            return $this->archiveMetadataColumnAvailable;
        }

        try {
            $row = $this->firstPrepared(
                'SELECT 1
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                 LIMIT 1',
                ['character_chat_archives', 'metadata_json'],
            );
            $this->archiveMetadataColumnAvailable = !empty($row);
        } catch (\Throwable $e) {
            $this->archiveMetadataColumnAvailable = false;
        }

        return $this->archiveMetadataColumnAvailable;
    }

    private function archiveMetadataSelectSql(string $alias = 'a'): string
    {
        return $this->hasArchiveMetadataColumn()
            ? ($alias . '.metadata_json')
            : 'NULL AS metadata_json';
    }

    private function getByIdQuery(): string
    {
        $archiveSelect = $this->hasArchiveMetadataColumn()
            ? 'a.*'
            : 'a.*, NULL AS metadata_json';

        $sql = 'SELECT ' . $archiveSelect . ', l.name AS location_name,
                       ce.title AS diary_event_title,
                       ce.is_visible AS diary_event_public
                FROM character_chat_archives a
                LEFT JOIN locations l ON l.id = a.source_location_id
                LEFT JOIN character_events ce ON ce.id = a.diary_event_id
                WHERE a.id = ?
                  AND a.deleted_at IS NULL
                LIMIT 1';

        return $sql;
    }

    public function listByOwner(int $characterId, int $userId, array $filters = []): array
    {
        if ($userId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT a.id, a.uuid, a.title, a.description, ' . $this->archiveMetadataSelectSql('a') . ',
                    a.source_type, a.source_location_id,
                    a.started_at, a.ended_at,
                    a.completeness_level,
                    a.total_messages_in_range, a.included_messages_count,
                    a.total_participants_in_range, a.included_participants_count,
                    a.public_enabled, a.public_token,
                    a.diary_event_id, ce.title AS diary_event_title,
                    a.created_at, a.updated_at,
                    l.name AS location_name
             FROM character_chat_archives a
             LEFT JOIN locations l ON l.id = a.source_location_id
             LEFT JOIN character_events ce ON ce.id = a.diary_event_id
             WHERE a.owner_user_id = ?
               AND a.deleted_at IS NULL
             ORDER BY a.created_at DESC',
            [$userId],
        );

        $dataset = [];
        foreach ($rows as $row) {
            $dataset[] = $this->hydrateArchiveRow($row);
        }

        return $dataset;
    }

    private function canViewArchiveRow(object $row, int $userId = 0, bool $isStaff = false, ?int $viaDiaryEventId = null): bool
    {
        if ($isStaff) {
            return true;
        }

        if ($userId > 0 && isset($row->owner_user_id) && (int) $row->owner_user_id === $userId) {
            return true;
        }

        if (isset($row->public_enabled) && (int) $row->public_enabled === 1) {
            return true;
        }

        if (
            $viaDiaryEventId !== null
            && $viaDiaryEventId > 0
            && isset($row->diary_event_id)
            && (int) $row->diary_event_id === $viaDiaryEventId
            && isset($row->diary_event_public)
            && (int) $row->diary_event_public === 1
        ) {
            return true;
        }

        return false;
    }

    private function getRowById(int $id)
    {
        if ($id <= 0) {
            return null;
        }

        return $this->firstPrepared($this->getByIdQuery(), [$id]);
    }

    public function getById(int $id, int $characterId, int $userId = 0, bool $isStaff = false, ?int $viaDiaryEventId = null): ?object
    {
        if ($id <= 0) {
            return null;
        }

        if (!$isStaff && $userId <= 0) {
            return null;
        }

        $row = $this->getRowById($id);
        if (empty($row) || !$this->canViewArchiveRow($row, $userId, $isStaff, $viaDiaryEventId)) {
            return null;
        }

        return $this->hydrateArchiveRow($row ?: null);
    }

    public function getOwnedById(int $id, int $characterId, int $userId = 0, bool $isStaff = false): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->getRowById($id);
        if (empty($row)) {
            return null;
        }

        if (!$isStaff) {
            if ($userId <= 0 || !isset($row->owner_user_id) || (int) $row->owner_user_id !== $userId) {
                return null;
            }
        }

        return $this->hydrateArchiveRow($row ?: null);
    }

    /**
     * @return array{archive:object,messages:array<int,mixed>}|null
     */
    public function getWithMessages(int $id, int $characterId, int $userId = 0, bool $isStaff = false, ?int $viaDiaryEventId = null): ?array
    {
        $archive = $this->getById($id, $characterId, $userId, $isStaff, $viaDiaryEventId);
        if ($archive === null) {
            return null;
        }

        $messages = $this->fetchPrepared(
            'SELECT id, source_message_id, sent_at, character_id,
                    character_name_snapshot, message_type, message_html, metadata_json
             FROM character_chat_archive_messages
             WHERE archive_id = ?
             ORDER BY id ASC',
            [$id],
        );

        return [
            'archive' => $archive,
            'messages' => $this->hydrateMessageRows($messages ?: []),
        ];
    }

    public function create(int $userId, int $characterId, array $payload): int
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $description = isset($payload['description']) ? trim((string) $payload['description']) : null;
        $locationId = (int) ($payload['source_location_id'] ?? 0);
        $startedAt = trim((string) ($payload['started_at'] ?? ''));
        $endedAt = trim((string) ($payload['ended_at'] ?? ''));

        /** @var int[] $messageIds */
        $messageIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($payload['message_ids'] ?? [])),
            static fn(int $id): bool => $id > 0
        )));

        if ($title === '') {
            throw AppError::validation('Il titolo e obbligatorio', [], 'title_required');
        }
        if ($locationId <= 0) {
            throw AppError::validation('Location obbligatoria', [], 'location_required');
        }
        if ($startedAt === '' || $endedAt === '') {
            throw AppError::validation('Intervallo date obbligatorio', [], 'date_range_required');
        }
        if (strtotime($startedAt) > strtotime($endedAt)) {
            throw AppError::validation('La data di inizio deve precedere quella di fine', [], 'date_range_invalid');
        }
        if (empty($messageIds)) {
            throw AppError::validation('Seleziona almeno un messaggio', [], 'messages_required');
        }

        $rangeRows = $this->fetchPublicLocationMessagesInRange($locationId, $startedAt, $endedAt);
        if (empty($rangeRows)) {
            throw AppError::validation('Nessun messaggio pubblico trovato nel range selezionato', [], 'messages_not_found');
        }

        $selectedById = array_fill_keys($messageIds, true);
        $selectedRows = [];
        $seenSelected = [];

        foreach ($rangeRows as $row) {
            $messageId = isset($row->id) ? (int) $row->id : 0;
            if ($messageId > 0 && isset($selectedById[$messageId])) {
                $selectedRows[] = $row;
                $seenSelected[$messageId] = true;
            }
        }

        foreach ($messageIds as $messageId) {
            if (!isset($seenSelected[$messageId])) {
                throw AppError::validation(
                    'Uno o piu messaggi selezionati non appartengono alla scena pubblica nel range scelto',
                    [],
                    'messages_out_of_range'
                );
            }
        }

        $messageService = new LocationMessageService($this->db);
        $htmlParts = [];
        $renderedRows = [];

        foreach ($selectedRows as $row) {
            $rendered = $messageService->buildMessageResponse($row);
            $html = isset($rendered->body_rendered) && is_string($rendered->body_rendered)
                ? $rendered->body_rendered
                : (isset($rendered->body) && is_string($rendered->body) ? $rendered->body : '');

            $htmlParts[] = $html;
            $renderedRows[] = ['row' => $rendered, 'html' => $html];
        }

        $allParticipants = $this->collectParticipantsFromRows($rangeRows);
        $includedParticipants = $this->collectParticipantsFromRows($selectedRows);
        $excludedParticipants = array_values(array_diff_key($allParticipants, $includedParticipants));

        $totalInRange = count($rangeRows);
        $includedCount = count($renderedRows);
        $totalParticipants = count($allParticipants);
        $includedParticipantsCount = count($includedParticipants);
        $completeness = ($includedCount === $totalInRange) ? 'complete' : 'partial';
        $checksum = $this->computeChecksum($htmlParts);
        $uuid = $this->generateUuid();

        $metadata = [
            'created_by_name' => $this->fetchCharacterDisplayName($characterId),
            'included_participants' => array_values($includedParticipants),
            'excluded_participants' => $excludedParticipants,
        ];

        $this->begin();
        try {
            if ($this->hasArchiveMetadataColumn()) {
                $this->execPrepared(
                    'INSERT INTO character_chat_archives
                        (uuid, owner_user_id, owner_character_id, title, description, metadata_json,
                         source_type, source_location_id, started_at, ended_at,
                         visibility, total_messages_in_range, included_messages_count,
                         total_participants_in_range, included_participants_count,
                         completeness_level, checksum_hash, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [
                        $uuid,
                        $userId,
                        $characterId,
                        $title,
                        $description,
                        $this->encodeJsonObject($metadata),
                        self::SOURCE_TYPE_LOCATION,
                        $locationId,
                        $startedAt,
                        $endedAt,
                        'private',
                        $totalInRange,
                        $includedCount,
                        $totalParticipants,
                        $includedParticipantsCount,
                        $completeness,
                        $checksum,
                    ],
                );
            } else {
                $this->execPrepared(
                    'INSERT INTO character_chat_archives
                        (uuid, owner_user_id, owner_character_id, title, description,
                         source_type, source_location_id, started_at, ended_at,
                         visibility, total_messages_in_range, included_messages_count,
                         total_participants_in_range, included_participants_count,
                         completeness_level, checksum_hash, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [
                        $uuid,
                        $userId,
                        $characterId,
                        $title,
                        $description,
                        self::SOURCE_TYPE_LOCATION,
                        $locationId,
                        $startedAt,
                        $endedAt,
                        'private',
                        $totalInRange,
                        $includedCount,
                        $totalParticipants,
                        $includedParticipantsCount,
                        $completeness,
                        $checksum,
                    ],
                );
            }

            $archiveId = $this->db->lastInsertId();

            foreach ($renderedRows as $item) {
                $row = $item['row'];
                $html = $item['html'];
                $sentAt = isset($row->date_created) ? (string) $row->date_created : date('Y-m-d H:i:s');
                $charName = $this->buildCharacterDisplayNameFromRow($row);
                $messageMetadataJson = null;
                if (isset($row->meta_json) && is_string($row->meta_json) && trim($row->meta_json) !== '') {
                    $messageMetadataJson = $row->meta_json;
                }

                $this->execPrepared(
                    'INSERT INTO character_chat_archive_messages
                        (archive_id, source_message_id, sent_at, character_id,
                         character_name_snapshot, message_type, message_html, metadata_json)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $archiveId,
                        isset($row->id) ? (int) $row->id : null,
                        $sentAt,
                        isset($row->character_id) ? (int) $row->character_id : null,
                        $charName,
                        isset($row->type) ? (int) $row->type : 1,
                        $html,
                        $messageMetadataJson,
                    ],
                );
            }

            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }

        return $archiveId;
    }

    public function update(int $id, int $characterId, array $payload, int $userId = 0, bool $isStaff = false): void
    {
        $archive = $this->getOwnedById($id, $characterId, $userId, $isStaff);
        if ($archive === null) {
            throw AppError::notFound('Archivio non trovato', [], 'archive_not_found');
        }

        $title = trim((string) ($payload['title'] ?? ''));
        $description = isset($payload['description']) ? trim((string) $payload['description']) : null;

        if ($title === '') {
            throw AppError::validation('Il titolo e obbligatorio', [], 'title_required');
        }

        $this->execPrepared(
            'UPDATE character_chat_archives SET title = ?, description = ?, updated_at = NOW()
             WHERE id = ?',
            [$title, $description, $id],
        );
    }

    public function softDelete(int $id, int $characterId, int $userId = 0, bool $isStaff = false): void
    {
        $archive = $this->getOwnedById($id, $characterId, $userId, $isStaff);
        if ($archive === null) {
            throw AppError::notFound('Archivio non trovato', [], 'archive_not_found');
        }

        $this->execPrepared(
            'UPDATE character_chat_archives SET deleted_at = NOW() WHERE id = ?',
            [$id],
        );
    }

    public function setPublic(int $id, int $characterId, bool $enabled, int $userId = 0, bool $isStaff = false): object
    {
        $archive = $this->getOwnedById($id, $characterId, $userId, $isStaff);
        if ($archive === null) {
            throw AppError::notFound('Archivio non trovato', [], 'archive_not_found');
        }

        $token = isset($archive->public_token) && $archive->public_token !== null
            ? (string) $archive->public_token
            : null;

        if ($enabled && $token === null) {
            $token = $this->generatePublicToken();
        }

        $this->execPrepared(
            'UPDATE character_chat_archives
             SET visibility = ?, public_enabled = ?, public_token = ?, updated_at = NOW()
             WHERE id = ?',
            [$enabled ? 'public' : 'private', $enabled ? 1 : 0, $token, $id],
        );

        $updated = $this->getOwnedById($id, $characterId, $userId, $isStaff);
        if ($updated === null) {
            throw AppError::notFound('Archivio non trovato', [], 'archive_not_found');
        }

        return $updated;
    }

    public function linkDiary(int $id, int $characterId, ?int $diaryEventId, int $userId = 0, bool $isStaff = false): void
    {
        $archive = $this->getOwnedById($id, $characterId, $userId, $isStaff);
        if ($archive === null) {
            throw AppError::notFound('Archivio non trovato', [], 'archive_not_found');
        }

        if ($diaryEventId !== null) {
            $ownerCharacterId = isset($archive->owner_character_id) ? (int) $archive->owner_character_id : $characterId;
            $event = $this->firstPrepared(
                'SELECT id FROM character_events WHERE id = ? AND character_id = ? LIMIT 1',
                [$diaryEventId, $ownerCharacterId],
            );
            if (empty($event)) {
                throw AppError::validation('Evento di diario non trovato', [], 'diary_event_not_found');
            }
        }

        $this->execPrepared(
            'UPDATE character_chat_archives SET diary_event_id = ?, updated_at = NOW() WHERE id = ?',
            [$diaryEventId, $id],
        );
    }

    /**
     * @return array{archive:object,messages:array<int,mixed>}|null
     */
    public function getByPublicToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $archiveSelect = $this->hasArchiveMetadataColumn()
            ? 'a.*'
            : 'a.*, NULL AS metadata_json';

        $archive = $this->firstPrepared(
            'SELECT ' . $archiveSelect . ', l.name AS location_name
             FROM character_chat_archives a
             LEFT JOIN locations l ON l.id = a.source_location_id
             WHERE a.public_token = ?
               AND a.public_enabled = 1
               AND a.deleted_at IS NULL
             LIMIT 1',
            [$token],
        );

        if (empty($archive)) {
            return null;
        }

        $archive = $this->hydrateArchiveRow($archive);
        if ($archive === null) {
            return null;
        }

        $messages = $this->fetchPrepared(
            'SELECT id, source_message_id, sent_at, character_id,
                    character_name_snapshot, message_type, message_html, metadata_json
             FROM character_chat_archive_messages
             WHERE archive_id = ?
             ORDER BY id ASC',
            [(int) $archive->id],
        );

        return [
            'archive' => $archive,
            'messages' => $this->hydrateMessageRows($messages ?: []),
        ];
    }
}
