<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class CharacterBondService
{
    /** @var DbAdapterInterface */
    private $db;

    /** @var string[] */
    private $allowedBondTypes = [
        'conoscente',
        'amico',
        'alleato',
        'rivale',
        'famiglia',
        'mentore',
    ];

    /** @var string[] */
    private $allowedActionTypes = [
        'create',
        'change_type',
        'close',
    ];

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

    private function normalizeString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return $value;
    }

    private function normalizePair(int $a, int $b): array
    {
        if ($a < $b) {
            return ['low' => $a, 'high' => $b];
        }

        return ['low' => $b, 'high' => $a];
    }

    private function assertCharacterExists(int $characterId): void
    {
        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $row = $this->firstPrepared(
            'SELECT id
             FROM characters
             WHERE id = ?
             LIMIT 1',
            [$characterId],
        );

        if (empty($row)) {
            throw AppError::notFound('Personaggio non trovato', [], 'character_not_found');
        }
    }

    private function normalizeBondType($value, bool $required): ?string
    {
        $type = $this->normalizeString($value);
        if ($type === null) {
            if ($required) {
                throw AppError::validation('Tipo legame non valido', [], 'bond_type_invalid');
            }
            return null;
        }

        $type = strtolower($type);
        if (!in_array($type, $this->allowedBondTypes, true)) {
            throw AppError::validation('Tipo legame non valido', [], 'bond_type_invalid');
        }

        return $type;
    }

    private function normalizeActionType($value): string
    {
        $action = strtolower((string) $value);
        if (!in_array($action, $this->allowedActionTypes, true)) {
            throw AppError::validation('Azione non valida', [], 'bond_action_invalid');
        }

        return $action;
    }

    private function getPendingPairRequestForUpdate(int $a, int $b)
    {
        return $this->firstPrepared(
            'SELECT id
             FROM character_bond_requests
             WHERE status = "pending"
               AND (
                    (requester_id = ? AND target_id = ?)
                    OR
                    (requester_id = ? AND target_id = ?)
               )
             LIMIT 1
             FOR UPDATE',
            [$a, $b, $b, $a],
        );
    }

    private function getBondByPairForUpdate(int $lowId, int $highId)
    {
        return $this->firstPrepared(
            'SELECT *
             FROM character_bonds
             WHERE character_low_id = ?
               AND character_high_id = ?
             LIMIT 1
             FOR UPDATE',
            [$lowId, $highId],
        );
    }

    private function resolveLastInsertId(): int
    {
        return $this->db->lastInsertId();
    }

    private function stringLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }

    public function listBondsForProfile(int $viewerId, int $targetCharacterId): array
    {
        $this->assertCharacterExists($targetCharacterId);

        $onlyPublic = ($viewerId !== $targetCharacterId);
        $publicSql = $onlyPublic ? ' AND b.is_public = 1' : '';

        $bonds = $this->fetchPrepared(
            'SELECT
                b.id,
                b.bond_type,
                b.intensity,
                b.status,
                b.is_public,
                b.last_interaction_at,
                b.date_created,
                b.date_updated,
                CASE
                    WHEN b.character_low_id = ? THEN b.character_high_id
                    ELSE b.character_low_id
                END AS other_character_id,
                CASE
                    WHEN b.character_low_id = ? THEN c_high.name
                    ELSE c_low.name
                END AS other_character_name,
                CASE
                    WHEN b.character_low_id = ? THEN c_high.avatar
                    ELSE c_low.avatar
                END AS other_character_avatar
             FROM character_bonds b
             LEFT JOIN characters c_low ON c_low.id = b.character_low_id
             LEFT JOIN characters c_high ON c_high.id = b.character_high_id
             WHERE (b.character_low_id = ? OR b.character_high_id = ?)
               AND b.status = "active"' . $publicSql . '
             ORDER BY b.intensity DESC, b.date_updated DESC, b.id DESC',
            [
                $targetCharacterId,
                $targetCharacterId,
                $targetCharacterId,
                $targetCharacterId,
                $targetCharacterId,
            ],
        );

        $incoming = [];
        $outgoing = [];
        if (!$onlyPublic) {
            $incoming = $this->fetchPrepared(
                'SELECT
                    r.id,
                    r.requester_id,
                    r.target_id,
                    r.action_type,
                    r.requested_type,
                    r.message,
                    r.status,
                    r.date_created,
                    c.name AS requester_name,
                    c.avatar AS requester_avatar
                 FROM character_bond_requests r
                 LEFT JOIN characters c ON c.id = r.requester_id
                 WHERE r.target_id = ?
                   AND r.status = "pending"
                 ORDER BY r.date_created DESC, r.id DESC',
                [$targetCharacterId],
            );

            $outgoing = $this->fetchPrepared(
                'SELECT
                    r.id,
                    r.requester_id,
                    r.target_id,
                    r.action_type,
                    r.requested_type,
                    r.message,
                    r.status,
                    r.date_created,
                    c.name AS target_name,
                    c.avatar AS target_avatar
                 FROM character_bond_requests r
                 LEFT JOIN characters c ON c.id = r.target_id
                 WHERE r.requester_id = ?
                   AND r.status = "pending"
                 ORDER BY r.date_created DESC, r.id DESC',
                [$targetCharacterId],
            );
        }

        return [
            'character_id' => $targetCharacterId,
            'viewer_id' => $viewerId,
            'visibility_scope' => $onlyPublic ? 'public' : 'owner',
            'bonds' => $bonds,
            'incoming_requests' => $incoming,
            'outgoing_requests' => $outgoing,
        ];
    }

    public function createBondRequest(
        int $requesterId,
        int $targetId,
        string $actionType,
        ?string $requestedType,
        ?string $message,
    ): array {
        if ($requesterId <= 0 || $targetId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        if ($requesterId === $targetId) {
            throw AppError::validation('Non puoi creare un legame con te stesso', [], 'bond_self_not_allowed');
        }

        $this->assertCharacterExists($requesterId);
        $this->assertCharacterExists($targetId);

        $actionType = $this->normalizeActionType($actionType);
        $requestedType = $this->normalizeBondType(
            $requestedType,
            ($actionType === 'create' || $actionType === 'change_type'),
        );

        $message = $this->normalizeString($message);
        if ($message !== null && $this->stringLength($message) > 1000) {
            throw AppError::validation('Messaggio troppo lungo', [], 'bond_request_message_too_long');
        }

        $pair = $this->normalizePair($requesterId, $targetId);

        $this->begin();
        try {
            $pending = $this->getPendingPairRequestForUpdate($requesterId, $targetId);
            if (!empty($pending)) {
                throw AppError::validation('Esiste gia una richiesta in attesa', [], 'bond_request_pending');
            }

            $bond = $this->getBondByPairForUpdate((int) $pair['low'], (int) $pair['high']);

            if ($actionType === 'create' && !empty($bond) && (string) $bond->status === 'active') {
                throw AppError::validation('Legame gia esistente', [], 'bond_already_exists');
            }

            if (($actionType === 'change_type' || $actionType === 'close')
                && (empty($bond) || (string) $bond->status !== 'active')) {
                throw AppError::validation('Legame non trovato', [], 'bond_not_found');
            }

            $this->execPrepared(
                'INSERT INTO character_bond_requests
                    (requester_id, target_id, action_type, requested_type, message, status, date_created, date_resolved, resolved_by)
                 VALUES
                    (?, ?, ?, ?, ?, "pending", NOW(), NULL, NULL)',
                [$requesterId, $targetId, $actionType, $requestedType, $message],
            );

            $requestId = $this->resolveLastInsertId();
            $this->commit();

            // Notifica action_required al destinatario
            $this->fireRequestNotification($requesterId, $targetId, $requestId, $actionType, $requestedType);

            return [
                'request_id' => $requestId,
                'status' => 'pending',
                'action_type' => $actionType,
                'requested_type' => $requestedType,
            ];
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    private function fireRequestNotification(
        int $requesterId,
        int $targetId,
        int $requestId,
        string $actionType,
        ?string $requestedType,
    ): void {
        try {
            $requester = $this->firstPrepared(
                'SELECT user_id, name FROM characters WHERE id = ? LIMIT 1',
                [$requesterId],
            );
            $target = $this->firstPrepared(
                'SELECT user_id FROM characters WHERE id = ? LIMIT 1',
                [$targetId],
            );

            if (empty($requester) || empty($target)) {
                return;
            }

            $actionLabel = [
                'create' => 'creare un legame',
                'change_type' => 'cambiare il tipo di legame',
                'close' => 'chiudere il legame',
            ][$actionType] ?? 'modificare il legame';

            $typeLabel = $requestedType ? (' (' . $requestedType . ')') : '';
            $title = (string) ($requester->name ?? 'Qualcuno') . ' vuole ' . $actionLabel . $typeLabel;

            $notifService = new NotificationService($this->db);
            $notifService->create(
                (int) $target->user_id,
                $targetId,
                NotificationService::KIND_ACTION_REQUIRED,
                'bond_request',
                $title,
                [
                    'actor_user_id' => (int) $requester->user_id,
                    'actor_character_id' => $requesterId,
                    'source_type' => 'bond_request',
                    'source_id' => $requestId,
                    'action_url' => '/game/profile',
                    'priority' => 'normal',
                ],
            );
        } catch (\Throwable $e) {
            // fire-and-forget: non bloccare il flusso principale
        }
    }

    public function respondBondRequest(int $responderId, int $requestId, string $decision): array
    {
        if ($responderId <= 0 || $requestId <= 0) {
            throw AppError::validation('Dati non validi', [], 'payload_invalid');
        }

        $decision = strtolower(trim($decision));
        if ($decision !== 'accepted' && $decision !== 'rejected') {
            throw AppError::validation('Decisione non valida', [], 'bond_request_decision_invalid');
        }

        $this->begin();
        try {
            $request = $this->firstPrepared(
                'SELECT *
                 FROM character_bond_requests
                 WHERE id = ?
                   AND status = "pending"
                 LIMIT 1
                 FOR UPDATE',
                [$requestId],
            );

            if (empty($request)) {
                throw AppError::validation('Richiesta non trovata o gia risolta', [], 'bond_request_not_found');
            }

            if ((int) $request->target_id !== $responderId) {
                throw AppError::unauthorized('Operazione non autorizzata', [], 'bond_request_forbidden');
            }

            $bondId = null;
            if ($decision === 'accepted') {
                $pair = $this->normalizePair((int) $request->requester_id, (int) $request->target_id);
                $bond = $this->getBondByPairForUpdate((int) $pair['low'], (int) $pair['high']);
                $actionType = (string) $request->action_type;
                $requestedType = $this->normalizeBondType($request->requested_type ?? null, false);

                if ($actionType === 'create') {
                    $type = $requestedType ?: 'conoscente';
                    if (empty($bond)) {
                        $this->execPrepared(
                            'INSERT INTO character_bonds
                                (character_low_id, character_high_id, bond_type, intensity, status, is_public, created_by_character_id, last_interaction_at, date_created, date_updated)
                             VALUES
                                (?, ?, ?, 0, "active", 1, ?, NOW(), NOW(), NOW())',
                            [(int) $pair['low'], (int) $pair['high'], $type, (int) $request->requester_id],
                        );
                        $bondId = $this->resolveLastInsertId();
                    } else {
                        $this->execPrepared(
                            'UPDATE character_bonds SET
                                bond_type = ?,
                                status = "active",
                                date_updated = NOW()
                            WHERE id = ?',
                            [$type, (int) $bond->id],
                        );
                        $bondId = (int) $bond->id;
                    }
                } elseif ($actionType === 'change_type') {
                    if (empty($bond) || (string) $bond->status !== 'active') {
                        throw AppError::validation('Legame non trovato', [], 'bond_not_found');
                    }

                    $type = $requestedType ?: 'conoscente';
                    $this->execPrepared(
                        'UPDATE character_bonds SET
                            bond_type = ?,
                            date_updated = NOW()
                        WHERE id = ?',
                        [$type, (int) $bond->id],
                    );
                    $bondId = (int) $bond->id;
                } elseif ($actionType === 'close') {
                    if (empty($bond) || (string) $bond->status !== 'active') {
                        throw AppError::validation('Legame non trovato', [], 'bond_not_found');
                    }

                    $this->execPrepared(
                        'UPDATE character_bonds SET
                            status = "closed",
                            date_updated = NOW()
                        WHERE id = ?',
                        [(int) $bond->id],
                    );
                    $bondId = (int) $bond->id;
                } else {
                    throw AppError::validation('Azione non valida', [], 'bond_action_invalid');
                }
            }

            $this->execPrepared(
                'UPDATE character_bond_requests SET
                    status = ?,
                    date_resolved = NOW(),
                    resolved_by = ?
                WHERE id = ?',
                [$decision, $responderId, $requestId],
            );

            $this->commit();

            // Sincronizza notifica pendente se risposta via endpoint diretto
            $this->resolveRequestNotification($responderId, $requestId, $decision);

            return [
                'request_id' => $requestId,
                'decision' => $decision,
                'bond_id' => $bondId,
            ];
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    private function resolveRequestNotification(int $responderId, int $requestId, string $decision): void
    {
        try {
            $target = $this->firstPrepared(
                'SELECT user_id FROM characters WHERE id = ? LIMIT 1',
                [$responderId],
            );
            if (empty($target)) {
                return;
            }
            $notifService = new NotificationService($this->db);
            $notifService->resolveBySource((int) $target->user_id, 'bond_request', $requestId, $decision);
        } catch (\Throwable $e) {
            // fire-and-forget
        }
    }
}
