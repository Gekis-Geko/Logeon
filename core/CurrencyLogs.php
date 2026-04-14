<?php

declare(strict_types=1);

namespace Core;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class CurrencyLogs
{
    /** @var DbAdapterInterface|null */
    private static $dbAdapter = null;
    private static $defaultCurrencyId = null;

    public static function setDbAdapter(DbAdapterInterface $adapter = null): void
    {
        static::$dbAdapter = $adapter;
    }

    private static function db(): DbAdapterInterface
    {
        if (static::$dbAdapter instanceof DbAdapterInterface) {
            return static::$dbAdapter;
        }

        static::$dbAdapter = DbAdapterFactory::createFromConfig();
        return static::$dbAdapter;
    }

    public static function getDefaultCurrencyId()
    {
        if (self::$defaultCurrencyId !== null) {
            return self::$defaultCurrencyId;
        }

        $row = static::db()->fetchOnePrepared(
            'SELECT id
             FROM currencies
             WHERE is_default = 1
               AND is_active = 1
             LIMIT 1',
        );

        self::$defaultCurrencyId = (!empty($row) && isset($row->id)) ? (int) $row->id : null;

        return self::$defaultCurrencyId;
    }

    public static function write($character_id, $currency_id, $account, $amount, $balance_before = null, $balance_after = null, $source = null, $meta = null)
    {
        if (empty($character_id) || empty($currency_id) || empty($account) || $source === null) {
            return;
        }

        $metaJson = null;
        if ($meta !== null) {
            $metaJson = json_encode($meta);
        }

        static::db()->executePrepared(
            'INSERT INTO currency_logs (
                character_id,
                currency_id,
                account,
                amount,
                balance_before,
                balance_after,
                source,
                meta,
                date_created
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                (int) $character_id,
                (int) $currency_id,
                (string) $account,
                (float) $amount,
                $balance_before !== null ? (float) $balance_before : null,
                $balance_after !== null ? (float) $balance_after : null,
                (string) $source,
                $metaJson,
            ],
        );
    }
}
