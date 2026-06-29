<?php

namespace Deployward;

final class Autoloader
{
    public static function register(string $baseDir): void
    {
        $root = rtrim($baseDir, '/');
        spl_autoload_register(function ($class) use ($root) {
            $prefix = 'Deployward\\';
            if (strpos($class, $prefix) !== 0) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $path = $root . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require $path;
            }
        });
    }
}
