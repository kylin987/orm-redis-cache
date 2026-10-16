<?php
require __DIR__ . '/bootstrap.php';

use Kylin987\Orm\RedisCache\RecordCache;
use Kylin987\Orm\RedisCache\RecordKey;
use Kylin987\Orm\RedisCache\RedisStore;

$backend=getenv('CACHE_TEST_BACKEND') ?: 'sqlite';
if ($backend==='mysql') {
    $port=(int)getenv('CACHE_TEST_MYSQL_PORT');
    if (!$port) throw new RuntimeException('Set CACHE_TEST_MYSQL_PORT to an isolated MySQL 8 server');
    $pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname=orm_cache_test','root','');
} else {
    $path=getenv('CACHE_TEST_SQLITE');
    if (!$path) throw new RuntimeException('Set CACHE_TEST_SQLITE to a disposable database');
    $pdo=new PDO('sqlite:'.$path);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('DROP TABLE IF EXISTS ca_cache_users');
$pdo->exec('CREATE TABLE ca_cache_users (id INTEGER PRIMARY KEY,tenant_id VARCHAR(50) NOT NULL,user_id VARCHAR(50) NOT NULL,name VARCHAR(100),counter INTEGER NOT NULL DEFAULT 0,meta TEXT,deleted_at VARCHAR(50),UNIQUE(tenant_id,user_id))');
$insert=$pdo->prepare('INSERT INTO ca_cache_users(id,tenant_id,user_id,name,meta) VALUES(1,?,?,?,?)');
$insert->execute(['10','20','ready',json_encode(['label'=>'中文','nested'=>['enabled'=>true]],JSON_UNESCAPED_UNICODE)]);
function worker($flavor,$mode,...$args) {
    $file=$flavor==='eloquent'?'eloquent':'think';
    $process=proc_open([PHP_BINARY,__DIR__.'/workers/'.$file.'.php',$flavor,$mode,...$args],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process)!==0) throw new RuntimeException($flavor.': '.$out.$error);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
$results=[];
foreach (['eloquent','think','think4','thinkphp'] as $flavor) $results[$flavor]=worker($flavor,'tests');
worker('eloquent','read');
foreach (['think','think4','thinkphp'] as $flavor) {
    worker($flavor,'read');
    worker('eloquent','write','eloquent-to-'.$flavor);
    check(worker($flavor,'read')['row']['name']==='eloquent-to-'.$flavor,'Eloquent invalidates '.$flavor.' cache');
    worker($flavor,'write',$flavor.'-to-eloquent');
    check(worker('eloquent','read')['row']['name']===$flavor.'-to-eloquent',$flavor.' invalidates Eloquent cache');
}
$redis=testRedis();
$store=new RedisStore(function () use ($redis) { return $redis; });
$key=new RecordKey('catering-test','ca_cache_users','eloquent:CachedRow',['tenant_id','user_id'],['tenant_id'=>10,'user_id'=>20]);
list($versionKey,$dataKey)=$key->keys('integration:v1');
list($seen)=$store->read($versionKey,$dataKey,bin2hex(random_bytes(16)));
$stale=$pdo->query('SELECT * FROM ca_cache_users WHERE id=1')->fetch(PDO::FETCH_ASSOC);
worker('think','write','after-race');
check(!$store->publish($versionKey,$dataKey,$seen,serialize($stale),60),'Concurrent stale refill is fenced across ORM processes');
check(worker('eloquent','read')['row']['name']==='after-race','Current generation returns committed data');
echo json_encode(['backend'=>$backend,'checks'=>$checks,'workers'=>$results,'total'=>$checks+array_sum(array_column($results,'checks'))],JSON_THROW_ON_ERROR),"\n";
