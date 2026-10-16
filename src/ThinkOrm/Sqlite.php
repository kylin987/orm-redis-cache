<?php

namespace Kylin987\Orm\RedisCache\ThinkOrm;

class Sqlite extends \think\db\connector\Sqlite implements CommitAwareConnection
{
    use TransactionCallbacks;

    public function getBuilderClass(): string
    {
        return $this->getConfig('builder') ?: '\\think\\db\\builder\\Sqlite';
    }
}
