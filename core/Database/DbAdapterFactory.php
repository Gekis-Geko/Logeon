<?php

declare(strict_types=1);

namespace Core\Database;

class DbAdapterFactory
{
    public static function createFromConfig(): DbAdapterInterface
    {
        return new MysqliDbAdapter();
    }
}
