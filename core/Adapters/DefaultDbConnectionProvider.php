<?php

declare(strict_types=1);

namespace Core\Adapters;

use Core\Contracts\DbConnectionProviderInterface;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class DefaultDbConnectionProvider implements DbConnectionProviderInterface
{
    public function connection(): DbAdapterInterface
    {
        return DbAdapterFactory::createFromConfig();
    }
}
