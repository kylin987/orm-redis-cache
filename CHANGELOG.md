# Changelog

## 1.0.0 - 2026-10-16

- 首版统一核心、Eloquent、Webman ThinkORM 和 ThinkPHP ThinkORM 适配，PHP >=8.1。
- 固定单字段/组合字段读取，按 scope/物理表双向失效；事务内读主库，提交后失效，回滚取消。
- 原始属性缓存、原生模型恢复、Redis 故障回源、Lua 版本校验避免旧读回填。
- 平台依赖按需安装，旧两个包可共存迁移，不改变旧缓存协议。
