<?php

namespace Kylin987\Orm\RedisCache;

use InvalidArgumentException;

/** Protocol v1: table identity is shared, model payloads are isolated. */
final class RecordKey
{
    private $scope;
    private $table;
    private $family;
    private $values;

    public function __construct(string $scope, string $table, string $family, $fields, $values)
    {
        if ($scope === '' || $table === '' || $family === '') {
            throw new InvalidArgumentException('Cache scope, physical table and model family must not be empty');
        }
        $this->scope = $scope;
        $this->table = $table;
        $this->family = $family;
        $this->values = self::normalize($fields, $values);
    }

    public static function normalize($fields, $values): array
    {
        if (is_string($fields)) {
            $values = [$fields => $values];
            $fields = [$fields];
        }
        if (!is_array($fields) || !$fields || !is_array($values) || count($fields) !== count($values)) {
            throw new InvalidArgumentException('Cache values must contain exactly the configured fields');
        }
        $normalized = [];
        foreach ($fields as $field) {
            if (!is_string($field) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $field) || array_key_exists($field, $normalized)) {
                throw new InvalidArgumentException('Cache fields must be distinct column names');
            }
            if (!array_key_exists($field, $values) || (!is_int($values[$field]) && !is_string($values[$field]))) {
                throw new InvalidArgumentException('Cache field values must be integers or strings');
            }
            $normalized[$field] = (string) $values[$field];
        }
        return $normalized;
    }

    public function values(): array
    {
        return $this->values;
    }

    public static function tableKey(string $prefix, string $scope, string $table): string
    {
        if ($prefix === '' || strpbrk($prefix, '{}') !== false || $scope === '' || $table === '') {
            throw new InvalidArgumentException('Invalid cache prefix, scope or physical table');
        }
        return $prefix . ':{' . hash('sha256', serialize([$scope, $table])) . '}';
    }

    public function keys(string $prefix): array
    {
        $base = self::tableKey($prefix, $this->scope, $this->table);
        return [$base . ':revision', $base . ':data:' . hash('sha256', serialize([$this->family, $this->values]))];
    }
}
