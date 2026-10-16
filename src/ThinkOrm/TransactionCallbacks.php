<?php

namespace Kylin987\Orm\RedisCache\ThinkOrm;

use LogicException;
use Throwable;

trait TransactionCallbacks
{
    private $cacheCommitCallbacks = [];

    public function cacheTransactionLevel(): int
    {
        return $this->transTimes;
    }

    public function afterCacheCommit(callable $callback, string $key = ''): void
    {
        if ($this->transTimes === 0) {
            $callback();
            return;
        }
        $level = $this->transTimes;
        if ($key === '') {
            $this->cacheCommitCallbacks[$level][] = $callback;
        } else {
            $this->cacheCommitCallbacks[$level][$key] = $callback;
        }
    }

    public function startTrans(): void
    {
        if ($this->transTimes === 0) {
            $this->cacheCommitCallbacks = [];
        }
        try {
            parent::startTrans();
        } catch (Throwable $error) {
            if ($this->transTimes === 0) {
                $this->cacheCommitCallbacks = [];
            }
            throw $error;
        }
    }

    public function commit(): void
    {
        $level = $this->transTimes;
        try {
            parent::commit();
        } catch (Throwable $error) {
            // A failed commit must never leave callbacks to run in a later request.
            $this->close();
            throw $error;
        }
        $callbacks = $this->cacheCommitCallbacks[$level] ?? [];
        unset($this->cacheCommitCallbacks[$level]);
        if ($this->transTimes > 0) {
            $target = $this->cacheCommitCallbacks[$this->transTimes] ?? [];
            $this->cacheCommitCallbacks[$this->transTimes] = array_merge($target, $callbacks);
            return;
        }
        $this->cacheCommitCallbacks = [];
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $error) {
                error_log('[orm-redis-cache] after-commit callback failed (' . get_class($error) . ')');
            }
        }
    }

    public function rollback(): void
    {
        $level = $this->transTimes;
        try {
            parent::rollback();
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
        unset($this->cacheCommitCallbacks[$level]);
        if ($this->transTimes === 0) {
            $this->cacheCommitCallbacks = [];
        }
    }

    public function close()
    {
        $this->cacheCommitCallbacks = [];
        return parent::close();
    }

    public function startTransXa(string $xid): void
    {
        throw new LogicException('Shared record cache does not support XA transactions');
    }

    public function commitXa(string $xid): void
    {
        throw new LogicException('Shared record cache does not support XA transactions');
    }

    public function rollbackXa(string $xid): void
    {
        throw new LogicException('Shared record cache does not support XA transactions');
    }
}
