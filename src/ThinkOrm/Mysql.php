<?php

namespace Kylin987\Orm\RedisCache\ThinkOrm;

class Mysql extends \think\db\connector\Mysql implements CommitAwareConnection
{
    use TransactionCallbacks;

    public function getBuilderClass(): string
    {
        return $this->getConfig('builder') ?: '\\think\\db\\builder\\Mysql';
    }
}
