<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class CurrencyAdminService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    private function execPrepared(string $sql, array $params = []): bool
    {
        return $this->db->executePrepared($sql, $params);
    }

    private function syncMoneyConfig($name): void
    {
        if ($name === null || $name === '') {
            return;
        }

        $this->execPrepared(
            'INSERT INTO sys_configs
                (`key`, `value`, `type`, date_created)
             VALUES
                (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                `value` = VALUES(`value`),
                date_updated = NOW()',
            ['money_name', $name, 'text'],
        );
    }

    public function create(object $data): void
    {
        if ((int) $data->is_default === 1) {
            $this->execPrepared('UPDATE currencies SET is_default = 0');
        }

        $this->execPrepared(
            'INSERT INTO currencies
                (code, name, symbol, image, is_default, is_active)
             VALUES
                (?, ?, ?, ?, ?, ?)',
            [(string) $data->code, (string) $data->name, ($data->symbol ?? null), ($data->image ?? null), (int) $data->is_default, (int) $data->is_active],
        );

        if ((int) $data->is_default === 1) {
            $this->syncMoneyConfig($data->name);
        }
        AuditLogService::writeEvent('currencies.create', ['name' => (string) $data->name, 'code' => (string) $data->code], 'admin');
    }

    public function update(object $data): void
    {
        if ((int) $data->is_default === 1) {
            $this->execPrepared('UPDATE currencies SET is_default = 0');
        }

        $this->execPrepared(
            'UPDATE currencies SET
                code = ?,
                name = ?,
                symbol = ?,
                image = ?,
                is_default = ?,
                is_active = ?
             WHERE id = ?',
            [(string) $data->code, (string) $data->name, ($data->symbol ?? null), ($data->image ?? null), (int) $data->is_default, (int) $data->is_active, (int) $data->id],
        );

        if ((int) $data->is_default === 1) {
            $this->syncMoneyConfig($data->name);
        }
        AuditLogService::writeEvent('currencies.update', ['id' => (int) $data->id], 'admin');
    }
}
