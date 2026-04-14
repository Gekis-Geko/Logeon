<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class GuildRequirementAdminService
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

    private function normalizeNullableText($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return ($value === '') ? null : $value;
    }

    public function create(object $data): void
    {
        $this->execPrepared(
            'INSERT INTO guild_requirements SET
                guild_id = ?,
                type = ?,
                value = ?,
                label = ?',
            [(int) $data->guild_id, (string) $data->type, (string) $data->value, $this->normalizeNullableText($data->label ?? null)],
        );
        AuditLogService::writeEvent('guild_requirements.create', ['guild_id' => (int) $data->guild_id, 'type' => (string) $data->type], 'admin');
    }

    public function update(object $data): void
    {
        $this->execPrepared(
            'UPDATE guild_requirements SET
                guild_id = ?,
                type = ?,
                value = ?,
                label = ?
             WHERE id = ?',
            [(int) $data->guild_id, (string) $data->type, (string) $data->value, $this->normalizeNullableText($data->label ?? null), (int) $data->id],
        );
        AuditLogService::writeEvent('guild_requirements.update', ['id' => (int) $data->id], 'admin');
    }
}
