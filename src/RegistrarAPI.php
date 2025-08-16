<?php
namespace RegistrarAPI;

use RegistrarAPI\Core\BaseAdapter;

final class RegistrarAPI {
    public static function make(string $brand, array $creds): BaseAdapter {
        $studly = self::studly($brand);
        $class = "\\RegistrarAPI\\adapters\\{$studly}";
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Unknown registrar brand: {$brand} (expected class {$class})");
        }
        return new $class($creds);
    }
    private static function studly(string $s): string {
        $s = preg_replace('/[^a-z0-9]+/i', ' ', $s);
        $s = ucwords(strtolower(trim($s)));
        return str_replace(' ', '', $s);
    }
}
