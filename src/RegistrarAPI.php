<?php
namespace RegistrarAPI;

use RegistrarAPI\Core\BaseAdapter;

final class RegistrarAPI
{
    /** brand aliases (input => canonical Studly) */
    private const ALIAS = [
        'cf'         => 'Cloudflare',
        'cloudflare' => 'Cloudflare',
        'namesilo'   => 'Namesilo',
        'name-silo'  => 'Namesilo',
        'go-daddy'   => 'GoDaddy',
        'godaddy'    => 'GoDaddy',
        'namecheap'  => 'Namecheap',
        'name-cheap' => 'Namecheap',
        'dynadot'    => 'Dynadot',
    ];

    public static function make(string $brand, array $creds): BaseAdapter
    {
        $brandKey = strtolower(trim($brand));
        $studly   = self::ALIAS[$brandKey] ?? self::studly($brand);

        // With and without the "Adapter" suffix, e.g. Adapters\Namesilo or Adapters\CloudflareAdapter
        $candidates = ['\\RegistrarAPI\\Adapters\\' . $studly, '\\RegistrarAPI\\Adapters\\' . $studly . 'Adapter'];

        foreach ($candidates as $class) {
            if (class_exists($class)) {
                if (!is_subclass_of($class, BaseAdapter::class)) {
                    throw new \RuntimeException("$class must extend " . BaseAdapter::class);
                }
                return new $class($creds);
            }
        }

        throw new \InvalidArgumentException(
            "Unknown registrar brand: {$brand}. Tried: " . implode(', ', $candidates)
        );
    }

    /** Canonical brand names shipped with the library. */
    public static function brands(): array
    {
        return array_values(array_unique(self::ALIAS));
    }

    private static function studly(string $s): string
    {
        $s = preg_replace('/[^a-z0-9]+/i', ' ', $s);
        $s = ucwords(strtolower(trim($s)));
        return str_replace(' ', '', $s);
    }
}
