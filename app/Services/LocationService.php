<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class LocationService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var array|null */
    private $inviteConfig = null;

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
            // no-op: rollback best effort
        }
    }

    public function updateMapCoordinates(int $locationId, ?float $mapX, ?float $mapY): void
    {
        if ($locationId <= 0) {
            throw AppError::validation('Dati non validi', [], 'payload_invalid');
        }

        $this->execPrepared(
            'UPDATE locations SET
                map_x = ?,
                map_y = ?
            WHERE id = ?',
            [$mapX, $mapY, $locationId],
        );
    }

    public function createOrRefreshInvite(int $ownerId, int $locationId, int $invitedId): void
    {
        if ($ownerId <= 0 || $locationId <= 0 || $invitedId <= 0) {
            throw AppError::validation('Dati invito non validi', [], 'invite_payload_invalid');
        }

        $this->begin();
        try {
            $this->firstPrepared(
                'SELECT id FROM characters WHERE id = ? LIMIT 1 FOR UPDATE',
                [$ownerId],
            );

            $location = $this->firstPrepared(
                'SELECT id, owner_id, is_private, is_house
                 FROM locations
                 WHERE id = ?
                 LIMIT 1
                 FOR UPDATE',
                [$locationId],
            );

            if (empty($location)) {
                throw AppError::validation('Location non trovata', [], 'location_not_found');
            }

            if ((int) $location->owner_id !== $ownerId) {
                throw AppError::validation('Non sei il proprietario della location', [], 'invite_owner_required');
            }

            if ((int) $location->is_private !== 1 && (int) $location->is_house !== 1) {
                throw AppError::validation('La location non e privata', [], 'invite_private_only');
            }

            $target = $this->firstPrepared(
                'SELECT id, invite_policy
                 FROM characters
                 WHERE id = ?
                 LIMIT 1
                 FOR UPDATE',
                [$invitedId],
            );
            if (empty($target)) {
                throw AppError::validation('Personaggio non valido', [], 'character_invalid');
            }

            $invitePolicy = (int) ($target->invite_policy ?? 0);
            \Core\AuthGuard::ensureInviteAllowed($ownerId, $invitedId, $invitePolicy);

            $inviteConfig = $this->getInviteConfig();
            $maxActive = (int) $inviteConfig['max_active'];
            if ($maxActive > 0) {
                $countRow = $this->firstPrepared(
                    'SELECT COUNT(*) AS tot
                     FROM location_invites
                     WHERE owner_id = ?
                       AND status = "pending"
                       AND (expires_at IS NULL OR expires_at > NOW())',
                    [$ownerId],
                );
                if (!empty($countRow) && (int) $countRow->tot >= $maxActive) {
                    throw AppError::validation('Hai raggiunto il limite di inviti attivi', [], 'invite_limit_reached');
                }
            }

            $expiryHours = (int) $inviteConfig['expiry_hours'];
            $expiresAt = null;
            if ($expiryHours > 0) {
                $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $expiryHours . ' hours'));
            }

            $this->execPrepared(
                'INSERT INTO location_invites SET
                    location_id = ?,
                    owner_id = ?,
                    invited_id = ?,
                    status = "pending",
                    owner_notified = 0,
                    date_created = NOW(),
                    expires_at = ?,
                    date_responded = NULL
                ON DUPLICATE KEY UPDATE
                    owner_id = VALUES(owner_id),
                    status = "pending",
                    owner_notified = 0,
                    date_created = NOW(),
                    expires_at = VALUES(expires_at),
                    date_responded = NULL',
                [$locationId, $ownerId, $invitedId, $expiresAt],
            );

            $inviteId = (int) $this->db->lastInsertId();
            if ($inviteId <= 0) {
                // ON DUPLICATE KEY UPDATE: recupera id esistente
                $existing = $this->firstPrepared(
                    'SELECT id FROM location_invites
                     WHERE location_id = ?
                       AND invited_id  = ?
                     LIMIT 1',
                    [$locationId, $invitedId],
                );
                $inviteId = $existing ? (int) $existing->id : 0;
            }

            $this->commit();

            // Notifica action_required al destinatario dell'invito
            if ($inviteId > 0) {
                $this->fireInviteNotification($ownerId, $invitedId, $locationId, $inviteId);
            }
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    private function fireInviteNotification(int $ownerId, int $invitedId, int $locationId, int $inviteId): void
    {
        try {
            $owner = $this->firstPrepared(
                'SELECT user_id, name FROM characters WHERE id = ? LIMIT 1',
                [$ownerId],
            );
            $invited = $this->firstPrepared(
                'SELECT user_id FROM characters WHERE id = ? LIMIT 1',
                [$invitedId],
            );
            $location = $this->firstPrepared(
                'SELECT name FROM locations WHERE id = ? LIMIT 1',
                [$locationId],
            );

            if (empty($owner) || empty($invited)) {
                return;
            }

            $locationName = $location ? (string) $location->name : 'una location';
            $title = (string) ($owner->name ?? 'Qualcuno') . ' ti ha invitato a ' . $locationName;

            $notifService = new NotificationService($this->db);
            $notifService->create(
                (int) $invited->user_id,
                $invitedId,
                NotificationService::KIND_ACTION_REQUIRED,
                'location_invite',
                $title,
                [
                    'actor_user_id' => (int) $owner->user_id,
                    'actor_character_id' => $ownerId,
                    'source_type' => 'location_invite',
                    'source_id' => $inviteId,
                    'action_url' => '/game/maps/' . $locationId,
                    'priority' => 'normal',
                ],
            );
        } catch (\Throwable $e) {
            // fire-and-forget
        }
    }

    public function respondInvite(int $inviteId, int $invitedId, string $newStatus)
    {
        if ($inviteId <= 0 || $invitedId <= 0) {
            throw AppError::validation('Invito non valido', [], 'invite_invalid');
        }

        if ($newStatus !== 'accepted' && $newStatus !== 'declined' && $newStatus !== 'expired') {
            throw AppError::validation('Invito non valido', [], 'invite_invalid');
        }

        $invite = null;
        $this->begin();
        try {
            $invite = $this->firstPrepared(
                'SELECT li.*, l.map_id
                 FROM location_invites li
                 LEFT JOIN locations l ON li.location_id = l.id
                 WHERE li.id = ?
                   AND li.invited_id = ?
                   AND li.status = "pending"
                   AND (li.expires_at IS NULL OR li.expires_at > NOW())
                 LIMIT 1
                 FOR UPDATE',
                [$inviteId, $invitedId],
            );

            if (empty($invite)) {
                throw AppError::validation('Invito non trovato o scaduto', [], 'invite_not_found_or_expired');
            }

            $this->execPrepared(
                'UPDATE location_invites SET
                    status = ?,
                    owner_notified = 0,
                    date_responded = NOW()
                WHERE id = ?',
                [$newStatus, $invite->id],
            );

            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }

        // Sincronizza notifica pendente se risposta via endpoint diretto
        $decision = ($newStatus === 'accepted') ? 'accepted' : 'rejected';
        $this->resolveInviteNotification($invitedId, (int) $invite->id, $decision);

        return $invite;
    }

    private function resolveInviteNotification(int $invitedId, int $inviteId, string $decision): void
    {
        try {
            $char = $this->firstPrepared(
                'SELECT user_id FROM characters WHERE id = ? LIMIT 1',
                [$invitedId],
            );
            if (empty($char)) {
                return;
            }
            $notifService = new NotificationService($this->db);
            $notifService->resolveBySource((int) $char->user_id, 'location_invite', $inviteId, $decision);
        } catch (\Throwable $e) {
            // fire-and-forget
        }
    }

    public function getLocationForAccess(int $locationId)
    {
        if ($locationId <= 0) {
            return null;
        }

        $location = $this->firstPrepared(
            'SELECT locations.*
            FROM locations
            WHERE locations.id = ?',
            [$locationId],
        );

        if (!empty($location)) {
            $status = (int) ($location->min_socialstatus_id ?? 0) > 0
                ? SocialStatusProviderRegistry::getById((int) $location->min_socialstatus_id)
                : null;
            $location->required_status_name = $status->name ?? null;
            $location->required_status_min  = $status->min ?? null;
        }

        return $location;
    }

    public function getCharacterById(int $characterId)
    {
        if ($characterId <= 0) {
            return null;
        }

        $character = $this->firstPrepared(
            'SELECT id, fame, socialstatus_id FROM characters WHERE id = ?',
            [$characterId],
        );

        return !empty($character) ? $character : null;
    }

    public function getAcceptedInvitesSet(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT location_id FROM location_invites
            WHERE invited_id = ? AND status = "accepted"',
            [$characterId],
        );

        $set = [];
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $set[(int) $row->location_id] = true;
            }
        }

        return $set;
    }

    public function evaluateAccess($location, $character, array $invitedSet, ?array $guildAccessSet = null): array
    {
        $guestsCount = $this->getGuestsCount($location->guests ?? null);
        $maxGuests = isset($location->max_guests) ? (int) $location->max_guests : 0;
        $isFull = ($maxGuests > 0 && $guestsCount >= $maxGuests);

        $result = [
            'allowed' => false,
            'reason' => 'Accesso non consentito',
            'reason_code' => 'denied',
            'is_owner' => false,
            'is_invited' => false,
            'is_full' => $isFull,
            'guests_count' => $guestsCount,
        ];

        if (empty($location) || empty($character)) {
            return $result;
        }

        $locationId = (int) $location->id;
        $characterId = (int) $character->id;
        $isOwner = !empty($location->owner_id) && (int) $location->owner_id === $characterId;
        $isInvited = isset($invitedSet[$locationId]);
        $isHouse = ((int) ($location->is_house ?? 0) === 1);
        $isPrivate = ((int) ($location->is_private ?? 0) === 1);

        $result['is_owner'] = $isOwner;
        $result['is_invited'] = $isInvited;

        if ($isOwner) {
            $result['allowed'] = true;
            $result['reason'] = null;
            $result['reason_code'] = 'owner';
            return $result;
        }

        if ($isInvited) {
            $result['allowed'] = true;
            $result['reason'] = null;
            $result['reason_code'] = 'invited';
            return $result;
        }

        if ($isFull) {
            $result['reason'] = 'Location piena';
            $result['reason_code'] = 'full';
            return $result;
        }

        $hasGuildAccess = false;
        if (is_array($guildAccessSet)) {
            $hasGuildAccess = isset($guildAccessSet[$locationId]);
        } else {
            $hasGuildAccess = $this->hasGuildRoleAccess($characterId, $locationId);
        }

        $policy = isset($location->access_policy) ? trim((string) $location->access_policy) : '';
        if ($policy !== '') {
            if ($policy === 'house') {
                $result['reason'] = 'Casa privata';
                $result['reason_code'] = 'house';
                return $result;
            }
            if ($policy === 'private') {
                $result['reason'] = 'Invito richiesto';
                $result['reason_code'] = 'private';
                return $result;
            }
            if ($policy === 'guild' && !$hasGuildAccess) {
                $result['reason'] = 'Accesso gilda richiesto';
                $result['reason_code'] = 'guild';
                return $result;
            }
        } else {
            if ($isHouse) {
                $result['reason'] = 'Casa privata';
                $result['reason_code'] = 'house';
                return $result;
            }

            if ($isPrivate) {
                $result['reason'] = 'Invito richiesto';
                $result['reason_code'] = 'private';
                return $result;
            }

            if ($hasGuildAccess) {
                $result['allowed'] = true;
                $result['reason'] = null;
                $result['reason_code'] = 'guild';
                return $result;
            }
        }

        $meetsFame = true;
        if (isset($location->min_fame) && $location->min_fame !== '') {
            $meetsFame = ((float) $character->fame >= (float) $location->min_fame);
        }

        $meetsStatus = true;
        if (isset($location->min_socialstatus_id) && $location->min_socialstatus_id !== '') {
            $requiredStatusId = (int) $location->min_socialstatus_id;
            $meetsStatus = SocialStatusProviderRegistry::meetsRequirement(
                $characterId,
                $requiredStatusId > 0 ? $requiredStatusId : null,
            );
        }

        if (!$meetsFame) {
            $result['reason'] = 'Fama richiesta';
            $result['reason_code'] = 'fame';
            if (isset($location->min_fame)) {
                $result['reason'] .= ': ' . $location->min_fame;
            }
            return $result;
        }

        if (!$meetsStatus) {
            $result['reason'] = 'Stato sociale richiesto';
            $result['reason_code'] = 'social_status';
            if (!empty($location->required_status_name)) {
                $result['reason'] .= ': ' . $location->required_status_name;
            }
            return $result;
        }

        $result['allowed'] = true;
        $result['reason'] = null;
        $result['reason_code'] = 'ok';
        return $result;
    }

    public function getGuildAccessSet(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT DISTINCT grl.location_id
            FROM guild_role_locations grl
            INNER JOIN guild_members gm ON gm.guild_id = grl.guild_id AND gm.role_id = grl.role_id
            WHERE gm.character_id = ?',
            [$characterId],
        );

        $set = [];
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $set[(int) $row->location_id] = true;
            }
        }

        return $set;
    }

    public function getInviteConfig(): array
    {
        if ($this->inviteConfig !== null) {
            return $this->inviteConfig;
        }

        $expiryHours = 48;
        $maxActive = 10;
        $rows = $this->fetchPrepared(
            "SELECT `key`, `value` FROM sys_configs WHERE `key` IN ('location_invite_expiry_hours', 'location_invite_max_active')",
        );
        if (!empty($rows)) {
            foreach ($rows as $row) {
                if ($row->key === 'location_invite_expiry_hours') {
                    $expiryHours = (int) $row->value;
                } elseif ($row->key === 'location_invite_max_active') {
                    $maxActive = (int) $row->value;
                }
            }
        }

        if ($expiryHours < 0) {
            $expiryHours = 0;
        }
        if ($maxActive < 0) {
            $maxActive = 0;
        }

        $this->inviteConfig = [
            'expiry_hours' => $expiryHours,
            'max_active' => $maxActive,
        ];

        return $this->inviteConfig;
    }

    public function expirePendingInvites(): void
    {
        $this->execPrepared(
            "UPDATE location_invites
            SET status = 'expired',
                owner_notified = 0,
                date_responded = NOW()
            WHERE status = 'pending'
              AND expires_at IS NOT NULL
              AND expires_at <= NOW()",
        );
    }

    public function listPendingInvitesForCharacter(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT li.id,
                li.location_id,
                li.owner_id,
                li.invited_id,
                li.status,
                li.date_created,
                li.expires_at,
                l.map_id,
                l.name AS location_name,
                c.name AS owner_name,
                c.surname AS owner_surname
            FROM location_invites li
            LEFT JOIN locations l ON li.location_id = l.id
            LEFT JOIN characters c ON li.owner_id = c.id
            WHERE li.invited_id = ?
                AND li.status = "pending"
                AND (li.expires_at IS NULL OR li.expires_at > NOW())
            ORDER BY li.date_created ASC',
            [$characterId],
        );

        return !empty($rows) ? $rows : [];
    }

    public function listOwnerInviteUpdates(int $ownerId): array
    {
        if ($ownerId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT li.id,
                li.location_id,
                li.invited_id,
                li.status,
                li.date_responded,
                l.name AS location_name,
                c.name AS invited_name,
                c.surname AS invited_surname
            FROM location_invites li
            LEFT JOIN locations l ON li.location_id = l.id
            LEFT JOIN characters c ON li.invited_id = c.id
            WHERE li.owner_id = ?
                AND li.owner_notified = 0
                AND li.status IN ("accepted", "declined", "expired")
            ORDER BY li.date_responded ASC',
            [$ownerId],
        );

        return !empty($rows) ? $rows : [];
    }

    public function markOwnerInviteUpdatesNotified(array $ids): void
    {
        $normalized = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $normalized[] = $id;
            }
        }

        if (empty($normalized)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $this->execPrepared(
            'UPDATE location_invites SET owner_notified = 1 WHERE id IN (' . $placeholders . ')',
            $normalized,
        );
    }

    public function logAccess(int $characterId, int $locationId, int $allowed, ?string $reasonCode, ?string $reason = null): void
    {
        if ($characterId <= 0 || $locationId <= 0) {
            return;
        }

        $this->execPrepared(
            'INSERT INTO location_access_logs SET
                character_id = ?,
                location_id = ?,
                allowed = ?,
                reason_code = ?,
                reason = ?,
                date_created = NOW()',
            [$characterId, $locationId, (int) $allowed, $reasonCode, $reason],
        );
    }

    public function hasGuildRoleAccess(int $characterId, int $locationId): bool
    {
        if ($characterId <= 0 || $locationId <= 0) {
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT grl.id
            FROM guild_role_locations grl
            LEFT JOIN guild_members gm ON gm.guild_id = grl.guild_id AND gm.role_id = grl.role_id
            WHERE grl.location_id = ?
              AND gm.character_id = ?
            LIMIT 1',
            [$locationId, $characterId],
        );

        return !empty($row);
    }

    public function getGuestsCount($guests): int
    {
        if ($guests === null || $guests === '') {
            return 0;
        }
        if (is_numeric($guests)) {
            return (int) $guests;
        }
        $decoded = json_decode((string) $guests, true);
        if (is_array($decoded)) {
            return count($decoded);
        }
        return 0;
    }
}
