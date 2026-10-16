# dt-catering 接入清单（尚未应用）

业务端 dt-catering 使用 Eloquent10，管理端 dt-catering-console-backend 使用 ThinkORM3。先选一张读多写少的表试点，不对 BaseModel 所有子类统一开启。

1. 两端都安装统一包 kylin987/orm-redis-cache，不再分别安装核心或 Eloquent 包。审核/发布前可以在隔离 Composer 测试项目配置 path repository；不要将个人绝对路径写入业务 composer.lock。
2. 两端统一 Redis 连接、逻辑库、连接前缀、shared_prefix；约定同一个业务库 scope，例如 `catering-main`。scope 不使用本地连接别名。
3. 在管理端 `config/think-orm.php` 对试点数据库连接把 type 改为 `Kylin987\Orm\RedisCache\ThinkOrm\Mysql::class`；只配置类名，不修改数据库账户或其他连接选项。
4. 业务模型添加 `Kylin987\Orm\RedisCache\Eloquent\EloquentCache`；两端选定模型添加 `protected $redisCacheScope = 'catering-main';`。单字段默认使用模型主键，组合字段需要先确认 UNIQUE 索引。两端的共享配置都放在 config/plugin/kylin987/orm-redis-cache/config.php，不使用旧插件配置。
5. 管理端模型替换旧 Trait 为 `Kylin987\Orm\RedisCache\Webman\ThinkOrmCache`，并在 onAfterInsert/Update/Delete/Restore 调用 `self::delCache($model)`。已有处理方法需要合并清理调用，不能覆盖原来的业务逻辑。同一模型不可同时使用新旧 Trait。
6. 如果修改共同基类 DtBaseModel，调用新 delCache 前先判断当前模型 redisCacheScope 非空，避免改变其他未迁移模型的行为。管理端现有本地 ModelCache 只有旧 tag 清理，不会自动清理新协议。
7. 业务端现有 `app/common/model/base/ModelCache.php` 保留，直到确认旧 cache()/recordCache()/clearCache 的全部调用已迁移；新 Trait 没有这些名字，避免无关改动。
8. KfcProduct、KfcQzGoods 覆盖了 booted；新 Trait 不要求它们补 parent::booted。不过原有旧缓存监听仍可能被覆盖，这不能当作迁移前已经正确清理的证据。
9. 逐一审计该表批量 update/delete、原生 SQL、withoutEvents 等路径，显式调用 delCache。两个 ORM 的模型事件都不能截获所有 SQL。
10. 两端读写都接入后验证：暖缓存→另一端改值→重新读；事务回滚；组合字段改变；删除/恢复。先灰度一张表，再决定扩展。

不要给余额、库存扣减或订单交易决策加缓存；缓存读取失败会回源，但漏失效只能靠 TTL 兜底。此次包开发没有执行上述业务接入步骤。
