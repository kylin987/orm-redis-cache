# 缓存协议与一致性

统一包内部的共同协议，供 Eloquent、Webman ThinkORM、ThinkPHP 适配器使用。核心类不依赖 ORM，不提供任意条件/列表缓存。

## 协议

- 同一 `scope + 带前缀物理表名` 共用一个随机版本令牌；不同业务数据库必须使用不同 scope。
- 原始数据库属性按 ORM、模型类和固定字段值隔离。双方不共享序列化模型对象。
- 命中一次 Lua；未命中回源主库，另一次 Lua 只有在令牌未变化时才写入。失效只更换令牌，不扫描键。
- 版本键不设 TTL，每个已使用的逻辑表一个；记录键有有限 TTL，旧数据自然过期。
- 一条记录变化会使该表全部缓存失效。适合读多写少的配置/商品表，高频交易和金额判断直接查库。
- 强制刷新也按表更换令牌；事务内的强制刷新只查库，不提前失效、不写缓存。

```php
$store = new \Kylin987\Orm\RedisCache\RedisStore(function () {
    // Return the CURRENT request/coroutine's raw Redis client.
    return getCurrentRedisClient();
});
$cache = new \Kylin987\Orm\RedisCache\RecordCache($store, 'ormrc:v1');
$key = new \Kylin987\Orm\RedisCache\RecordKey(
    'catering-main', 'ca_kfc_product', 'eloquent:Product',
    ['tenant_id', 'id'], ['tenant_id' => 10, 'id' => 20]
);
$attributes = $cache->remember($key, 300, $loadRawRowFromPrimary);
$cache->invalidate('catering-main', 'ca_kfc_product');
```

字段必须是固定列名；值只接受整数/字符串；组合查询需要数据库唯一索引。整数和对应字符串统一编码，字段顺序以配置为准。

## ThinkORM 事务驱动

互通模式必须显式修改数据库连接配置，不修改 vendor：

```php
// config/think-orm.php (Webman) 或 config/database.php (ThinkPHP)
'type' => \Kylin987\Orm\RedisCache\ThinkOrm\Mysql::class,
```

SQLite 测试可用 `ThinkOrm\Sqlite`。驱动保持原来的数据库连接配置，自动选择原生 SQL builder；自定义 builder 参数仍优先。

驱动提供 `cacheTransactionLevel()` 与 `afterCacheCommit($callback, $deduplicationKey = '')`。内层提交合并，回滚丢弃，最外层提交成功后执行；close 清空回调。只支持 ORM 管理的常规事务，XA 明确拒绝；不要直接用底层 PDO 开事务或在事务中执行隐式提交 DDL。

ThinkORM 适配使用原生 `resultToModel` 结果处理流程恢复缓存，确保 JSON、AfterRead、original 状态与直接查询一致。此内部集成已针对 ORM 3.0.8/3.0.34 与 4.0.51 验证，升级 ORM 大版本必须重新验证。

## 故障与一致性

Redis 读取/写回失败会记录错误并返回数据库结果；数据库异常照常抛出，不重查。失效失败返回 false，并记录错误，不把已提交的写入伪装成失败。

可传入错误回调 `function (string $operation, \Throwable $error): void`。默认日志只包含操作和异常类型；生产项目应配置自己的告警记录器。

数据库提交与 Redis 失效不原子。进程提交后崩溃或 Redis 故障可能漏失效，只能由 TTL 兜底；与写入重叠的读取也可能给当前请求返回旧数据。此库不提供强一致或持久化补偿队列。

请求/任务结束前必须提交或回滚全部事务；不要把活跃事务连接归还连接池。连接池清理不能代替业务 finally 回滚。

## 验证

统一包要求 PHP >=8.1。当前集成实际验证 PHP 8.3、Illuminate 10.48、ORM3/4、Webman Fiber、单机 Redis、SQLite/MySQL8；未承诺其他 Illuminate 大版本、Swoole/Swow 或 Redis Cluster 的运行验证。

核心回归需要明确提供独立 Redis socket：

```sh
CACHE_TEST_REDIS_SOCKET=/tmp/your-isolated-redis.sock php tests/run.php
```

完整集成会删除并重建测试库中的 `ca_cache_users`。只能使用独立数据库。需要四套互相隔离的 Composer autoload 文件，测试只加载依赖，不加载宿主业务 bootstrap：

```sh
export CACHE_TEST_REDIS_SOCKET=/tmp/your-isolated-redis.sock
export CACHE_TEST_SQLITE=/tmp/your-disposable.sqlite
export ELOQUENT_TEST_AUTOLOAD=/path/to/eloquent10/vendor/autoload.php
export THINK_TEST_AUTOLOAD=/path/to/webman-thinkorm3/vendor/autoload.php
export THINK4_TEST_AUTOLOAD=/path/to/webman-thinkorm4/vendor/autoload.php
export THINKPHP_TEST_AUTOLOAD=/path/to/thinkphp6/vendor/autoload.php
php tests/integration.php

# 独立 MySQL8：数据库名固定为 orm_cache_test，用户 root，空密码，仅用于临时测试服务。
CACHE_TEST_BACKEND=mysql CACHE_TEST_MYSQL_PORT=33060 php tests/integration.php
```

测试代码预加载专用配置和 Redis 工厂，确保不会读取宿主业务连接信息。
