<?php
require __DIR__ . '/bootstrap.php';

use Kylin987\Orm\RedisCache\Eloquent\EloquentCache;
use Kylin987\Orm\RedisCache\Eloquent\Transactions;
use Kylin987\Orm\RedisCache\RecordKey;

Transactions::install();
$capsule = new Webman\Database\Manager();
$capsule->addConnection($dbConfig);
$capsule->setEventDispatcher(new Illuminate\Events\Dispatcher(new Illuminate\Container\Container()));
$capsule->setAsGlobal();
$capsule->bootEloquent();
$connection = $capsule->getConnection();
class CachedRow extends Illuminate\Database\Eloquent\Model {
    use EloquentCache;
    use Illuminate\Database\Eloquent\SoftDeletes;
    protected $table = 'cache_users';
    protected $guarded = [];
    protected $redisCacheScope = 'catering-test';
    protected $cachePk = ['tenant_id','user_id'];
    public $timestamps = false;
    protected $casts = ['tenant_id'=>'integer','meta'=>'array'];
    public static $reads = 0;
    protected static function booted() { static::retrieved(function () { self::$reads++; }); }
}
class ScopedRow extends CachedRow {
    protected static function booted() {
        static::addGlobalScope('tenant',function ($query) { $query->where('tenant_id',$GLOBALS['tenant']); });
    }
}
class DecoratedRow extends CachedRow {
    protected static function booted() {
        static::retrieved(function ($model) { $model->counter = (int)$model->counter + 1; });
    }
}
class UncachedRow extends CachedRow {
    protected $redisCacheScope='';
}
function revision() {
    return testRedis()->get(RecordKey::tableKey('integration:v1','catering-test','ca_cache_users').':revision');
}
$where=['tenant_id'=>10,'user_id'=>20];
$mode=$argv[2];
if ($mode === 'read') {
    $row=CachedRow::getRedisCache($where);
    check($row && $row->exists && !$row->isDirty() && is_int($row->tenant_id),'Hydrated record retains exists, original and casts');
    finishWorker(['row'=>$row->getAttributes()]); exit;
}
if ($mode === 'write') {
    CachedRow::findOrFail(1)->update(['name'=>$argv[3]]);
    finishWorker(); exit;
}
$connection->enableQueryLog();
$row=CachedRow::getRedisCache($where,true);
check(CachedRow::$reads===1,'Cache miss fires retrieved only once');
$missQueries=count($connection->getQueryLog());
$row=CachedRow::getRedisCache($where);
check(CachedRow::$reads===2,'Cache hit fires retrieved only once');
check($missQueries>0 && count($connection->getQueryLog())===$missQueries,'Eloquent cache hit executes no SQL');
check($row->meta===['label'=>'中文','nested'=>['enabled'=>true]],'Eloquent cache retains JSON casts and Unicode');
$connection->disableQueryLog();
check(UncachedRow::getRedisCache($where)->name==='ready'
    && UncachedRow::delCache(new UncachedRow())===false, 'Empty Eloquent scope uses native query without invalidation');
$row->increment('counter');
check((int)CachedRow::getRedisCache($where)->counter===1,'Model increment invalidates via updated event');
$row->decrement('counter');
check((int)CachedRow::getRedisCache($where)->counter===0,'Model decrement invalidates via updated event');
$first=DecoratedRow::getRedisCache($where,true);
$second=DecoratedRow::getRedisCache($where);
check((int)$first->counter===1 && (int)$second->counter===1
    && (int)$first->getRawOriginal('counter')===0 && (int)$second->getRawOriginal('counter')===0,
    'Retrieved observer mutations never enter the raw cache snapshot');
