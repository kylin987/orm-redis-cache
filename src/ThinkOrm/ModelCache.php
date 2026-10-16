<?php

namespace Kylin987\Orm\RedisCache\ThinkOrm;

use Kylin987\Orm\RedisCache\RecordCache;
use Kylin987\Orm\RedisCache\RecordKey;
use LogicException;

/** Framework adapters supply config/client; all ThinkORM semantics live here. */
final class ModelCache
{
    private static function connection($model): CommitAwareConnection
    {
        $connection = $model->db()->getConnection();
        if (!$connection instanceof CommitAwareConnection) {
            throw new LogicException('redisCacheScope requires the cache-aware Mysql/Sqlite connection driver');
        }
        return $connection;
    }

    public static function get($model, $values, bool $refresh, RecordCache $cache, string $scope, $fields, int $ttl, bool $scoped = false)
    {
        $connection = self::connection($model);
        $query = $model->db()->where(RecordKey::normalize($fields, $values))->master()->cache(false);
        if ($scoped || $connection->cacheTransactionLevel() > 0) {
            return $query->find();
        }
        $key = new RecordKey($scope, $query->getTable(), 'thinkorm:' . get_class($model), $fields, $values);
        $attributes = $cache->remember($key, $ttl, function () use ($connection, $query) {
            // Raw PDO result, before AfterRead, JSON conversions or model filters.
            $raw = $connection->find($query);
            return $raw ?: null;
        }, false, $refresh);
        if ($attributes === null) {
            return null;
        }
        $query->parseOptions();
        // Reuse the native ORM3/4 pipeline for JSON, casts, AfterRead and original
        // state. newInstance alone omits parts of that pipeline in both versions.
        $hydrate = function (array $raw) {
            $this->resultToModel($raw);
            return $raw;
        };
        return $hydrate->call($query, $attributes);
    }

    /** Returns true when queued; an immediate Redis failure returns false. */
    public static function invalidate($model, RecordCache $cache, string $scope): bool
    {
        $connection = self::connection($model);
        $table = $model->db()->getTable();
        $result = true;
        $connection->afterCacheCommit(function () use ($cache, $scope, $table, &$result) {
            $result = $cache->invalidate($scope, $table);
        }, hash('sha256', serialize([$scope, $table])));
        return $result;
    }
}
