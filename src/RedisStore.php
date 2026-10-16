<?php

namespace Kylin987\Orm\RedisCache;

use RuntimeException;

final class RedisStore implements Store
{
    private $client;

    // No dynamic Redis keys inside Lua; both keys share the table's cluster slot.
    private const READ = <<<'LUA'
local revision = redis.call('GET', KEYS[1])
if not revision then
    revision = ARGV[1]
    redis.call('SET', KEYS[1], revision)
end
local data = redis.call('GET', KEYS[2])
if data and string.sub(data, 1, 32) == revision then
    return {revision, string.sub(data, 33)}
end
return {revision, false}
LUA;

    private const PUBLISH = <<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[1] then return 0 end
redis.call('SETEX', KEYS[2], ARGV[2], ARGV[1] .. ARGV[3])
return 1
LUA;

    /** Factory is resolved per operation; never retain a pooled request client. */
    public function __construct(callable $client)
    {
        $this->client = $client;
    }

    private function evaluate(string $script, array $keys, array $args)
    {
        $client = call_user_func($this->client);
        if ($client instanceof \Redis || $client instanceof \RedisCluster) {
            $result = $client->eval($script, array_merge($keys, $args), count($keys));
        } elseif ($client instanceof \Predis\ClientInterface) {
            $result = $client->eval($script, count($keys), ...array_merge($keys, $args));
        } else {
            throw new RuntimeException('Shared cache requires a phpredis or Predis client');
        }
        if ($result === false) {
            throw new RuntimeException('Redis cache script failed');
        }
        return $result;
    }

    public function read(string $revisionKey, string $dataKey, string $candidate): array
    {
        $result = $this->evaluate(self::READ, [$revisionKey, $dataKey], [$candidate]);
        if (!is_array($result) || count($result) !== 2 || !is_string($result[0]) || strlen($result[0]) !== 32) {
            throw new RuntimeException('Invalid cache revision response');
        }
        return $result;
    }

    public function publish(string $revisionKey, string $dataKey, string $revision, string $payload, int $ttl): bool
    {
        return (int) $this->evaluate(self::PUBLISH, [$revisionKey, $dataKey], [$revision, $ttl, $payload]) === 1;
    }

    public function invalidate(string $revisionKey, string $revision): void
    {
        $this->evaluate("redis.call('SET', KEYS[1], ARGV[1]); return 1", [$revisionKey], [$revision]);
    }
}
