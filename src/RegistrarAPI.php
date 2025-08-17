<?php
namespace RegistrarAPI;

use RegistrarAPI\Core\BaseAdapter;

final class RegistrarAPI
{
    /** brand aliases (input => canonical Studly) */
    private const ALIAS = [
        'cf'        => 'Cloudflare',
        'cloudflare'=> 'Cloudflare',
        'namesilo'  => 'Namesilo',
        'name-silo' => 'Namesilo',
        'go-daddy'  => 'GoDaddy',
        'godaddy'   => 'GoDaddy',
        'namecheap'  => 'Namecheap',
        'dynadot'  => 'Dynadot',
    ];

    public static function make(string $brand, array $creds): BaseAdapter
    {
        $brandKey = strtolower(trim($brand));
        $studly   = self::ALIAS[$brandKey] ?? self::studly($brand);

        // Try both namespace casings + with/without "Adapter" suffix
        $namespaces = ['\\RegistrarAPI\\Adapters\\', '\\RegistrarAPI\\adapters\\'];
        $candidates = [];
        foreach ($namespaces as $ns) {
            $candidates[] = $ns . $studly;              // e.g. \...Adapters\Cloudflare
            $candidates[] = $ns . $studly . 'Adapter';  // e.g. \...Adapters\CloudflareAdapter
        }

        foreach ($candidates as $class) {
            if (class_exists($class)) {
                $obj = new $class($creds);
                if (!$obj instanceof BaseAdapter) {
                    throw new \RuntimeException("$class must extend BaseAdapter");
                }
                return $obj;
            }
        }

        throw new \InvalidArgumentException(
            "Unknown registrar brand: {$brand}. Tried: " . implode(', ', $candidates)
        );
    }

    private static function studly(string $s): string
    {
        $s = preg_replace('/[^a-z0-9]+/i', ' ', $s);
        $s = ucwords(strtolower(trim($s)));
        return str_replace(' ', '', $s);
    }
}
