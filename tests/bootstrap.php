<?php

spl_autoload_register(function ($class) {
    $prefix = 'Kylin987\\Orm\\RedisCache\\';
    if (strpos($class, $prefix) === 0) {
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) require $path;
    }
}, true, true);

$checks = 0;
function check($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function testRedis() {
    $socket = getenv('CACHE_TEST_REDIS_SOCKET');
    if (!$socket) throw new RuntimeException('Set CACHE_TEST_REDIS_SOCKET to an isolated Redis Unix socket');
    $redis = new Redis();
    $redis->connect($socket);
    return $redis;
}
