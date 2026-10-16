<?php

// Usage: php tests/composer.php /isolated/vendor/autoload.php core|eloquent|think|thinkphp
$autoload = $argv[1] ?? '';
$flavor = $argv[2] ?? '';
if (!is_file($autoload)) throw new RuntimeException('Supply an isolated Composer autoload file');
$loader = require $autoload;
require __DIR__ . '/bootstrap.php';

use Composer\InstalledVersions;
use Kylin987\Orm\RedisCache\Eloquent\Transactions;

$eloquent = InstalledVersions::isInstalled('illuminate/database');
$think = InstalledVersions::isInstalled('topthink/think-orm');
check(InstalledVersions::isInstalled('kylin987/orm-redis-cache'), 'Unified package is installed');
$trait = null;
switch ($flavor) {
    case 'core':
        check(!$eloquent && !$think, 'Core-only installation pulls neither ORM');
        $vendorPackages = array_values(array_diff(InstalledVersions::getInstalledPackages(), [InstalledVersions::getRootPackage()['name']]));
        check($vendorPackages === ['kylin987/orm-redis-cache'], 'Core-only has no vendor dependencies');
        check(!Transactions::available(), 'No implicit Eloquent integration');
        break;
    case 'eloquent':
        check($eloquent && !$think, 'Eloquent installation pulls no ThinkORM');
        check(Transactions::available(), 'Supported Eloquent dependencies detected');
        $trait = Kylin987\Orm\RedisCache\Eloquent\EloquentCache::class;
        break;
    case 'think':
    case 'thinkphp':
        check($think && !$eloquent, 'ThinkORM installation pulls no Eloquent database');
        $trait = $flavor === 'think'
            ? Kylin987\Orm\RedisCache\Webman\ThinkOrmCache::class
            : Kylin987\Orm\RedisCache\ThinkPhp\ThinkOrmCache::class;
        $legacy = 'Kylin987\\ThinkOrm\\RedisCache\\traits\\ThinkOrmCache';
        check(trait_exists($legacy) && trait_exists($trait), 'Old and unified traits coexist');
        check(realpath((new ReflectionClass($legacy))->getFileName()) !== realpath((new ReflectionClass($trait))->getFileName()),
            'Unified trait does not replace old namespace');
        break;
    default:
        throw new RuntimeException('Unknown fixture flavor');
}
if ($trait !== null) {
    check(trait_exists($trait), 'Selected adapter autoloads');
    check(realpath($loader->findFile($trait)) === realpath((new ReflectionClass($trait))->getFileName()),
        'Composer PSR-4 maps selected adapter to unified package');
}
echo json_encode(['flavor'=>$flavor,'checks'=>$checks,'eloquent'=>$eloquent,'thinkorm'=>$think], JSON_THROW_ON_ERROR), "\n";
