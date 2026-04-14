<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\HtmlSanitizer;

class GuildService
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

    public function createGuild(object $data): void
    {
        $statuteHtml = HtmlSanitizer::sanitize((string) ($data->statute_html ?? ''), ['allow_images' => true]);
        $objectivesHtml = HtmlSanitizer::sanitize((string) ($data->objectives_html ?? ''), ['allow_images' => true]);
        $purposeHtml = HtmlSanitizer::sanitize((string) ($data->purpose_html ?? ''), ['allow_images' => true]);
        $requirementsHtml = HtmlSanitizer::sanitize((string) ($data->requirements_html ?? ''), ['allow_images' => true]);

        $this->execPrepared(
            'INSERT INTO guilds (
                name, alignment_id, image, website_url,
                statute_html, objectives_html, purpose_html, requirements_html,
                is_visible, leader_character_id, date_created
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                (string) ($data->name ?? ''),
                $data->alignment_id ?? null,
                $data->image ?? null,
                $data->website_url ?? null,
                $statuteHtml !== '' ? $statuteHtml : null,
                $objectivesHtml !== '' ? $objectivesHtml : null,
                $purposeHtml !== '' ? $purposeHtml : null,
                $requirementsHtml !== '' ? $requirementsHtml : null,
                (int) ($data->is_visible ?? 0),
                $data->leader_character_id ?? null,
            ],
        );
    }

    public function updateGuild(object $data): void
    {
        $guildId = isset($data->id) ? (int) $data->id : 0;
        if ($guildId <= 0) {
            return;
        }

        $statuteHtml = HtmlSanitizer::sanitize((string) ($data->statute_html ?? ''), ['allow_images' => true]);
        $objectivesHtml = HtmlSanitizer::sanitize((string) ($data->objectives_html ?? ''), ['allow_images' => true]);
        $purposeHtml = HtmlSanitizer::sanitize((string) ($data->purpose_html ?? ''), ['allow_images' => true]);
        $requirementsHtml = HtmlSanitizer::sanitize((string) ($data->requirements_html ?? ''), ['allow_images' => true]);

        $this->execPrepared(
            'UPDATE guilds SET
                name = ?,
                alignment_id = ?,
                image = ?,
                website_url = ?,
                statute_html = ?,
                objectives_html = ?,
                purpose_html = ?,
                requirements_html = ?,
                is_visible = ?,
                leader_character_id = ?,
                date_updated = NOW()
            WHERE id = ?',
            [
                (string) ($data->name ?? ''),
                $data->alignment_id ?? null,
                $data->image ?? null,
                $data->website_url ?? null,
                $statuteHtml !== '' ? $statuteHtml : null,
                $objectivesHtml !== '' ? $objectivesHtml : null,
                $purposeHtml !== '' ? $purposeHtml : null,
                $requirementsHtml !== '' ? $requirementsHtml : null,
                (int) ($data->is_visible ?? 0),
                $data->leader_character_id ?? null,
                $guildId,
            ],
        );
    }

    public function listPublicGuildsForCharacter(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }

        $memberships = $this->fetchPrepared(
            'SELECT guild_id, role_id
             FROM guild_members
             WHERE character_id = ?',
            [$characterId],
        );

        $memberMap = [];
        $memberIds = [];
        if (!empty($memberships)) {
            foreach ($memberships as $membership) {
                $guildId = (int) $membership->guild_id;
                $memberMap[$guildId] = (int) $membership->role_id;
                $memberIds[] = $guildId;
            }
        }

        $where = ' WHERE g.is_visible = 1';
        $params = [];
        if (!empty($memberIds)) {
            $in = implode(',', array_fill(0, count($memberIds), '?'));
            $where = ' WHERE g.is_visible = 1 OR g.id IN (' . $in . ')';
            $params = $memberIds;
        }

        $guilds = $this->fetchPrepared(
            'SELECT g.id, g.name, g.image, g.is_visible,
                ga.name AS alignment_name,
                (SELECT COUNT(*) FROM guild_members gm WHERE gm.guild_id = g.id) AS members_count
            FROM guilds g
            LEFT JOIN guild_alignments ga ON g.alignment_id = ga.id
            ' . $where . '
            ORDER BY g.name ASC',
            $params,
        );

        if (empty($guilds)) {
            return [];
        }

        foreach ($guilds as $guild) {
            $guildId = (int) $guild->id;
            $guild->is_member = isset($memberMap[$guildId]) ? 1 : 0;
            $guild->role_id = $memberMap[$guildId] ?? null;
        }

        return $guilds;
    }

    /**
     * Returns id, name, icon for every guild a character belongs to.
     * Used by the character profile page.
     */
    public function getCharacterGuildsForProfile(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT g.id, g.name, g.icon, gm.is_primary
             FROM guild_members gm
             INNER JOIN guilds g ON g.id = gm.guild_id
             WHERE gm.character_id = ?
             ORDER BY gm.is_primary DESC, g.name ASC',
            [$characterId],
        );

        if (empty($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'icon' => (string) $row->icon,
                'is_primary' => (int) $row->is_primary,
            ];
        }
        return $result;
    }

    public function getGuild($guildId)
    {
        $guildId = (int) $guildId;
        if ($guildId <= 0) {
            return null;
        }

        $guild = $this->firstPrepared(
            'SELECT g.*, ga.name AS alignment_name
            FROM guilds g
            LEFT JOIN guild_alignments ga ON g.alignment_id = ga.id
            WHERE g.id = ?
            LIMIT 1',
            [$guildId],
        );

        return !empty($guild) ? $guild : null;
    }

    public function getCharacter($characterId)
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT id, fame, socialstatus_id
            FROM characters
            WHERE id = ?',
            [$characterId],
        );

        return !empty($row) ? $row : null;
    }

    public function getCharacterUserId($characterId): int
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            return 0;
        }

        $row = $this->firstPrepared(
            'SELECT user_id FROM characters WHERE id = ? LIMIT 1',
            [$characterId],
        );

        return !empty($row) ? (int) $row->user_id : 0;
    }

    public function getMembership($characterId, $guildId)
    {
        $characterId = (int) $characterId;
        $guildId = (int) $guildId;
        if ($characterId <= 0 || $guildId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT gm.id AS member_id,
                gm.guild_id,
                gm.character_id,
                gm.role_id,
                gm.is_primary,
                gm.salary_last_claim_at,
                gr.name AS role_name,
                gr.monthly_salary,
                gr.is_leader,
                gr.is_officer
            FROM guild_members gm
            LEFT JOIN guild_roles gr ON gm.role_id = gr.id
            WHERE gm.guild_id = ?
              AND gm.character_id = ?
            LIMIT 1',
            [$guildId, $characterId],
        );

        return !empty($row) ? $row : null;
    }

    public function getRequirements($guildId): array
    {
        $guildId = (int) $guildId;
        if ($guildId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT id, type, value, label FROM guild_requirements
            WHERE guild_id = ?
            ORDER BY id ASC',
            [$guildId],
        );

        return !empty($rows) ? $rows : [];
    }

    public function listRequirementSocialStatuses(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT id, name
             FROM social_status
             ORDER BY name ASC, id ASC',
        );

        return !empty($rows) ? $rows : [];
    }

    public function listRequirementJobs(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT id, name
             FROM jobs
             WHERE is_active = 1
             ORDER BY name ASC, id ASC',
        );

        return !empty($rows) ? $rows : [];
    }

    public function findRequirementById($requirementId)
    {
        $requirementId = (int) $requirementId;
        if ($requirementId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT id, guild_id, type, value, label
             FROM guild_requirements
             WHERE id = ?
             LIMIT 1',
            [$requirementId],
        );

        return !empty($row) ? $row : null;
    }

    public function createRequirement($guildId, $type, $value, $label): void
    {
        $guildId = (int) $guildId;
        $type = trim((string) $type);
        if ($guildId <= 0 || $type === '') {
            return;
        }

        $this->execPrepared(
            'INSERT INTO guild_requirements (guild_id, type, value, label)
             VALUES (?, ?, ?, ?)',
            [$guildId, $type, $value, $label],
        );
    }

    public function updateRequirement($requirementId, $type, $value, $label): void
    {
        $requirementId = (int) $requirementId;
        $type = trim((string) $type);
        if ($requirementId <= 0 || $type === '') {
            return;
        }

        $this->execPrepared(
            'UPDATE guild_requirements SET
                type = ?,
                value = ?,
                label = ?
             WHERE id = ?',
            [$type, $value, $label, $requirementId],
        );
    }

    public function deleteRequirement($requirementId): void
    {
        $requirementId = (int) $requirementId;
        if ($requirementId <= 0) {
            return;
        }

        $this->execPrepared(
            'DELETE FROM guild_requirements
             WHERE id = ?',
            [$requirementId],
        );
    }

    public function checkRequirements($requirements, $character): array
    {
        $result = [
            'allowed' => true,
            'missing' => [],
        ];

        if (empty($requirements) || empty($character)) {
            return $result;
        }

        $jobId = null;
        foreach ($requirements as $req) {
            $type = $req->type ?? '';
            $value = $req->value ?? null;

            if ($type === 'min_fame') {
                if ((float) $character->fame < (float) $value) {
                    $result['allowed'] = false;
                    $result['missing'][] = $req->label ?: ('Fama minima: ' . $value);
                }
            } elseif ($type === 'min_socialstatus_id') {
                if ((int) $character->socialstatus_id !== (int) $value) {
                    $result['allowed'] = false;
                    $result['missing'][] = $req->label ?: 'Stato sociale richiesto';
                }
            } elseif ($type === 'job_id') {
                if ($jobId === null) {
                    $jobId = $this->getActiveJobId($character->id);
                }
                if ((int) $jobId !== (int) $value) {
                    $result['allowed'] = false;
                    $result['missing'][] = $req->label ?: 'Lavoro richiesto';
                }
            } elseif ($type === 'no_job') {
                if ($jobId === null) {
                    $jobId = $this->getActiveJobId($character->id);
                }
                if (!empty($jobId)) {
                    $result['allowed'] = false;
                    $result['missing'][] = $req->label ?: 'Non devi avere un lavoro attivo';
                }
            }
        }

        return $result;
    }

    public function getActiveJobId($characterId)
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT job_id FROM character_jobs
            WHERE character_id = ?
              AND is_active = 1
            LIMIT 1',
            [$characterId],
        );

        return !empty($row) ? (int) $row->job_id : null;
    }

    public function getMemberCount($characterId): int
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            return 0;
        }

        $row = $this->firstPrepared(
            'SELECT COUNT(*) AS total
            FROM guild_members
            WHERE character_id = ?',
            [$characterId],
        );

        return !empty($row) ? (int) $row->total : 0;
    }

    public function getDefaultRole($guildId)
    {
        $guildId = (int) $guildId;
        if ($guildId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT id FROM guild_roles
            WHERE guild_id = ? AND is_default = 1
            ORDER BY id ASC
            LIMIT 1',
            [$guildId],
        );
        if (!empty($row)) {
            return (int) $row->id;
        }

        $row = $this->firstPrepared(
            'SELECT id FROM guild_roles
            WHERE guild_id = ?
              AND is_leader = 0
            ORDER BY id ASC
            LIMIT 1',
            [$guildId],
        );

        return !empty($row) ? (int) $row->id : null;
    }

    public function findApplicationByGuildAndCharacter($guildId, $characterId)
    {
        $guildId = (int) $guildId;
        $characterId = (int) $characterId;
        if ($guildId <= 0 || $characterId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT id, status
            FROM guild_applications
            WHERE guild_id = ?
              AND character_id = ?',
            [$guildId, $characterId],
        );

        return !empty($row) ? $row : null;
    }

    public function createApplication($guildId, $characterId, $message): void
    {
        $guildId = (int) $guildId;
        $characterId = (int) $characterId;
        if ($guildId <= 0 || $characterId <= 0) {
            return;
        }

        $this->execPrepared(
            'INSERT INTO guild_applications (guild_id, character_id, message, status, date_created)
             VALUES (?, ?, ?, "pending", NOW())',
            [$guildId, $characterId, $message],
        );
    }

    public function setApplicationPending($applicationId, $message): void
    {
        $applicationId = (int) $applicationId;
        if ($applicationId <= 0) {
            return;
        }

        $this->execPrepared(
            'UPDATE guild_applications SET
                message = ?,
                status = "pending",
                reviewed_by = NULL,
                reviewed_at = NULL
            WHERE id = ?',
            [$message, $applicationId],
        );
    }

    public function findApplicationById($applicationId)
    {
        $applicationId = (int) $applicationId;
        if ($applicationId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT *
            FROM guild_applications
            WHERE id = ?',
            [$applicationId],
        );

        return !empty($row) ? $row : null;
    }

    public function setApplicationReviewed($applicationId, $status, $reviewedBy): void
    {
        $applicationId = (int) $applicationId;
        $reviewedBy = (int) $reviewedBy;
        $status = (string) $status;
        if ($applicationId <= 0 || $reviewedBy <= 0 || $status === '') {
            return;
        }

        $this->execPrepared(
            'UPDATE guild_applications SET
                status = ?,
                reviewed_by = ?,
                reviewed_at = NOW()
            WHERE id = ?',
            [$status, $reviewedBy, $applicationId],
        );
    }

    public function listPendingApplications($guildId): array
    {
        $guildId = (int) $guildId;
        if ($guildId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT ga.id, ga.character_id, ga.message, ga.status, ga.date_created,
                c.name, c.surname, c.avatar
            FROM guild_applications ga
            LEFT JOIN characters c ON ga.character_id = c.id
            WHERE ga.guild_id = ?
              AND ga.status = "pending"
            ORDER BY ga.date_created ASC',
            [$guildId],
        );

        return !empty($rows) ? $rows : [];
    }

    public function getMemberRole($guildId, $characterId)
    {
        $guildId = (int) $guildId;
        $characterId = (int) $characterId;
        if ($guildId <= 0 || $characterId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT role_id
            FROM guild_members
            WHERE guild_id = ?
              AND character_id = ?
            LIMIT 1',
            [$guildId, $characterId],
        );

        return !empty($row) ? $row : null;
    }

    public function getRoleInGuild($guildId, $roleId)
    {
        $guildId = (int) $guildId;
        $roleId = (int) $roleId;
        if ($guildId <= 0 || $roleId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT id, is_leader
            FROM guild_roles
            WHERE id = ? AND guild_id = ?',
            [$roleId, $guildId],
        );

        return !empty($row) ? $row : null;
    }

    public function hasLeader($guildId): bool
    {
        $guildId = (int) $guildId;
        if ($guildId <= 0) {
            return false;
        }

        $existingLeader = $this->firstPrepared(
            'SELECT gm.id
            FROM guild_members gm
            LEFT JOIN guild_roles gr ON gm.role_id = gr.id
            WHERE gm.guild_id = ?
              AND gr.is_leader = 1
            LIMIT 1',
            [$guildId],
        );

        return !empty($existingLeader);
    }

    public function addMember($guildId, $characterId, $roleId, $isPrimary): void
    {
        $guildId = (int) $guildId;
        $characterId = (int) $characterId;
        $roleId = (int) $roleId;
        $isPrimary = (int) $isPrimary;
        if ($guildId <= 0 || $characterId <= 0 || $roleId <= 0) {
            return;
        }

        $this->execPrepared(
            'INSERT INTO guild_members (guild_id, character_id, role_id, is_primary, date_joined)
             VALUES (?, ?, ?, ?, NOW())',
            [$guildId, $characterId, $roleId, $isPrimary],
        );
    }

    public function setGuildLeader($guildId, $characterId): void
    {
        $guildId = (int) $guildId;
        $characterId = (int) $characterId;
        if ($guildId <= 0 || $characterId <= 0) {
            return;
        }

        $this->execPrepared(
            'UPDATE guilds
            SET leader_character_id = ?
            WHERE id = ?',
            [$characterId, $guildId],
        );
    }

    public function updateMemberRole($guildId, $characterId, $roleId): void
    {
        $guildId = (int) $guildId;
        $characterId = (int) $characterId;
        $roleId = (int) $roleId;
        if ($guildId <= 0 || $characterId <= 0 || $roleId <= 0) {
            return;
        }

        $this->execPrepared(
            'UPDATE guild_members SET
                role_id = ?,
                date_updated = NOW()
            WHERE guild_id = ?
              AND character_id = ?',
            [$roleId, $guildId, $characterId],
        );
    }

    public function removeMember($guildId, $characterId): void
    {
        $guildId = (int) $guildId;
        $characterId = (int) $characterId;
        if ($guildId <= 0 || $characterId <= 0) {
            return;
        }

        $this->execPrepared(
            'DELETE FROM guild_members
            WHERE guild_id = ?
              AND character_id = ?',
            [$guildId, $characterId],
        );
    }

    public function createKickRequest($guildId, $requesterId, $targetId, $reason): void
    {
        $guildId = (int) $guildId;
        $requesterId = (int) $requesterId;
        $targetId = (int) $targetId;
        if ($guildId <= 0 || $requesterId <= 0 || $targetId <= 0) {
            return;
        }

        $this->execPrepared(
            'INSERT INTO guild_kick_requests (guild_id, requester_id, target_id, reason, status, date_created)
             VALUES (?, ?, ?, ?, "pending", NOW())',
            [$guildId, $requesterId, $targetId, $reason],
        );
    }

    public function findKickRequestById($requestId)
    {
        $requestId = (int) $requestId;
        if ($requestId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT *
            FROM guild_kick_requests
            WHERE id = ?',
            [$requestId],
        );

        return !empty($row) ? $row : null;
    }

    public function setKickRequestReviewed($requestId, $status, $reviewedBy): void
    {
        $requestId = (int) $requestId;
        $reviewedBy = (int) $reviewedBy;
        $status = (string) $status;
        if ($requestId <= 0 || $reviewedBy <= 0 || $status === '') {
            return;
        }

        $this->execPrepared(
            'UPDATE guild_kick_requests SET
                status = ?,
                reviewed_by = ?,
                reviewed_at = NOW()
            WHERE id = ?',
            [$status, $reviewedBy, $requestId],
        );
    }

    public function listMembers($guildId): array
    {
        $guildId = (int) $guildId;
        if ($guildId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT gm.character_id, gm.role_id, gm.date_joined,
                gr.name AS role_name, gr.is_leader, gr.is_officer,
                c.name, c.surname, c.avatar
            FROM guild_members gm
            LEFT JOIN guild_roles gr ON gm.role_id = gr.id
            LEFT JOIN characters c ON gm.character_id = c.id
            WHERE gm.guild_id = ?
            ORDER BY gr.is_leader DESC, gr.is_officer DESC, gr.name ASC',
            [$guildId],
        );

        return !empty($rows) ? $rows : [];
    }

    public function listRoles($guildId): array
    {
        $guildId = (int) $guildId;
        if ($guildId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT id, name, is_leader, is_officer, is_default
            FROM guild_roles
            WHERE guild_id = ?
            ORDER BY is_leader DESC, is_officer DESC, name ASC',
            [$guildId],
        );

        return !empty($rows) ? $rows : [];
    }

    public function getCharacterBank($characterId)
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT bank
            FROM characters
            WHERE id = ?',
            [$characterId],
        );

        if (empty($row) || !isset($row->bank)) {
            return null;
        }

        return (int) $row->bank;
    }

    public function addCharacterBank($characterId, $amount): void
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            return;
        }

        $this->execPrepared(
            'UPDATE characters SET
                bank = bank + ?
            WHERE id = ?',
            [$amount, $characterId],
        );
    }

    public function markSalaryClaimed($memberId): void
    {
        $memberId = (int) $memberId;
        if ($memberId <= 0) {
            return;
        }

        $this->execPrepared(
            'UPDATE guild_members SET
                salary_last_claim_at = NOW()
            WHERE id = ?',
            [$memberId],
        );
    }

    public function setPrimaryMembership($characterId, $guildId): void
    {
        $characterId = (int) $characterId;
        $guildId = (int) $guildId;
        if ($characterId <= 0 || $guildId <= 0) {
            return;
        }

        $this->execPrepared(
            'UPDATE guild_members SET is_primary = 0
            WHERE character_id = ?',
            [$characterId],
        );
        $this->execPrepared(
            'UPDATE guild_members SET is_primary = 1
            WHERE guild_id = ?
              AND character_id = ?',
            [$guildId, $characterId],
        );
    }

    public function listLogs($guildId): array
    {
        $guildId = (int) $guildId;
        if ($guildId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT gl.*,
                a.name AS actor_name, a.surname AS actor_surname,
                t.name AS target_name, t.surname AS target_surname
            FROM guild_logs gl
            LEFT JOIN characters a ON gl.actor_id = a.id
            LEFT JOIN characters t ON gl.target_id = t.id
            WHERE gl.guild_id = ?
            ORDER BY gl.date_created DESC
            LIMIT 50',
            [$guildId],
        );

        return !empty($rows) ? $rows : [];
    }

    public function listAnnouncements($guildId): array
    {
        $guildId = (int) $guildId;
        if ($guildId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT ga.*,
                c.name, c.surname
            FROM guild_announcements ga
            LEFT JOIN characters c ON ga.created_by = c.id
            WHERE ga.guild_id = ?
            ORDER BY ga.is_pinned DESC, ga.date_created DESC
            LIMIT 50',
            [$guildId],
        );

        return !empty($rows) ? $rows : [];
    }

    public function createAnnouncement($guildId, $title, $bodyHtml, $isPinned, $createdBy): void
    {
        $guildId = (int) $guildId;
        $createdBy = (int) $createdBy;
        if ($guildId <= 0 || $createdBy <= 0) {
            return;
        }

        $this->execPrepared(
            'INSERT INTO guild_announcements (guild_id, title, body_html, is_pinned, created_by, date_created)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$guildId, $title, $bodyHtml, (int) $isPinned, $createdBy],
        );
    }

    public function deleteAnnouncement($guildId, $announcementId): void
    {
        $guildId = (int) $guildId;
        $announcementId = (int) $announcementId;
        if ($guildId <= 0 || $announcementId <= 0) {
            return;
        }

        $this->execPrepared(
            'DELETE FROM guild_announcements
            WHERE id = ? AND guild_id = ?',
            [$announcementId, $guildId],
        );
    }

    public function listEvents($guildId): array
    {
        $guildId = (int) $guildId;
        if ($guildId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT ge.*,
                c.name, c.surname
            FROM guild_events ge
            LEFT JOIN characters c ON ge.created_by = c.id
            WHERE ge.guild_id = ?
            ORDER BY ge.starts_at ASC',
            [$guildId],
        );

        return !empty($rows) ? $rows : [];
    }

    public function createEvent($guildId, $title, $bodyHtml, $startsAt, $endsAt, $createdBy): void
    {
        $guildId = (int) $guildId;
        $createdBy = (int) $createdBy;
        if ($guildId <= 0 || $createdBy <= 0) {
            return;
        }

        $this->execPrepared(
            'INSERT INTO guild_events (guild_id, title, body_html, starts_at, ends_at, created_by, date_created)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$guildId, $title, $bodyHtml, $startsAt, $endsAt, $createdBy],
        );
    }

    public function deleteEvent($guildId, $eventId): void
    {
        $guildId = (int) $guildId;
        $eventId = (int) $eventId;
        if ($guildId <= 0 || $eventId <= 0) {
            return;
        }

        $this->execPrepared(
            'DELETE FROM guild_events
            WHERE id = ? AND guild_id = ?',
            [$eventId, $guildId],
        );
    }

    public function canManageRole($managerRoleId, $targetRoleId): bool
    {
        $managerRoleId = (int) $managerRoleId;
        $targetRoleId = (int) $targetRoleId;
        if ($managerRoleId <= 0 || $targetRoleId <= 0) {
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT id FROM guild_role_scopes
            WHERE role_id = ?
              AND manages_role_id = ?
            LIMIT 1',
            [$managerRoleId, $targetRoleId],
        );

        return !empty($row);
    }

    public function canClaimSalary($lastClaimAt): bool
    {
        if (empty($lastClaimAt)) {
            return true;
        }

        $last = strtotime((string) $lastClaimAt);
        if (!$last) {
            return true;
        }

        $lastYm = date('Y-m', $last);
        $nowYm = date('Y-m');

        return $lastYm < $nowYm;
    }

    public function logEvent($guildId, $action, $actorId, $targetId = null, $meta = null): void
    {
        $guildId = (int) $guildId;
        $actorId = (int) $actorId;
        $action = (string) $action;
        if ($guildId <= 0 || $actorId <= 0 || $action === '') {
            return;
        }

        $metaJson = null;
        if ($meta !== null) {
            $metaJson = json_encode($meta);
        }

        $this->execPrepared(
            'INSERT INTO guild_logs (guild_id, action, actor_id, target_id, meta, date_created)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$guildId, $action, $actorId, $targetId, $metaJson],
        );
    }
}
