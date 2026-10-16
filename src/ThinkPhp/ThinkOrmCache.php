<?php

namespace Kylin987\Orm\RedisCache\ThinkPhp;

trait ThinkOrmCache
{
    use \Kylin987\Orm\RedisCache\ThinkOrm\Cache;

    protected static function redisCacheConfig(string $name, $default = null)
    {
        return config('orm_cache.' . $name) ?? $default;
    }

    protected static function redisCacheClient()
    {
        return \think\facade\Cache::store(static::redisCacheConfig('cache_store', 'default'))->handler();
    }
}
