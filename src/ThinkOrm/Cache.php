<?php

namespace Kylin987\Orm\RedisCache\ThinkOrm;

use Kylin987\Orm\RedisCache\RecordCache;
use Kylin987\Orm\RedisCache\RecordKey;
use Kylin987\Orm\RedisCache\RedisStore;

/** Shared model behavior; platform traits supply config and a current Redis client. */
trait Cache
{
    abstract protected static function redisCacheConfig(string $name, $default = null);
    abstract protected static function redisCacheClient();

    public static function getRedisCache($id, $getDb = false)
    {
        $model = static::getModel();
        $scope = (string) ($model->redisCacheScope ?? '');
        $fields = $model->cachePk ?? $model->getPk();
        if ($scope === '') {
            return $model->db()->where(RecordKey::normalize($fields, $id))->master()->cache(false)->find();
        }
        return ModelCache::get($model, $id, (bool) $getDb, static::redisRecordCache(), $scope, $fields,
            (int) ($model->cacheExpTime ?? static::redisCacheConfig('cache_exptime', 300)),
            (bool) (method_exists($model, 'getOption') ? $model->getOption('globalScope', []) : ($model->globalScope ?? [])));
    }

    public static function delCache($model): bool
    {
        $scope = (string) ($model->redisCacheScope ?? '');
        return $scope === '' ? false : ModelCache::invalidate($model, static::redisRecordCache(), $scope);
    }

    private static function redisRecordCache(): RecordCache
    {
        return new RecordCache(new RedisStore(static function () { return static::redisCacheClient(); }),
            (string) static::redisCacheConfig('shared_prefix', 'ormrc:v1'), static::redisCacheConfig('error_handler'));
    }
}
