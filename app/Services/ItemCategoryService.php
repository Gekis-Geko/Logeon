<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class ItemCategoryService
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

    private function normalizeSortOrder($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return (int) $value;
    }

    public function create(object $data): void
    {
        $this->execPrepared(
            'INSERT INTO item_categories SET
                name = ?,
                description = ?,
                icon = ?,
                sort_order = ?',
            [
                (string) $data->name,
                $this->normalizeNullableText($data->description ?? null),
                $this->normalizeNullableText($data->icon ?? null),
                $this->normalizeSortOrder($data->sort_order ?? null),
            ],
        );
        AuditLogService::writeEvent('item_categories.create', ['name' => (string) $data->name], 'admin');
    }

    public function update(object $data): void
    {
        $this->execPrepared(
            'UPDATE item_categories SET
                name = ?,
                description = ?,
                icon = ?,
                sort_order = ?
             WHERE id = ?',
            [
                (string) $data->name,
                $this->normalizeNullableText($data->description ?? null),
                $this->normalizeNullableText($data->icon ?? null),
                $this->normalizeSortOrder($data->sort_order ?? null),
                (int) $data->id,
            ],
        );
        AuditLogService::writeEvent('item_categories.update', ['id' => (int) $data->id], 'admin');
    }
}
