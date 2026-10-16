<?php

require __DIR__ . '/bootstrap.php';

use Kylin987\Orm\RedisCache\Eloquent\Transactions;

if (($argv[1] ?? '') === 'unsupported') {
    require __DIR__ . '/Support/UnsupportedComposer.php';
}
check(!Transactions::available(), 'Unsupported or absent Eloquent is not automatically installed');
try {
    Transactions::install();
    throw new RuntimeException('Unsupported dependencies must reject explicit Eloquent use');
} catch (LogicException $expected) {
    check(strpos($expected->getMessage(), 'illuminate/database ^10.48') !== false, 'Missing dependency error names supported version');
}
require __DIR__ . '/Support/WebmanBootstrap.php';
Kylin987\Orm\RedisCache\Webman\Bootstrap::start(null);
check(trait_exists(Kylin987\Orm\RedisCache\Webman\ThinkOrmCache::class)
    && trait_exists(Kylin987\Orm\RedisCache\ThinkPhp\ThinkOrmCache::class),
    'ThinkORM traits autoload without Illuminate or ThinkORM being installed');
echo "OK: $checks optional dependency checks\n";
