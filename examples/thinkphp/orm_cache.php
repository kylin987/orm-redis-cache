<?php

return [
    // Must name a Redis store defined in config/cache.php.
    'cache_store' => 'default',
    'cache_exptime' => 300,
    'shared_prefix' => 'ormrc:v1',
    'error_handler' => null,
];
