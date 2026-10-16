<?php

declare(strict_types=1);

namespace Kylin987\Orm\RedisCache\Eloquent;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use LogicException;
use WeakMap;
use Composer\InstalledVersions;

final class Transactions
{
    private static ?WeakMap $prepared = null;
    private static array $installed = [];

    public static function available(): bool
    {
        if (!class_exists(InstalledVersions::class)) {
            return false;
        }
        foreach (['illuminate/database', 'illuminate/events'] as $package) {
            if (!InstalledVersions::isInstalled($package)) {
                return false;
            }
            $version = (string) InstalledVersions::getVersion($package);
            if (version_compare($version, '10.48.0.0', '<') || version_compare($version, '11.0.0.0', '>=')) {
                return false;
            }
        }
        return class_exists(\Illuminate\Events\Dispatcher::class);
    }

    public static function assertSupported(): void
    {
        if (!self::available()) {
            throw new LogicException('Eloquent cache requires illuminate/database ^10.48 and illuminate/events ^10.48');
        }
    }

    /** Run before business transactions; applies to future pooled connections too. */
    public static function install(): void
    {
        self::assertSupported();
        foreach (['mysql' => MySqlConnection::class, 'sqlite' => SQLiteConnection::class] as $driver => $class) {
            if (isset(self::$installed[$driver])) {
                continue;
            }
            $previous = Connection::getResolver($driver);
            $resolver = static function ($pdo, $database, $prefix, $config) use ($previous, $class) {
                $connection = $previous ? $previous($pdo, $database, $prefix, $config) : new $class($pdo, $database, $prefix, $config);
                self::prepare($connection);
                return $connection;
            };
            Connection::resolverFor($driver, $resolver);
            self::$installed[$driver] = $resolver;
        }
    }

    public static function prepare(Connection $connection): void
    {
        self::$prepared ??= new WeakMap();
        if (isset(self::$prepared[$connection])) {
            return;
        }
        // Preserve a host-provided transaction manager rather than replace it.
        $manager = (function () { return $this->transactionsManager; })->call($connection);
        if ($manager === null) {
            if ($connection->transactionLevel() !== 0) {
                throw new LogicException('Start Eloquent cache Bootstrap before opening a database transaction');
            }
            $manager = new DatabaseTransactionsManager();
            $connection->setTransactionManager($manager);
            $connection->beforeStartingTransaction(static function ($connection) use ($manager) {
                if ($connection->transactionLevel() === 0) {
                    // disconnect()/setPdo() reset PDO nesting but not native pending callbacks.
                    $manager->rollback($connection->getName(), 0);
                }
            });
        }
        self::$prepared[$connection] = true;
    }

    public static function afterCommit(Connection $connection, callable $callback): void
    {
        if ($connection->transactionLevel() === 0) {
            $callback();
        } else {
            $connection->afterCommit($callback);
        }
    }
}
