<?php

// Standalone installation check. Uses only disposable files, no host bootstrap.
$destination = sys_get_temp_dir() . '/eloquent-cache-install-' . bin2hex(random_bytes(6));
function base_path(): string
{
    global $destination;
    return $destination;
}
require __DIR__ . '/bootstrap.php';
if (isset($argv[1])) {
    require $argv[1];
}

use Kylin987\Orm\RedisCache\Install;

$directory = $destination . '/config/plugin/kylin987/orm-redis-cache';
try {
    if (!interface_exists(\Webman\Bootstrap::class)) {
        Install::install();
        check(!is_dir($destination), 'Non-Webman install is a no-op');
        require __DIR__ . '/Support/WebmanBootstrap.php';
    }
    Install::install();
    foreach (['app.php', 'bootstrap.php', 'config.php'] as $file) {
        if (!is_file($directory . '/' . $file)) {
            throw new RuntimeException('Missing installed config: ' . $file);
        }
    }
    $bootstrap = require $directory . '/bootstrap.php';
    if ($bootstrap !== [Kylin987\Orm\RedisCache\Webman\Bootstrap::class]) {
        throw new RuntimeException('Bootstrap was not registered');
    }
    Kylin987\Orm\RedisCache\Webman\Bootstrap::start(null);
    $custom = "<?php return ['cache_exptime' => 42];\n";
    file_put_contents($directory . '/config.php', $custom);
    Install::install();
    Install::uninstall();
    if (file_get_contents($directory . '/config.php') !== $custom) {
        throw new RuntimeException('User configuration was overwritten');
    }
    echo "OK: platform-safe install, bootstrap registration and config preservation\n";
} finally {
    if (is_dir($directory)) {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        $path = $directory;
        while (strpos($path, $destination) === 0) {
            rmdir($path);
            if ($path === $destination) break;
            $path = dirname($path);
        }
    }
}
