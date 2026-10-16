<?php

namespace Kylin987\Orm\RedisCache;

interface Store
{
    /** @return array [revision token, payload or false] */
    public function read(string $revisionKey, string $dataKey, string $candidate): array;

    public function publish(string $revisionKey, string $dataKey, string $revision, string $payload, int $ttl): bool;

    public function invalidate(string $revisionKey, string $revision): void;
}
