<?php

namespace Kylin987\Orm\RedisCache\Webman;

trait ThinkOrmCache
{
    use \Kylin987\Orm\RedisCache\ThinkOrm\Cache;

    protected static function redisCacheConfig(string $name, $default = null)
    {
        return CacheConfig::get($name, $default);
    }

    protected static function redisCacheClient()
    {
        return \support\Redis::connection((string) CacheConfig::get('cache_store', 'default'))->client();
    }
}
