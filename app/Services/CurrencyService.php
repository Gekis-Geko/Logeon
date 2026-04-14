<?php

declare(strict_types=1);

namespace App\Services;

use Core\CurrencyLogs;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class CurrencyService
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

    private function begin(): void
    {
        $this->db->query('START TRANSACTION');
    }

    private function commit(): void
    {
        $this->db->query('COMMIT');
    }

    private function rollback(): void
    {
        try {
            $this->db->query('ROLLBACK');
        } catch (\Throwable $e) {
            // no-op: rollback best effort
        }
    }

    public function getDefaultCurrency()
    {
        return $this->firstPrepared(
            'SELECT id, code, name, symbol, image, is_default
             FROM currencies
             WHERE is_default = 1 AND is_active = 1
            LIMIT 1',
        );
    }

    public function getActiveCurrencies()
    {
        return $this->fetchPrepared(
            'SELECT id, code, name, symbol, image, is_default
             FROM currencies
             WHERE is_active = 1
            ORDER BY is_default DESC, name ASC',
        );
    }

    public function getCharacterBalances($characterId)
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            return [];
        }

        $balances = [];
        $defaultCurrency = $this->getDefaultCurrency();
        if (!empty($defaultCurrency)) {
            $money = $this->firstPrepared(
                'SELECT money FROM characters WHERE id = ?',
                [$characterId],
            );
            $balances[(int) $defaultCurrency->id] = [
                'currency' => $defaultCurrency,
                'account' => 'money',
                'balance' => (int) ($money->money ?? 0),
            ];
        }

        $wallets = $this->fetchPrepared(
            'SELECT w.currency_id, w.balance, c.code, c.name, c.symbol, c.image, c.is_default
             FROM character_wallets w
             INNER JOIN currencies c ON c.id = w.currency_id
             WHERE w.character_id = ?
               AND c.is_active = 1',
            [$characterId],
        );

        if (!empty($wallets)) {
            foreach ($wallets as $wallet) {
                $balances[(int) $wallet->currency_id] = [
                    'currency' => (object) [
                        'id' => (int) $wallet->currency_id,
                        'code' => $wallet->code,
                        'name' => $wallet->name,
                        'symbol' => $wallet->symbol,
                        'image' => $wallet->image,
                        'is_default' => (int) $wallet->is_default,
                    ],
                    'account' => ((int) $wallet->is_default === 1) ? 'money' : 'wallet',
                    'balance' => (int) $wallet->balance,
                ];
            }
        }

        return $balances;
    }

    public function getBalance($characterId, $currencyId)
    {
        $characterId = (int) $characterId;
        $currencyId = (int) $currencyId;
        if ($characterId <= 0 || $currencyId <= 0) {
            return 0;
        }

        $currency = $this->firstPrepared(
            'SELECT id, is_default FROM currencies WHERE id = ? LIMIT 1',
            [$currencyId],
        );
        if (empty($currency)) {
            return 0;
        }

        if ((int) $currency->is_default === 1) {
            $row = $this->firstPrepared(
                'SELECT money FROM characters WHERE id = ? LIMIT 1',
                [$characterId],
            );
            return (int) ($row->money ?? 0);
        }

        $row = $this->firstPrepared(
            'SELECT balance
             FROM character_wallets
             WHERE character_id = ?
               AND currency_id = ?
             LIMIT 1',
            [$characterId, $currencyId],
        );

        return (int) ($row->balance ?? 0);
    }

    public function debit($characterId, $currencyId, $amount, $source = 'service_debit', $meta = null)
    {
        $characterId = (int) $characterId;
        $currencyId = (int) $currencyId;
        $amount = (int) $amount;
        if ($characterId <= 0 || $currencyId <= 0 || $amount <= 0) {
            return ['ok' => false, 'error' => 'invalid_input'];
        }

        $currency = $this->firstPrepared(
            'SELECT id, is_default FROM currencies WHERE id = ? AND is_active = 1 LIMIT 1',
            [$currencyId],
        );
        if (empty($currency)) {
            return ['ok' => false, 'error' => 'currency_not_found'];
        }

        $this->begin();
        try {
            if ((int) $currency->is_default === 1) {
                $row = $this->firstPrepared(
                    'SELECT money FROM characters
                     WHERE id = ?
                     LIMIT 1
                     FOR UPDATE',
                    [$characterId],
                );

                if (empty($row)) {
                    $this->rollback();
                    return ['ok' => false, 'error' => 'character_not_found'];
                }

                $before = (int) $row->money;
                if ($before < $amount) {
                    $this->rollback();
                    return ['ok' => false, 'error' => 'insufficient_funds', 'balance' => $before];
                }

                $this->execPrepared(
                    'UPDATE characters
                     SET money = money - ?
                     WHERE id = ?
                       AND money >= ?',
                    [$amount, $characterId, $amount],
                );

                $after = $before - $amount;
                $account = 'money';
            } else {
                $row = $this->firstPrepared(
                    'SELECT balance
                     FROM character_wallets
                     WHERE character_id = ?
                       AND currency_id = ?
                     LIMIT 1
                     FOR UPDATE',
                    [$characterId, $currencyId],
                );

                if (empty($row)) {
                    $this->rollback();
                    return ['ok' => false, 'error' => 'insufficient_funds', 'balance' => 0];
                }

                $before = (int) $row->balance;
                if ($before < $amount) {
                    $this->rollback();
                    return ['ok' => false, 'error' => 'insufficient_funds', 'balance' => $before];
                }

                $this->execPrepared(
                    'UPDATE character_wallets
                     SET balance = balance - ?
                     WHERE character_id = ?
                       AND currency_id = ?
                       AND balance >= ?',
                    [$amount, $characterId, $currencyId, $amount],
                );

                $after = $before - $amount;
                $account = 'wallet';
            }

            CurrencyLogs::write($characterId, $currencyId, $account, -$amount, $before, $after, $source, $meta);
            $this->commit();
            return ['ok' => true, 'balance_before' => $before, 'balance_after' => $after];
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function credit($characterId, $currencyId, $amount, $source = 'service_credit', $meta = null)
    {
        $characterId = (int) $characterId;
        $currencyId = (int) $currencyId;
        $amount = (int) $amount;
        if ($characterId <= 0 || $currencyId <= 0 || $amount <= 0) {
            return ['ok' => false, 'error' => 'invalid_input'];
        }

        $currency = $this->firstPrepared(
            'SELECT id, is_default FROM currencies WHERE id = ? AND is_active = 1 LIMIT 1',
            [$currencyId],
        );
        if (empty($currency)) {
            return ['ok' => false, 'error' => 'currency_not_found'];
        }

        $this->begin();
        try {
            if ((int) $currency->is_default === 1) {
                $row = $this->firstPrepared(
                    'SELECT money FROM characters
                     WHERE id = ?
                     LIMIT 1
                     FOR UPDATE',
                    [$characterId],
                );

                if (empty($row)) {
                    $this->rollback();
                    return ['ok' => false, 'error' => 'character_not_found'];
                }

                $before = (int) $row->money;
                $this->execPrepared(
                    'UPDATE characters
                     SET money = money + ?
                     WHERE id = ?',
                    [$amount, $characterId],
                );
                $after = $before + $amount;
                $account = 'money';
            } else {
                $this->execPrepared(
                    'INSERT INTO character_wallets (character_id, currency_id, balance)
                     VALUES (?, ?, 0)
                     ON DUPLICATE KEY UPDATE balance = balance',
                    [$characterId, $currencyId],
                );

                $row = $this->firstPrepared(
                    'SELECT balance
                     FROM character_wallets
                     WHERE character_id = ?
                       AND currency_id = ?
                     LIMIT 1
                     FOR UPDATE',
                    [$characterId, $currencyId],
                );

                $before = (int) ($row->balance ?? 0);
                $this->execPrepared(
                    'UPDATE character_wallets
                     SET balance = balance + ?
                     WHERE character_id = ?
                       AND currency_id = ?',
                    [$amount, $characterId, $currencyId],
                );
                $after = $before + $amount;
                $account = 'wallet';
            }

            CurrencyLogs::write($characterId, $currencyId, $account, $amount, $before, $after, $source, $meta);
            $this->commit();
            return ['ok' => true, 'balance_before' => $before, 'balance_after' => $after];
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }
}
