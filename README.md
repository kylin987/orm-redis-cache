# orm-redis-cache

一个 Composer 包，提供 Webman Eloquent、Webman ThinkORM、ThinkPHP ThinkORM 的固定单条记录 Redis 缓存，并支持两端互相失效。PHP >=8.1。

只保留 `getRedisCache()` 和 `delCache()`：支持单字段/固定组合字段，不提供任意条件、列表、关系或空结果缓存。共同协议和平台适配都在本包内，只维护一个版本。

发布版本：`v1.0.0`。安装时请按模型选择对应 Trait；框架依赖不会自动安装。

## 安装与依赖

已有平台依赖的项目只需安装本包：

```sh
composer require kylin987/orm-redis-cache:^1.0
```

本包不强制安装 ORM 或框架。根据项目选择一组依赖，不要三组一起安装：

| 平台 | 宿主依赖 | 模型 Trait |
| --- | --- | --- |
| Webman Eloquent | webman/database ^2.1、illuminate/database ^10.48、illuminate/events ^10.48、webman/redis ^2.1 | `Kylin987\Orm\RedisCache\Eloquent\EloquentCache` |
| Webman ThinkORM | webman/think-orm ^1.1 或 ^2.0、webman/redis ^2.1；ThinkORM 3/4 | `Kylin987\Orm\RedisCache\Webman\ThinkOrmCache` |
| ThinkPHP | topthink/framework ^6.0 或 ^8.0；ThinkORM 3/4；Redis 缓存驱动 | `Kylin987\Orm\RedisCache\ThinkPhp\ThinkOrmCache` |

全新环境的依赖安装示例：

```sh
# Eloquent：首版仅支持 Illuminate 10.48 及之后的 10.x
composer require webman/database:^2.1 illuminate/database:^10.48 illuminate/events:^10.48 webman/redis:^2.1

# Webman ThinkORM
composer require webman/think-orm:^2.0 webman/redis:^2.1

# ThinkPHP（已有应用不必重复安装框架）
composer require topthink/framework:^8.0
```

建议使用 phpredis。普通用户没有 Redis 时只安装本包仍可自动加载核心；实际启用缓存前必须配置平台 Redis 客户端。

## 配置

Webman 安装器自动复制 `config/plugin/kylin987/orm-redis-cache/`，不覆盖已有配置。插件配置 `config.php`：

```php
return [
    'cache_store' => 'default', // config/redis.php 中的连接名
    'cache_exptime' => 300,     // 必须 > 0，模型 cacheExpTime 可覆盖
    'shared_prefix' => 'ormrc:v1',
    'error_handler' => null,   // callable(string $operation, Throwable $error)
];
```

Webman 的启动项只在支持的 Eloquent 环境中注册事务适配，纯 ThinkORM 项目不需要 Illuminate。安装本包之前已有的项目要确认其 Composer 脚本包含 Webman 的 `support\Plugin::install`；如果禁用了安装脚本，手动复制本包 `src/config/plugin/kylin987/orm-redis-cache/` 到项目相同目录。

ThinkPHP 不执行 Webman 安装动作。复制 [examples/thinkphp/orm_cache.php](examples/thinkphp/orm_cache.php) 为项目 `config/orm_cache.php`；字段与上面相同，`cache_store` 指向 `config/cache.php` 中的 Redis store。

所有互通项目必须统一 Redis 实例、逻辑库、底层客户端连接前缀、shared_prefix、业务库 scope 和带表前缀的物理表名。不同业务数据库必须使用不同 scope，即使它们表名相同。

## Webman Eloquent

```php
class Product extends \support\Model
{
    use \Kylin987\Orm\RedisCache\Eloquent\EloquentCache;

    protected $table = 'kfc_product';
    protected $redisCacheScope = 'catering-main'; // 显式开启
    protected $cachePk = ['tenant_id', 'id'];     // 固定唯一组合
    protected $cacheExpTime = 300;
}

$product = Product::getRedisCache(['tenant_id' => 10, 'id' => 20]);
$fresh = Product::getRedisCache(['id' => 20, 'tenant_id' => 10], true);
Product::delCache(new Product());
```

单字段可省略 cachePk，默认模型主键：`Product::getRedisCache(20)`。未定义 redisCacheScope 时直接查主库，不读写 Redis。

Trait 自动监听 created/updated/deleted，覆盖模型实例 increment/decrement、软删除和恢复；子模型覆盖 booted 不会丢失这些监听。缓存存放 retrieved 之前的原始属性，命中时保留 exists/original、casts 并执行一次 retrieved。

自定义全局作用域、默认 eager load/withCount 的模型直接查库；SoftDeletes 默认作用域可缓存。Bootstrap 必须早于业务事务启动；如果连接已开事务但缺少事务管理器，会明确报错。已有自定义 resolver 和事务管理器保留。

