<?php

declare(strict_types=1);

namespace Kylin987\Orm\RedisCache;

final class Install
{
    public const WEBMAN_PLUGIN = true;

    public static function install(): void
    {
        if (!interface_exists(\Webman\Bootstrap::class) || !function_exists('base_path')) {
            return;
        }
        $relative = 'config/plugin/kylin987/orm-redis-cache';
        $destination = base_path() . '/' . $relative;
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }
        foreach (glob(__DIR__ . '/' . $relative . '/*.php') as $source) {
            $target = $destination . '/' . basename($source);
            if (!file_exists($target)) {
                copy($source, $target);
            }
        }
    }

    public static function uninstall(): void
    {
        // Keep user configuration on uninstall; reinstall must not lose it.
    }
}
