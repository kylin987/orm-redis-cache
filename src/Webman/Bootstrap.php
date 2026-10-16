<?php

declare(strict_types=1);

namespace Kylin987\Orm\RedisCache\Webman;

use Webman\Bootstrap as WebmanBootstrap;
use Kylin987\Orm\RedisCache\Eloquent\Transactions;

final class Bootstrap implements WebmanBootstrap
{
    public static function start($worker): void
    {
        if (Transactions::available()) {
            Transactions::install();
        }
    }
}
