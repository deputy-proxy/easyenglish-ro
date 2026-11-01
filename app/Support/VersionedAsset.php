<?php

namespace App\Support;

use RuntimeException;

class VersionedAsset
{
    public static function url(string $path): string
    {
        $path = ltrim($path, '/');
        $filePath = public_path($path);

        if (! is_file($filePath)) {
            throw new RuntimeException("Public asset [{$path}] does not exist.");
        }

        $hash = hash_file('sha256', $filePath);

        if ($hash === false) {
            throw new RuntimeException("Public asset [{$path}] could not be hashed.");
        }

        return asset($path).'?v='.$hash;
    }
}
