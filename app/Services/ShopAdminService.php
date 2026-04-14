<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class ShopAdminService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    public function create(object $data): void
    {
        $name = trim((string) ($data->name ?? ''));
        $type = trim((string) ($data->type ?? ''));
        $locationId = isset($data->location_id) && $data->location_id !== '' ? (int) $data->location_id : null;
        $isActive = (int) ($data->is_active ?? 0) > 0 ? 1 : 0;

        $this->db->executePrepared(
            'INSERT INTO shops (name, type, location_id, is_active)
             VALUES (?, ?, ?, ?)',
            [$name, $type, $locationId, $isActive],
        );
        AuditLogService::writeEvent('shops.create', ['name' => $name], 'admin');
    }

    public function update(object $data): void
    {
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            return;
        }

        $name = trim((string) ($data->name ?? ''));
        $type = trim((string) ($data->type ?? ''));
        $locationId = isset($data->location_id) && $data->location_id !== '' ? (int) $data->location_id : null;
        $isActive = (int) ($data->is_active ?? 0) > 0 ? 1 : 0;

        $this->db->executePrepared(
            'UPDATE shops
             SET name = ?,
                 type = ?,
                 location_id = ?,
                 is_active = ?
             WHERE id = ?',
            [$name, $type, $locationId, $isActive, $id],
        );
        AuditLogService::writeEvent('shops.update', ['id' => $id], 'admin');
    }
}
