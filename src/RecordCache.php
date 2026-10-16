<?php

namespace Kylin987\Orm\RedisCache;

use InvalidArgumentException;
use Throwable;

final class RecordCache
{
    private $store;
    private $prefix;
    private $reporter;

    public function __construct(Store $store, string $prefix = 'ormrc:v1', callable $reporter = null)
    {
        RecordKey::tableKey($prefix, 'validate', 'validate');
        $this->store = $store;
        $this->prefix = $prefix;
        $this->reporter = $reporter;
    }

    public function remember(RecordKey $key, int $ttl, callable $loader, bool $bypass = false, bool $refresh = false): ?array
    {
        if ($ttl < 1) {
            throw new InvalidArgumentException('Cache TTL must be a positive number of seconds');
        }
        if ($bypass) {
            return $loader();
        }
        list($revisionKey, $dataKey) = $key->keys($this->prefix);
        try {
            if ($refresh) {
                $this->store->invalidate($revisionKey, bin2hex(random_bytes(16)));
            }
            list($revision, $payload) = $this->store->read($revisionKey, $dataKey, bin2hex(random_bytes(16)));
        } catch (Throwable $error) {
            $this->report('read', $error);
            return $loader();
        }
        if (!$refresh && is_string($payload)) {
            $attributes = @unserialize($payload, ['allowed_classes' => false]);
            if (is_array($attributes) && self::rawAttributes($attributes)) {
                return $attributes;
            }
        }
        // Database errors must propagate and must never trigger a second query.
        $attributes = $loader();
        if ($attributes !== null && self::rawAttributes($attributes)) {
            try {
                $this->store->publish($revisionKey, $dataKey, $revision, serialize($attributes), $ttl);
            } catch (Throwable $error) {
                $this->report('publish', $error);
            }
        }
        return $attributes;
    }

    public function invalidate(string $scope, string $table): bool
    {
        $key = RecordKey::tableKey($this->prefix, $scope, $table) . ':revision';
        try {
            $this->store->invalidate($key, bin2hex(random_bytes(16)));
            return true;
        } catch (Throwable $error) {
            $this->report('invalidate', $error);
            return false;
        }
    }

    private static function rawAttributes(array $values): bool
    {
        foreach ($values as $value) {
            if (is_object($value) || is_resource($value) || (is_array($value) && !self::rawAttributes($value))) {
                return false;
            }
        }
        return true;
    }

    private function report(string $operation, Throwable $error): void
    {
        if ($this->reporter) {
            try {
                call_user_func($this->reporter, $operation, $error);
                return;
            } catch (Throwable $ignored) {
                // Reporting must not turn a committed write into an apparent failure.
            }
        }
        error_log('[orm-redis-cache] ' . $operation . ' failed (' . get_class($error) . ')');
    }
}
