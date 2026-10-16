<?php

namespace Composer;

/** Only loaded in the standalone unsupported-dependency test process. */
class InstalledVersions
{
    public static function isInstalled($name) { return true; }
    public static function getVersion($name) { return '12.0.0.0'; }
}
