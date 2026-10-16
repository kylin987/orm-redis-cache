# 统一包验证记录

日期：2026-10-16。Composer 名称：`kylin987/orm-redis-cache`。以下为 v1.0.0 的实现验证记录；Git 标签和 Packagist 收录状态以远程为准。

原两个未发布草稿目录的源码、测试和必要文档已迁入统一包；独立 Composer 清单已移除，空目录已清理。两个旧包撤回本次会话新增且尚未提交的桥接，工作区恢复 HEAD；未改旧标签/发布记录。两个 dt 业务项目的工作区也保持干净。

## 测试结果

| 检查 | 环境 | 结果 |
| --- | --- | --- |
| 核心协议及故障行为 | PHP 8.1 + 独立 Redis Unix socket | 24 项通过 |
| 完整集成，原依赖 | PHP 8.3.31 + SQLite | 106 项通过 |
| 完整集成，原依赖 | PHP 8.3.31 + MySQL 8.0.46 | 106 项通过 |
| 完整集成，全新安装依赖 | PHP 8.3.31 + SQLite | 106 项通过 |
| 完整集成，全新安装依赖 | PHP 8.3.31 + MySQL 8.0.46 | 106 项通过 |
| 可选依赖隔离 | PHP 8.1，无宿主 + 模拟不支持的 Illuminate 版本 | 各 3 项通过 |
| 独立 Composer 安装 | core-only / Eloquent / Webman ThinkORM / ThinkPHP | 四组安装成功，21 项隔离/自动加载检查通过 |
| 安装器 | 无框架、真实 Webman Eloquent、真实 ThinkPHP 的隔离 fixture | 非 Webman 无操作、配置复制/保留、Bootstrap 注册及启动通过 |
| 旧 Webman 包回归 | PHP 8.3.31 | 16 tests / 51 assertions 通过 |
| 旧 ThinkPHP 包回归 | PHP 8.1 | 34 项通过 |
| PHP 源码、测试及示例语法 | PHP 8.1 | 通过 |
| Composer 元数据 | 新统一包 | validate 通过；未初始化 Git 导致的根版本默认提示属预期 |

完整集成每轮为：Eloquent 29 项、Webman ThinkORM3 23 项、Webman ThinkORM4 23 项、ThinkPHP 23 项、跨进程/并发 8 项。

原依赖版本：Illuminate 10.48.28 / webman/database 2.1.6；Webman ThinkORM 使用 ORM 3.0.34 与 4.0.51；ThinkPHP 6.1.2 / ORM 3.0.8。测试只加载项目 vendor/autoload.php，不加载业务 bootstrap 或数据库/Redis 配置。

全新安装版本：Illuminate 10.49.0 / webman/database 2.1.10 / webman/redis 2.1.5；webman/think-orm 2.1.11 / ORM 4.0.51；ThinkPHP 8.1.4 / ORM 4.0.51。ORM3 一栏继续使用独立的 3.0.34 依赖环境。

## 覆盖与隔离

集成覆盖固定组合字段、JSON/Unicode 转换、命中时无 SQL、读取事件仅执行一次及 original 语义、缓存模型保存为 UPDATE、Eloquent 自增/自减、软删除/恢复、自定义作用域旁路、空 scope 原生查询、提交后清理、嵌套事务/回滚、断开重连及 Webman Fiber/连接池复用。ThinkORM 空 scope 用原生 Mysql/Sqlite 驱动验证，不依赖 cache-aware 驱动。

跨进程覆盖 Eloquent 与三种 ThinkORM 环境双向清理；旧查询在失效后不得回填。核心检查还覆盖 Redis 故障回源/告警、数据库异常不重复查询、损坏 payload、丢失版本令牌、TTL、强制刷新及空结果不缓存。

四组 Composer fixture 位于独立 `/tmp` 目录，使用 path repository 的测试版本 1.0.0；未发布到 Packagist。Composer 安装禁用了第三方插件/脚本，自己的安装器单独运行验证。

- core-only 只安装统一包，没有其他 vendor 依赖。
- Eloquent 组不安装 ThinkORM。
- 两个 ThinkORM 组不安装 illuminate/database；Webman Redis 本身可依赖 Illuminate Redis/Support，这不等于安装 Eloquent Database。
- ThinkORM 组同时安装对应旧包，新旧 Trait 的命名空间和源文件互不覆盖。

测试使用独立 Redis socket 和本次创建的临时 MySQL Docker 容器。没有连接业务数据库或业务 Redis；完成后停止这两个测试服务。

## 审查与边界

规范、实现与活跃文档均经过独立只读审查，无剩余实质问题。沿用前一轮已经修正的 created/updated/deleted 事件监听和事件执行前原始数据缓存。开发过程中的本地计划与历史记录不纳入发布仓库。

PHP 8.1 验证语法、核心、无宿主安装器/依赖检测和旧 ThinkPHP 回归；完整 ORM 集成运行于 PHP 8.3。未验证 Swoole/Swow、Redis Cluster、Predis、Illuminate 11/12、线上发布或业务迁移。

数据库提交与 Redis 失效不原子，有限 TTL 是漏失效兜底；不承诺强一致。按表失效适合读多写少场景。原生 SQL、无事件/批量写入必须在同连接事务中显式 delCache。旧协议不自动互通；同模型不可同时使用新旧 Trait。ThinkORM 的原生内部模型恢复接口升级后须重新验证。
