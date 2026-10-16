<?php

namespace Kylin987\Orm\RedisCache\ThinkOrm;

interface CommitAwareConnection
{
    public function cacheTransactionLevel(): int;

    public function afterCacheCommit(callable $callback, string $key = ''): void;
}
