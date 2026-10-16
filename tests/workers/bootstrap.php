<?php
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/Support/Redis.php';

$testConfig = [];
function config($key = null, $default = null) {
    global $testConfig;
    $value = $testConfig;
    if ($key === null) return $value;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part,$value)) return $default;
        $value = $value[$part];
    }
    return $value;
}
$flavor = $argv[1];
$autoload = getenv($flavor === 'eloquent' ? 'ELOQUENT_TEST_AUTOLOAD' : ($flavor === 'thinkphp' ? 'THINKPHP_TEST_AUTOLOAD' : ($flavor === 'think4' ? 'THINK4_TEST_AUTOLOAD' : 'THINK_TEST_AUTOLOAD')));
if (!$autoload || !is_file($autoload)) throw new RuntimeException('Set the relevant *_TEST_AUTOLOAD to an isolated dependency runtime');
require $autoload;
$backend = getenv('CACHE_TEST_BACKEND') ?: 'sqlite';
$sqlite = getenv('CACHE_TEST_SQLITE');
$port = (int) getenv('CACHE_TEST_MYSQL_PORT');
$dbConfig = $backend === 'mysql'
    ? ['driver'=>'mysql','type'=>\Kylin987\Orm\RedisCache\ThinkOrm\Mysql::class,'host'=>'127.0.0.1','hostname'=>'127.0.0.1','port'=>$port,'hostport'=>$port,'database'=>'orm_cache_test','username'=>'root','password'=>'','charset'=>'utf8mb4','prefix'=>'ca_','fields_cache'=>false]
    : ['driver'=>'sqlite','type'=>\Kylin987\Orm\RedisCache\ThinkOrm\Sqlite::class,'database'=>$sqlite,'prefix'=>'ca_','fields_cache'=>false];
$dbConfig['debug'] = true;
$testConfig = [
    'orm_cache'=>['cache_store'=>'default','shared_prefix'=>'integration:v1'],
    'plugin'=>['kylin987'=>['orm-redis-cache'=>['config'=>['cache_store'=>'default','shared_prefix'=>'integration:v1']]]],
];
function finishWorker($extra = []) {
    global $checks;
    echo json_encode(['checks'=>$checks]+$extra,JSON_THROW_ON_ERROR),"\n";
}
