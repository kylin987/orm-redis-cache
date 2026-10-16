<?php
return [
    'cache_store' => 'default',
    'cache_exptime' => 300,
    // Must match every participating app, including Redis prefix/database.
    'shared_prefix' => 'ormrc:v1',
    // Optional callable(string $operation, Throwable $error): void.
    'error_handler' => null,
];
