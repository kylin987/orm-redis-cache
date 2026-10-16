<?php
require __DIR__ . '/bootstrap.php';

use Kylin987\Orm\RedisCache\RecordKey;
use Kylin987\Orm\RedisCache\RecordCache;
use Kylin987\Orm\RedisCache\RedisStore;
use Kylin987\Orm\RedisCache\Store;

$redis = testRedis();
$prefix = 'test:' . bin2hex(random_bytes(8));
$store = new RedisStore(function () use ($redis) { return $redis; });
$cache = new RecordCache($store, $prefix);
$key = new RecordKey('catering', 'ca_products', 'eloquent:Product', ['tenant_id', 'id'], ['id' => 2, 'tenant_id' => 1]);
$same = new RecordKey('catering', 'ca_products', 'eloquent:Product', ['tenant_id', 'id'], ['tenant_id' => '1', 'id' => '2']);
check($key->keys($prefix) === $same->keys($prefix), 'Integer/string and input order normalize');
$other = new RecordKey('catering', 'ca_products', 'thinkorm:Product', ['tenant_id','id'], ['tenant_id'=>1,'id'=>2]);
check($key->keys($prefix)[0] === $other->keys($prefix)[0] && $key->keys($prefix)[1] !== $other->keys($prefix)[1], 'ORM payloads differ but table invalidation is shared');
check($key->keys($prefix)[0] !== (new RecordKey('other-db','ca_products','eloquent:Product',['tenant_id','id'],['tenant_id'=>1,'id'=>2]))->keys($prefix)[0], 'Business databases are isolated');
foreach ([[['id','id'], ['id'=>1]], [['id'], ['id'=>true]], [['id'], ['id'=>null]], [['id'], ['id'=>1,'extra'=>2]], ['id', []], [['id OR 1=1'], ['id OR 1=1'=>1]]] as $invalid) {
    try { RecordKey::normalize($invalid[0],$invalid[1]); check(false, 'Invalid identity must fail'); }
    catch (InvalidArgumentException $expected) { check(true, 'Invalid identity rejected'); }
}
$queries = 0;
$loader = function () use (&$queries) { $queries++; return ['id'=>2,'payload'=>"\xff\0binary"]; };
check($cache->remember($key,60,$loader)['id'] === 2, 'Miss loads a record');
check($cache->remember($key,60,$loader)['payload'] === "\xff\0binary" && $queries === 1, 'Cache hit preserves binary raw attributes and avoids database');
$cache->remember($other,60,$loader);
$cache->invalidate('catering','ca_products');
$cache->remember($other,60,$loader);
check($queries === 3, 'Shared invalidation affects the other ORM');
list($versionKey,$dataKey) = $key->keys($prefix);
list($seen) = $store->read($versionKey,$dataKey,bin2hex(random_bytes(16)));
$cache->invalidate('catering','ca_products');
check(!$store->publish($versionKey,$dataKey,$seen,serialize(['id'=>9]),60), 'Stale in-flight fill is rejected');
$cache->remember($key,60,$loader);
list($seen) = $store->read($versionKey,$dataKey,bin2hex(random_bytes(16)));
$redis->del($versionKey);
list($fresh,$payload) = $store->read($versionKey,$dataKey,bin2hex(random_bytes(16)));
check($fresh !== $seen && $payload === false, 'Evicted revision cannot resurrect old data');
check(!$store->publish($versionKey,$dataKey,$seen,serialize(['id'=>9]),60), 'Evicted revision rejects previous publisher');
$cache->remember($key,60,$loader);
$count = $queries;
check($cache->remember($key,60,function () { return null; },false,true) === null, 'Forced refresh can return missing record');
$cache->remember($key,60,$loader);
check($queries === $count+1, 'Forced refresh removes stale data even when database returns null');
$missing = new RecordKey('catering','ca_products','eloquent:Product','id',999);
$cache->remember($missing,60,function () { return null; });
check(!$redis->exists($missing->keys($prefix)[1]), 'Missing rows are not cached');
$before = $redis->get($versionKey);
$cache->remember($key,60,function () { return ['id'=>2,'name'=>'uncommitted']; },true,true);
check($redis->get($versionKey) === $before, 'Transaction bypass does not invalidate or publish');
$redis->set($dataKey,$before.'malformed');
check($cache->remember($key,60,$loader)['id'] === 2, 'Corrupt payload falls back to database');
check($redis->ttl($dataKey) > 0 && $redis->ttl($dataKey) <= 60, 'Data keys have bounded TTL');
$calls = 0;
try { $cache->remember($missing,60,function () use (&$calls) { $calls++; throw new LogicException('database failed'); }); }
catch (LogicException $expected) { check($calls === 1, 'Database exception propagates without repeating query'); }

$errors = [];
$failing = new class implements Store {
    public function read(string $a,string $b,string $c): array { throw new RuntimeException('offline'); }
    public function publish(string $a,string $b,string $c,string $d,int $e): bool { throw new RuntimeException('offline'); }
    public function invalidate(string $a,string $b): void { throw new RuntimeException('offline'); }
};
$fallback = new RecordCache($failing,$prefix,function ($op,$error) use (&$errors) { $errors[]=$op; });
check($fallback->remember($key,60,$loader)['id'] === 2 && $errors === ['read'], 'Redis read failure reports and queries database');
check(!$fallback->invalidate('catering','ca_products') && $errors === ['read','invalidate'], 'Invalidation failure is reported without throwing after a commit');
echo 'OK: ', $checks, " core checks\n";
