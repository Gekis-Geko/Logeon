<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;
use Core\Hooks;

class CharacterStateService
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

    private function execPrepared(string $sql, array $params = []): bool
    {
        return $this->db->executePrepared($sql, $params);
    }

    public function syncSocialStatus(int $characterId, $fame, $currentStatusId = null)
    {
        if ($characterId <= 0) {
            return null;
        }

        return SocialStatusProviderRegistry::syncForCharacter(
            $characterId,
            (float) $fame,
            $currentStatusId !== null ? (int) $currentStatusId : null,
        );
    }

    public function getLatestNameRequest(int $characterId)
    {
        if ($characterId <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT id, current_name, new_name, status, reason, date_created, date_resolved
             FROM character_name_requests
             WHERE character_id = ?
             ORDER BY date_created DESC
             LIMIT 1',
            [$characterId],
        );
    }

    public function getLatestIdentityRequest(int $characterId)
    {
        if ($characterId <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT id, new_surname, new_height, new_weight, new_eyes, new_hair, new_skin,
                    status, reason, date_created, date_resolved
             FROM character_identity_requests
             WHERE character_id = ?
             ORDER BY date_created DESC
             LIMIT 1',
            [$characterId],
        );
    }

    public function getLatestLoanfaceRequest(int $characterId)
    {
        if ($characterId <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT id, current_loanface, new_loanface, status, reason, date_created, date_resolved
             FROM character_loanface_requests
             WHERE character_id = ?
             ORDER BY date_created DESC
             LIMIT 1',
            [$characterId],
        );
    }

    public function getUserSessionsRevokedAt(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT date_sessions_revoked
             FROM users
             WHERE id = ?',
            [$userId],
        );

        if (empty($row) || !isset($row->date_sessions_revoked)) {
            return null;
        }

        return (string) $row->date_sessions_revoked;
    }

    public function setSocialStatusByAdmin(int $characterId, int $statusId, ?string $reason, int $authorUserId): array
    {
        if ($characterId <= 0 || $statusId <= 0 || $authorUserId <= 0) {
            throw AppError::validation('Dati mancanti', [], 'payload_missing');
        }

        $status = SocialStatusProviderRegistry::getById($statusId);
        if ($status === null) {
            throw AppError::validation('Stato sociale non valido', [], 'social_status_invalid');
        }

        $row = $this->firstPrepared(
            'SELECT fame
             FROM characters
             WHERE id = ?',
            [$characterId],
        );
        if (empty($row)) {
            throw AppError::notFound('Personaggio non trovato', [], 'character_not_found');
        }

        $fameBefore = (float) $row->fame;
        $fameAfter = (float) $status->min;
        $delta = $fameAfter - $fameBefore;

        $this->execPrepared(
            'UPDATE characters SET
                socialstatus_id = ?,
                fame = ?
             WHERE id = ?',
            [(int) $status->id, $fameAfter, $characterId],
        );

        $this->execPrepared(
            'INSERT INTO fame_logs
                (character_id, fame_before, fame_after, delta, reason, source, author_id, date_created)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, NOW())',
            [$characterId, $fameBefore, $fameAfter, $delta, $reason, 'admin_set_status', $authorUserId],
        );

        return [
            'character_id' => $characterId,
            'socialstatus_id' => (int) $status->id,
            'fame' => $fameAfter,
        ];
    }

    public function listSocialStatuses(): array
    {
        return SocialStatusProviderRegistry::listAll();
    }

    public function getVisibility(int $characterId): int
    {
        if ($characterId <= 0) {
            return 0;
        }

        $row = $this->firstPrepared(
            'SELECT is_visible
             FROM characters
             WHERE id = ?',
            [$characterId],
        );

        return (!empty($row) && (int) $row->is_visible === 1) ? 1 : 0;
    }

    public function setVisibility(int $characterId, int $isVisible): void
    {
        if ($characterId <= 0) {
            return;
        }

        $visible = ($isVisible === 1) ? 1 : 0;
        $this->execPrepared(
            'UPDATE characters SET
                is_visible = ?,
                date_last_seed = NOW()
             WHERE id = ?',
            [$visible, $characterId],
        );
    }

    public function requestDelete(int $characterId): ?string
    {
        if ($characterId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT delete_scheduled_at
             FROM characters
             WHERE id = ?',
            [$characterId],
        );

        if (!empty($row) && !empty($row->delete_scheduled_at)) {
            $scheduled = strtotime((string) $row->delete_scheduled_at);
            if ($scheduled && $scheduled > time()) {
                throw AppError::validation('Eliminazione gia programmata', [], 'character_delete_already_scheduled');
            }
        }

        $this->execPrepared(
            'UPDATE characters SET
                delete_requested_at = NOW(),
                delete_scheduled_at = DATE_ADD(NOW(), INTERVAL 10 DAY)
             WHERE id = ?',
            [$characterId],
        );

        $updated = $this->firstPrepared(
            'SELECT delete_scheduled_at
             FROM characters
             WHERE id = ?',
            [$characterId],
        );

        return !empty($updated) && isset($updated->delete_scheduled_at)
            ? (string) $updated->delete_scheduled_at
            : null;
    }

    public function cancelDelete(int $characterId): void
    {
        if ($characterId <= 0) {
            return;
        }

        $this->execPrepared(
            'UPDATE characters SET
                delete_requested_at = NULL,
                delete_scheduled_at = NULL
             WHERE id = ?',
            [$characterId],
        );
    }

    public function getGuildMemberships(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT gm.guild_id,
                gm.role_id,
                gm.is_primary,
                gr.name AS role_name,
                gr.is_leader,
                gr.is_officer,
                g.name AS guild_name,
                g.image AS guild_image
             FROM guild_members gm
             LEFT JOIN guild_roles gr ON gm.role_id = gr.id
             LEFT JOIN guilds g ON gm.guild_id = g.id
             WHERE gm.character_id = ?
             ORDER BY gm.is_primary DESC, g.name ASC',
            [$characterId],
        );

        return !empty($rows) ? $rows : [];
    }

    public function getDefaultCurrency()
    {
        $row = $this->firstPrepared(
            'SELECT id, code, name, symbol, image
             FROM currencies
             WHERE is_default = 1 AND is_active = 1
             LIMIT 1',
        );

        return !empty($row) ? $row : null;
    }

    public function getWallets(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT cw.currency_id, cw.balance,
                c.code, c.name, c.symbol, c.image, c.is_default
             FROM character_wallets cw
             LEFT JOIN currencies c ON cw.currency_id = c.id
             WHERE cw.character_id = ?
               AND c.is_active = 1
               AND c.is_default = 0
             ORDER BY c.name ASC',
            [$characterId],
        );

        $wallets = !empty($rows) ? $rows : [];

        if (!class_exists('\\Core\\Hooks')) {
            return $wallets;
        }

        $extraWallets = Hooks::filter('currency.extra_wallets', [], $characterId);
        if (!is_array($extraWallets) || empty($extraWallets)) {
            return $wallets;
        }

        foreach ($extraWallets as $extraWallet) {
            if (is_array($extraWallet)) {
                $extraWallet = (object) $extraWallet;
            }

            if (!is_object($extraWallet)) {
                continue;
            }

            $wallets[] = $extraWallet;
        }

        return $wallets;
    }
}
