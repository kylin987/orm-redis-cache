<?php

namespace Webman;

/** A minimal host interface for framework-free installation tests. */
interface Bootstrap
{
    public static function start($worker);
}
