<?php
require __DIR__ . '/bootstrap.php';

use Kylin987\Orm\RedisCache\RecordKey;
if ($flavor === 'thinkphp') {
    class_alias(Kylin987\Orm\RedisCache\ThinkPhp\ThinkOrmCache::class, 'TestThinkOrmCache');
} else {
    class_alias(Kylin987\Orm\RedisCache\Webman\ThinkOrmCache::class, 'TestThinkOrmCache');
}

if ($flavor==='thinkphp') {
    $app=new think\App(sys_get_temp_dir().'/orm-cache-test-'.getmypid());
    $app->config->set(['default'=>'default','stores'=>['default'=>['type'=>'redis','host'=>getenv('CACHE_TEST_REDIS_SOCKET'),'port'=>0]]],'cache');
    $db=new think\DbManager();
} elseif (class_exists(Webman\ThinkOrm\DbManager::class)) {
    $db=new Webman\ThinkOrm\DbManager();
} else {
    $db=new think\DbManager();
}
$nativeConfig=$dbConfig;
$nativeConfig['type']=$backend === 'mysql' ? 'mysql' : 'sqlite';
$db->setConfig(['default'=>'test','connections'=>['test'=>$dbConfig,'native'=>$nativeConfig]]);
think\Model::setDb($db);
think\Container::getInstance()->instance('think\DbManager',$db);
$connection=$db->connect();

class CachedRow extends think\Model {
    use TestThinkOrmCache;
    use think\model\concern\SoftDelete;
    protected $name='cache_users';
    protected $autoWriteTimestamp=false;
    protected $deleteTime='deleted_at';
    protected $defaultSoftDelete=null;
    protected $redisCacheScope='catering-test';
    protected $cachePk=['tenant_id','user_id'];
    protected $type=['meta'=>'json'];
    public static function onAfterUpdate($model): void { self::delCache($model); }
    public static function onAfterInsert($model): void { self::delCache($model); }
    public static function onAfterDelete($model): void { self::delCache($model); }
    public static function onAfterRestore($model): void { self::delCache($model); }
}
class ScopedRow extends CachedRow {
    protected $globalScope=['tenant'];
    public function scopeTenant($query) { $query->where('tenant_id',$GLOBALS['tenant']); }
}
class DecoratedRow extends CachedRow {
    public static function onAfterRead($model): void { $model->counter=(int)$model->counter+1; }
}
class UncachedRow extends CachedRow {
    protected $redisCacheScope='';
    protected $connection='native';
}
function revision() {
    return testRedis()->get(RecordKey::tableKey('integration:v1','catering-test','ca_cache_users').':revision');
}
$where=['tenant_id'=>10,'user_id'=>20];
$mode=$argv[2];
if ($mode==='read') {
    $row=CachedRow::getRedisCache($where);
    check($row && (string)$row->getOrigin('tenant_id')==='10','Cache hydration retains original attributes');
    finishWorker(['row'=>$row->getData()]); exit;
}
if ($mode==='write') {
    CachedRow::find(1)->save(['name'=>$argv[3]]);
    finishWorker(); exit;
}
$sqlCount=0;
$db->listen(function () use (&$sqlCount) { $sqlCount++; });
$row=CachedRow::getRedisCache($where,true);
check($row && $row->name==='ready','ThinkORM bridge reads a record');
$missQueries=$sqlCount;
$row=CachedRow::getRedisCache($where);
check($missQueries>0 && $sqlCount===$missQueries,'ThinkORM cache hit executes no SQL');
check($row->meta===['label'=>'中文','nested'=>['enabled'=>true]],'ThinkORM cache retains JSON casts and Unicode');
check(UncachedRow::getRedisCache($where)->name==='ready'
    && UncachedRow::delCache(new UncachedRow())===false, 'Empty ThinkORM scope uses native query without invalidation');
$first=DecoratedRow::getRedisCache($where,true);
$second=DecoratedRow::getRedisCache($where);
check((int)$first->counter===1 && (int)$second->counter===1
    && $first->getOrigin('counter')===$second->getOrigin('counter'),
    'AfterRead mutations never enter raw cache snapshot');
$row=CachedRow::getRedisCache($where);
$before=revision();
$connection->startTrans();
$row->save(['tenant_id'=>11,'user_id'=>21,'name'=>'committed']);
check(revision()===$before,'Outer transaction defers invalidation');
check(CachedRow::getRedisCache($where)===null,'Transaction ignores existing old cache');
check(CachedRow::getRedisCache(['tenant_id'=>11,'user_id'=>21])->name==='committed','Transaction reads own writes');
$connection->commit();
check(revision()!==$before,'Outer commit invalidates');
check(CachedRow::getRedisCache($where)===null,'Old composite key no longer resolves');
$where=['tenant_id'=>11,'user_id'=>21];
$row=CachedRow::getRedisCache($where);
$row->save(['name'=>'saved-from-cache']);
check(CachedRow::find(1)->name==='saved-from-cache','Hydrated record saves an UPDATE');
$before=revision();
$connection->startTrans();
CachedRow::find(1)->save(['name'=>'rollback']);
$connection->rollback();
check(revision()===$before && CachedRow::find(1)->name==='saved-from-cache','Rollback drops callback and data');
$connection->startTrans(); $connection->startTrans();
CachedRow::find(1)->save(['name'=>'nested']);
$connection->commit();
check(revision()===$before,'Inner commit defers invalidation');
$connection->rollback();
check(revision()===$before,'Outer rollback discards child callbacks');
$connection->startTrans();
CachedRow::find(1)->save(['name'=>'outer-kept']);
$connection->startTrans();
CachedRow::find(1)->save(['name'=>'inner-lost']);
$connection->rollback(); $connection->commit();
check(CachedRow::getRedisCache($where)->name==='outer-kept','Inner rollback preserves outer changes');
$connection->transaction(function () use ($connection) {
    $connection->transaction(function () { CachedRow::find(1)->save(['name'=>'both-commit']); });
});
check(CachedRow::getRedisCache($where)->name==='both-commit','Nested closures invalidate after commit');
CachedRow::getRedisCache($where)->delete();
check(CachedRow::getRedisCache($where)===null,'Soft delete invalidates');
CachedRow::withTrashed()->find(1)->restore();
check(CachedRow::getRedisCache($where)!==null,'Restore invalidates');
$GLOBALS['tenant']=11;
check(ScopedRow::getRedisCache($where)!==null,'Scoped model returns permitted tenant');
$GLOBALS['tenant']=99;
check(ScopedRow::getRedisCache($where)===null,'Custom tenant scope never reuses another tenant cache');
CachedRow::where('id',1)->update(['tenant_id'=>10,'user_id'=>20,'name'=>'ready']);
CachedRow::delCache(new CachedRow());
check(CachedRow::getRedisCache(['tenant_id'=>10,'user_id'=>20])->name==='ready','Bulk update is covered by explicit invalidation');
$callbacks=[];
$connection->startTrans();
$connection->afterCacheCommit(function () use (&$callbacks) { $callbacks[]='lost'; });
$connection->close();
$connection->transaction(function () use ($connection,&$callbacks) {
    $connection->afterCacheCommit(function () use (&$callbacks) { $callbacks[]='fresh'; });
});
check($callbacks===['fresh'],'Close and reconnect clears stale callbacks');
try { $connection->startTransXa('unsupported'); check(false,'XA must reject'); }
catch (LogicException $expected) { check(true,'XA is explicitly rejected'); }
finishWorker();
