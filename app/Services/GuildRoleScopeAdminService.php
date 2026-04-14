<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class GuildRoleScopeAdminService
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

    public function create(object $data): void
    {
        $this->execPrepared(
            'INSERT INTO guild_role_scopes SET
                role_id = ?,
                manages_role_id = ?',
            [(int) $data->role_id, (int) $data->manages_role_id],
        );
        AuditLogService::writeEvent('guild_role_scopes.create', ['role_id' => (int) $data->role_id, 'manages_role_id' => (int) $data->manages_role_id], 'admin');
    }

    public function update(object $data): void
    {
        $this->execPrepared(
            'UPDATE guild_role_scopes SET
                role_id = ?,
                manages_role_id = ?
             WHERE id = ?',
            [(int) $data->role_id, (int) $data->manages_role_id, (int) $data->id],
        );
        AuditLogService::writeEvent('guild_role_scopes.update', ['id' => (int) $data->id], 'admin');
    }
}
