<?php
namespace RegistrarAPI\Core;

abstract class BaseAdapter {
    protected string $brand;
    protected array $creds;
    public function __construct(array $creds) { $this->creds = $creds; }
    public function brand(): string { return $this->brand; }

    abstract public function checkAvailability(array $domains): array;
    abstract public function registerDomain(string $domain, array $opts): array;
    abstract public function renewDomain(string $domain, int $years=1, array $opts=[]): array;
    abstract public function transferDomain(string $domain, array $opts): array;
    abstract public function getDomain(string $domain): array;

    abstract public function getDNS(string $domain): array;
    abstract public function setDNS(string $domain, array $records): array;
    abstract public function addDNS(string $domain, array $record): array;
    abstract public function delDNS(string $domain, array $selector): array;

    abstract public function setNameServers(string $domain, array $nameservers): array;

    abstract public function raw(string $op, array $params=[]): array;

    protected function json(string $body): array {
        if ($body === '') return [];
        $j = json_decode($body, true);
        return is_array($j) ? $j : ['_parse_error'=>true, '_raw'=>$body];
    }

    /** Standard result shape: ok/raw/http/err plus any extra keys. */
    protected function result(bool $ok, $raw, int $http=0, string $err='', array $extra=[]): array {
        return array_merge(['ok'=>$ok, 'raw'=>$raw, 'http'=>$http, 'err'=>$err], $extra);
    }

    protected function unsupported(string $what): array {
        return $this->result(false, null, 0, "{$this->brand}: {$what} is not supported by this registrar's API", ['unsupported'=>true]);
    }

    protected function requireCreds(array $keys): void {
        foreach ($keys as $k) {
            if (!isset($this->creds[$k]) || $this->creds[$k] === '') {
                throw new \InvalidArgumentException("{$this->brand}: missing credential '{$k}'");
            }
        }
    }

    /** Accepts a scalar or a list and always returns a list (registrars collapse 1-item lists). */
    protected static function listOf($v): array {
        if ($v === null || $v === '' || $v === []) return [];
        if (!is_array($v)) return [$v];
        return array_keys($v) === range(0, count($v) - 1) ? $v : [$v];
    }
}
