<?php
namespace support;
/** Source-package tests only: use an explicitly supplied isolated socket. */
class Redis {
    public static function connection($name = 'default') {
        return new class {
            public function client() { return \testRedis(); }
        };
    }
}
