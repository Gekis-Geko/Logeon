<?php

declare(strict_types=1);

namespace Modules\Logeon\MultiCurrency\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class AdditionalCurrencyService
{
    private DbAdapterInterface $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    private function fetchPrepared(string $sql, array $params = []): array
    {
        return $this->db->fetchAllPrepared($sql, $params);
    }

    /**
     * @return array<int,object>
     */
    public function listActiveAdditionalCurrencies(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT id, code, name, symbol, image, is_default
             FROM currencies
             WHERE is_active = 1
               AND is_default = 0
             ORDER BY name ASC, id ASC',
        );

        return !empty($rows) ? $rows : [];
    }

    /**
     * @return array<int,object>
     */
    public function listExtraWalletsForCharacter(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT cw.currency_id, cw.balance,
                c.code, c.name, c.symbol, c.image, c.is_default
             FROM character_wallets cw
             INNER JOIN currencies c ON c.id = cw.currency_id
             WHERE cw.character_id = ?
               AND c.is_active = 1
               AND c.is_default = 0
             ORDER BY c.name ASC, c.id ASC',
            [$characterId],
        );

        return !empty($rows) ? $rows : [];
    }

    /**
     * @param array<int,mixed> $available
     * @return array<int,object>
     */
    public function extendAvailableList(array $available): array
    {
        $out = [];
        $seen = [];

        foreach ($available as $row) {
            $normalized = $this->normalizeCurrencyRow($row);
            if ($normalized === null) {
                continue;
            }

            $id = (int) ($normalized->id ?? 0);
            if ($id > 0 && isset($seen[$id])) {
                continue;
            }
            if ($id > 0) {
                $seen[$id] = true;
            }

            $out[] = $normalized;
        }

        foreach ($this->listActiveAdditionalCurrencies() as $row) {
            $normalized = $this->normalizeCurrencyRow($row);
            if ($normalized === null) {
                continue;
            }

            $id = (int) ($normalized->id ?? 0);
            if ($id > 0 && isset($seen[$id])) {
                continue;
            }
            if ($id > 0) {
                $seen[$id] = true;
            }

            $out[] = $normalized;
        }

        return $out;
    }

    /**
     * @param mixed $row
     */
    private function normalizeCurrencyRow($row): ?object
    {
        if (is_array($row)) {
            $row = (object) $row;
        }

        if (!is_object($row)) {
            return null;
        }

        return (object) [
            'id' => (int) ($row->id ?? $row->currency_id ?? 0),
            'currency_id' => (int) ($row->currency_id ?? $row->id ?? 0),
            'code' => (string) ($row->code ?? ''),
            'name' => (string) ($row->name ?? ''),
            'symbol' => (string) ($row->symbol ?? ''),
            'image' => (string) ($row->image ?? ''),
            'is_default' => (int) ($row->is_default ?? 0),
            'balance' => isset($row->balance) ? (int) $row->balance : 0,
        ];
    }
}
