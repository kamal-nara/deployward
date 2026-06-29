<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

final class PayloadValidator
{
    public function validate(string $dir, string $targetType, string $targetSlug): Result
    {
        if (! is_dir($dir)) {
            return Result::fail('Payload directory is missing');
        }
        if ($targetType === 'theme') {
            return $this->validateTheme($dir, $targetSlug);
        }

        return $this->validatePlugin($dir, $targetSlug);
    }

    private function validatePlugin(string $dir, string $slug): Result
    {
        $files = glob($dir . '/*.php');
        if ($files === false) {
            $files = array();
        }
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match('/^[ \t\/*#@]*Plugin Name:/mi', $contents)) {
                return Result::ok($slug);
            }
        }

        return Result::fail('No plugin header found in payload for ' . $slug);
    }

    private function validateTheme(string $dir, string $slug): Result
    {
        $style = $dir . '/style.css';
        if (! is_file($style)) {
            return Result::fail('Theme payload is missing style.css');
        }
        $contents = (string) file_get_contents($style);
        if (! preg_match('/^[ \t\/*#@]*Theme Name:/mi', $contents)) {
            return Result::fail('No theme header found in style.css');
        }

        return Result::ok($slug);
    }
}
