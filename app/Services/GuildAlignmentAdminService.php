<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class GuildAlignmentAdminService
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
            'INSERT INTO guild_alignments SET
                name = ?,
                description = ?,
                is_active = ?',
            [(string) $data->name, $this->normalizeNullableText($data->description ?? null), (int) $data->is_active],
        );
        AuditLogService::writeEvent('guild_alignments.create', ['name' => (string) $data->name], 'admin');
    }

    public function update(object $data): void
    {
        $this->execPrepared(
            'UPDATE guild_alignments SET
                name = ?,
                description = ?,
                is_active = ?
             WHERE id = ?',
            [(string) $data->name, $this->normalizeNullableText($data->description ?? null), (int) $data->is_active, (int) $data->id],
        );
        AuditLogService::writeEvent('guild_alignments.update', ['id' => (int) $data->id], 'admin');
    }
}