## 两种 ThinkORM 平台

Webman 与 ThinkPHP 使用相同模型配置和事件规则，只替换 Trait：

```php
class Product extends \think\Model
{
    use \Kylin987\Orm\RedisCache\Webman\ThinkOrmCache;
    // ThinkPHP 改用：\Kylin987\Orm\RedisCache\ThinkPhp\ThinkOrmCache

    protected $name = 'kfc_product';
    protected $redisCacheScope = 'catering-main';
    protected $cachePk = ['tenant_id', 'id'];
    protected $cacheExpTime = 300;

    public static function onAfterInsert($model): void { self::delCache($model); }
    public static function onAfterUpdate($model): void { self::delCache($model); }
    public static function onAfterDelete($model): void { self::delCache($model); }
    public static function onAfterRestore($model): void { self::delCache($model); }
}

$product = Product::getRedisCache(['tenant_id' => 10, 'id' => 20]);
```

已有事件方法中合并 delCache 调用，子模型覆盖事件时也必须保留。ThinkORM 适配本身不自动注册模型事件。

共享缓存模式必须把对应连接的 type 配置为事务驱动：

```php
// Webman: config/think-orm.php；ThinkPHP: config/database.php 的对应连接
'type' => \Kylin987\Orm\RedisCache\ThinkOrm\Mysql::class,
```

其他数据库选项保持原值；SQLite 可用 `ThinkOrm\Sqlite`。缺少适配驱动时明确报错，不提前清理。未定义 redisCacheScope 的模型直接走原生查询，无需这个驱动。单字段默认模型主键，组合字段必须有数据库唯一约束。

ThinkORM 通过原生 resultToModel 恢复 JSON、AfterRead 和 original 语义；这是内部接口集成，升级 ORM 大版本必须重新验证。

## 事务、批量写入和失效

缓存只查询主库。事务内直接查库，不读写共享缓存；最外层提交成功后清理，回滚取消。一个模型变化使同 scope/物理表的全部记录缓存失效，模型类和 cachePk 方案可以不同。

固定字段值只接受整数或字符串；整数 10 和字符串 '10' 统一编码。传入字段顺序不影响键；不要混用数据库认为等值而字符串不同的值，例如 '010' 与 10。找不到返回 null 且不缓存。getRedisCache 第二个参数为 true 时先让整表共享缓存失效再查库；事务里只查库。

批量 update/delete、查询构造器、原生 SQL、withoutEvents/saveQuietly 等不保证触发模型事件，必须在相同事务连接里显式清理：

```php
\support\Db::transaction(function () {
    Product::where('tenant_id', 10)->update(['enabled' => 0]);
    Product::delCache(new Product());
});
```

ThinkORM 同样在其事务中调用 delCache。事务中 true 只表示已排队；立即清理返回 false 表示 Redis 失效失败并已记录错误。请求结束前必须完成所有事务，不得把活跃事务连接归还池。不支持 XA、原生 PDO 事务或隐式提交 DDL。

Redis 读取/写回失败会记录错误并回源，数据库异常正常抛出、不重复查询。提交和 Redis 清理不原子，进程崩溃或失效失败可能短暂保留旧数据，只能靠有限 TTL 兜底。适合读多写少的商品/配置表，余额、库存和交易决策直接查库。

## 从旧包迁移

旧 `kylin987/webman-thinkorm-redis-cache` 和 `kylin987/think-orm-redis-cache` 保留现有版本与行为；新包不 replace 它们，不占用旧 namespace。可以共存，按模型替换 Trait 和配置，**同一模型不要同时使用新旧 Trait**。

旧 `orm_*`、cache_tag/cache_record 协议不会自动互通或被新包清理。同表所有读写端完成迁移后才算互通；过渡期保留旧清理逻辑，直到旧读端全部退出。不要把旧包未变更误认为已接入新协议。

不提供实例动态切库、动态表或跨库模型的默认支持；静态方法每次新建模型，不能沿用某实例 setConnection 的状态。先选一张明确读多写少的表接入。

[dt-catering 两端接入清单](docs/dt-catering.md) 尚未应用到业务项目。[协议详情](docs/protocol.md) 与[验证记录](docs/verification.md)列出实现边界及实测环境。

## 本地检查

```sh
php tests/install.php
php tests/dependencies.php
php tests/dependencies.php unsupported
CACHE_TEST_REDIS_SOCKET=/tmp/your-isolated-redis.sock php tests/run.php
```

完整集成会删除并重建测试库中的 ca_cache_users，只能使用独立数据库；命令和依赖环境变量见 [协议文档](docs/protocol.md)。
