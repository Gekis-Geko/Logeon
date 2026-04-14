<?php

declare(strict_types=1);

namespace Core\Contracts;

use Core\Database\DbAdapterInterface;

interface DbConnectionProviderInterface
{
    public function connection(): DbAdapterInterface;
}
