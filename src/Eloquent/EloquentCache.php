<?php

declare(strict_types=1);

namespace Kylin987\Orm\RedisCache\Eloquent;

use Illuminate\Database\Eloquent\SoftDeletingScope;
use Kylin987\Orm\RedisCache\RecordKey;
use Kylin987\Orm\RedisCache\Webman\CacheConfig;

trait EloquentCache
{
    protected static function bootEloquentCache(): void
    {
        Transactions::assertSupported();
        static::created(static fn ($model) => self::delCache($model));
        static::updated(static fn ($model) => self::delCache($model));
        static::deleted(static fn ($model) => self::delCache($model));
    }

    public function getConnection()
    {
        $connection = parent::getConnection();
        if (!empty($this->redisCacheScope)) {
            Transactions::prepare($connection);
        }
        return $connection;
    }

    public static function getRedisCache($id, $getDb = false)
    {
        $model = new static();
        $scope = (string) ($model->redisCacheScope ?? '');
        $fields = $model->cachePk ?? $model->getKeyName();
        $values = RecordKey::normalize($fields, $id);
        $connection = $model->getConnection();
        $query = $model->newQuery()->useWritePdo()->where($values);
        $scoped = false;
        foreach ($model->getGlobalScopes() as $globalScope) {
            if (!$globalScope instanceof SoftDeletingScope) {
                $scoped = true;
                break;
            }
        }
        if ($scope === '' || $scoped || $connection->transactionLevel() > 0 || $model->with || $model->withCount) {
            return $query->first();
        }
        $table = $connection->getTablePrefix() . $model->getTable();
        $key = new RecordKey($scope, $table, 'eloquent:' . static::class, $fields, $id);
        $attributes = CacheConfig::cache()->remember($key,
            (int) ($model->cacheExpTime ?? CacheConfig::get('cache_exptime', 300)), static function () use ($query) {
                $raw = $query->toBase()->first();
                return $raw === null ? null : (array) $raw;
            }, false, (bool) $getDb);
        return $attributes === null ? null : $model->newFromBuilder($attributes, $model->getConnectionName());
    }

    /** Invalidates this physical table, including caches populated by ThinkORM. */
    public static function delCache($model): bool
    {
        $scope = (string) ($model->redisCacheScope ?? '');
        if ($scope === '') {
            return false;
        }
        $connection = $model->getConnection();
        $table = $connection->getTablePrefix() . $model->getTable();
        $cache = CacheConfig::cache();
        $result = true;
        Transactions::afterCommit($connection, static function () use ($cache, $scope, $table, &$result) {
            $result = $cache->invalidate($scope, $table);
        });
        return $result;
    }
}
