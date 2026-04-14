<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class GuildRoleAdminService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    private function normalizeGuildRoleFlags($data): array
    {
        $isLeader = !empty($data->is_leader) ? 1 : 0;
        $isOfficer = !empty($data->is_officer) ? 1 : 0;
        $isDefault = !empty($data->is_default) ? 1 : 0;

        return [$isLeader, $isOfficer, $isDefault];
    }

    public function create(object $data): void
    {
        [$isLeader, $isOfficer, $isDefault] = $this->normalizeGuildRoleFlags($data);
        $guildId = (int) ($data->guild_id ?? 0);
        $name = (string) ($data->name ?? '');
        $image = isset($data->image) && trim((string) $data->image) !== '' ? (string) $data->image : null;
        $monthlySalary = (float) ($data->monthly_salary ?? 0);

        $this->execPrepared(
            'INSERT INTO guild_roles
                (guild_id, name, image, monthly_salary, is_leader, is_officer, is_default)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$guildId, $name, $image, $monthlySalary, $isLeader, $isOfficer, $isDefault],
        );

        $insertedRoleId = (int) $this->db->lastInsertId();
        if ($isDefault === 1) {
            $this->execPrepared(
                'UPDATE guild_roles
                 SET is_default = 0
                 WHERE guild_id = ?
                   AND id <> ?',
                [$guildId, $insertedRoleId],
            );
        }

        if ($isLeader === 1) {
            $this->execPrepared(
                'UPDATE guild_roles
                 SET is_leader = 0
                 WHERE guild_id = ?
                   AND id <> ?',
                [$guildId, $insertedRoleId],
            );
        }
        AuditLogService::writeEvent('guild_roles.create', ['id' => $insertedRoleId, 'name' => $name], 'admin');
    }

    public function update(object $data): void
    {
        [$isLeader, $isOfficer, $isDefault] = $this->normalizeGuildRoleFlags($data);
        $id = (int) ($data->id ?? 0);
        $guildId = (int) ($data->guild_id ?? 0);
        $name = (string) ($data->name ?? '');
        $image = isset($data->image) && trim((string) $data->image) !== '' ? (string) $data->image : null;
        $monthlySalary = (float) ($data->monthly_salary ?? 0);

        $this->execPrepared(
            'UPDATE guild_roles
             SET guild_id = ?,
                 name = ?,
                 image = ?,
                 monthly_salary = ?,
                 is_leader = ?,
                 is_officer = ?,
                 is_default = ?
             WHERE id = ?',
            [$guildId, $name, $image, $monthlySalary, $isLeader, $isOfficer, $isDefault, $id],
        );

        if ($isDefault === 1) {
            $this->execPrepared(
                'UPDATE guild_roles
                 SET is_default = 0
                 WHERE guild_id = ?
                   AND id <> ?',
                [$guildId, $id],
            );
        }

        if ($isLeader === 1) {
            $this->execPrepared(
                'UPDATE guild_roles
                 SET is_leader = 0
                 WHERE guild_id = ?
                   AND id <> ?',
                [$guildId, $id],
            );
        }
        AuditLogService::writeEvent('guild_roles.update', ['id' => $id], 'admin');
    }
}
