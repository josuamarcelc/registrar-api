<?php
// Works with or without `composer install`.
if (is_file(__DIR__.'/../vendor/autoload.php')) {
    require __DIR__.'/../vendor/autoload.php';
} else {
    spl_autoload_register(function (string $class) {
        foreach (['RegistrarAPI\\Adapters\\' => '/../src/adapters/', 'RegistrarAPI\\' => '/../src/'] as $prefix => $dir) {
            if (strncmp($class, $prefix, strlen($prefix)) === 0) {
                $file = __DIR__.$dir.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                if (is_file($file)) { require $file; return; }
            }
        }
    });
}

use RegistrarAPI\Core\Http;

/** Records every request and answers from a queue of [code, body] pairs (or a callable). */
final class FakeHttp {
    public static array $calls = [];
    private static array $queue = [];
    public static function reply(...$responses): void {
        self::$calls = []; self::$queue = $responses;
        Http::setTransport(function (string $method, string $url, ?string $body, array $headers) {
            self::$calls[] = compact('method', 'url', 'body', 'headers');
            $next = array_shift(self::$queue) ?? [500, ''];
            if (is_callable($next)) $next = $next($method, $url, $body);
            return [$next[0], is_string($next[1]) ? $next[1] : json_encode($next[1]), ''];
        });
    }
    public static function last(): array { return end(self::$calls) ?: []; }
    public static function query(int $i = -1): array {
        $c = $i < 0 ? self::last() : self::$calls[$i];
        parse_str((string)parse_url($c['url'], PHP_URL_QUERY), $q);
        if ($c['body'] !== null && strpos(implode("\n", $c['headers']), 'x-www-form-urlencoded') !== false) { parse_str($c['body'], $b); $q += $b; }
        return $q;
    }
}

$GLOBALS['__fails'] = 0; $GLOBALS['__passes'] = 0;
function check(string $label, bool $cond): void {
    if ($cond) { $GLOBALS['__passes']++; return; }
    $GLOBALS['__fails']++; echo "FAIL: {$label}\n";
}
function same(string $label, $expected, $actual): void {
    check($label.' (expected '.json_encode($expected).', got '.json_encode($actual).')', $expected === $actual);
}
