<?php

declare(strict_types=1);

namespace Kylin987\Orm\RedisCache\Webman;

use Kylin987\Orm\RedisCache\RecordCache;
use Kylin987\Orm\RedisCache\RedisStore;
use support\Redis;

final class CacheConfig
{
    public static function get(string $name, $default = null)
    {
        return config('plugin.kylin987.orm-redis-cache.config.' . $name, $default);
    }

    public static function cache(): RecordCache
    {
        $store = (string) self::get('cache_store', 'default');
        return new RecordCache(new RedisStore(static fn () => Redis::connection($store)->client()),
            (string) self::get('shared_prefix', 'ormrc:v1'), self::get('error_handler'));
    }
}