$before=revision();
$connection->beginTransaction();
$row->update(['tenant_id'=>11,'user_id'=>21,'name'=>'committed']);
check(revision()===$before,'Outer transaction defers invalidation');
check(CachedRow::getRedisCache($where)===null,'Transaction ignores existing old cache');
check(CachedRow::getRedisCache(['tenant_id'=>11,'user_id'=>21])->name==='committed','Transaction reads own writes');
$connection->commit();
check(revision()!==$before,'Outer commit invalidates');
check(CachedRow::getRedisCache($where)===null,'Composite old key no longer returns data');
$where=['tenant_id'=>11,'user_id'=>21];
$row=CachedRow::getRedisCache($where);
$row->update(['name'=>'saved-from-cache']);
check(CachedRow::findOrFail(1)->name==='saved-from-cache','Restored model performs UPDATE rather than INSERT');
$before=revision();
$connection->beginTransaction();
CachedRow::findOrFail(1)->update(['name'=>'rollback']);
$connection->rollBack();
check(revision()===$before && CachedRow::findOrFail(1)->name==='saved-from-cache','Rollback drops callback and data');
$connection->beginTransaction();
$connection->beginTransaction();
CachedRow::findOrFail(1)->update(['name'=>'nested-rollback']);
$connection->commit();
check(revision()===$before,'Inner commit does not invalidate');
$connection->rollBack();
check(revision()===$before,'Outer rollback discards child callbacks');
$connection->beginTransaction();
CachedRow::findOrFail(1)->update(['name'=>'outer-kept']);
$connection->beginTransaction();
CachedRow::findOrFail(1)->update(['name'=>'inner-lost']);
$connection->rollBack();
$connection->commit();
check(CachedRow::getRedisCache($where)->name==='outer-kept','Inner rollback preserves outer callback');
$connection->transaction(function () use ($connection,$where) {
    $connection->transaction(function () { CachedRow::findOrFail(1)->update(['name'=>'both-commit']); });
});
check(CachedRow::getRedisCache($where)->name==='both-commit','Nested closure transactions flush at outer commit');
$row=CachedRow::getRedisCache($where);
$row->delete();
check(CachedRow::getRedisCache($where)===null,'Soft delete invalidates');
CachedRow::withTrashed()->findOrFail(1)->restore();
check(CachedRow::getRedisCache($where)!==null,'Restore invalidates');
$GLOBALS['tenant']=11;
check(ScopedRow::getRedisCache($where)!==null,'Scoped model can query permitted tenant');
$GLOBALS['tenant']=99;
check(ScopedRow::getRedisCache($where)===null,'Changing tenant cannot reuse prior scoped result');
CachedRow::where('id',1)->update(['tenant_id'=>10,'user_id'=>20,'name'=>'ready']);
CachedRow::delCache(new CachedRow());
check(CachedRow::getRedisCache(['tenant_id'=>10,'user_id'=>20])->name==='ready','Explicit invalidation handles bulk writes');

// Overlapping Fibers use real Webman pooled connections, not global transaction state.
$callbacks=[]; $connections=[];
$a=new Fiber(function () use ($capsule,&$callbacks,&$connections) {
    $c=$capsule->getConnection(); $connections['a']=$c;
    $c->beginTransaction(); $c->afterCommit(function () use (&$callbacks) { $callbacks[]='a'; });
    Fiber::suspend(); $c->commit(); Webman\Context::destroy();
});
$b=new Fiber(function () use ($capsule,&$callbacks,&$connections) {
    $c=$capsule->getConnection(); $connections['b']=$c;
    $c->beginTransaction(); $c->afterCommit(function () use (&$callbacks) { $callbacks[]='b'; });
    Fiber::suspend(); $c->rollBack(); Webman\Context::destroy();
});
$a->start(); $b->start();
check($connections['a']!==$connections['b'],'Fibers receive separate database connections');
$a->resume(); $b->resume();
check($callbacks===['a'],'Fiber rollback cannot discard another Fiber callback');
unset($a,$b);
$c=new Fiber(function () use ($capsule,&$callbacks) {
    $db=$capsule->getConnection(); $db->transaction(function () use ($db,&$callbacks) { $db->afterCommit(function () use (&$callbacks) { $callbacks[]='reused'; }); });
    Webman\Context::destroy();
});
$c->start();
check($callbacks===['a','reused'],'Pooled connection reuse has no stale transaction callbacks');
$connection->disconnect();
$connection->transaction(function () use ($connection,&$callbacks) { $connection->afterCommit(function () use (&$callbacks) { $callbacks[]='reconnected'; }); });
check(end($callbacks)==='reconnected','Eloquent reconnect keeps transaction integration');
$connection->beginTransaction();
$connection->afterCommit(function () use (&$callbacks) { $callbacks[]='abandoned'; });
$connection->disconnect();
$connection->transaction(function () use ($connection,&$callbacks) {
    $connection->afterCommit(function () use (&$callbacks) { $callbacks[]='after-abandon'; });
});
check(end($callbacks)==='after-abandon' && !in_array('abandoned',$callbacks,true),'Disconnect with open transaction cannot leak callbacks into next transaction');
finishWorker();
