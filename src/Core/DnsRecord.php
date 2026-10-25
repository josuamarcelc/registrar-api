<?php
namespace RegistrarAPI\Core;

final class DnsRecord {
    public string $type;
    public string $host;
    public string $value;
    public int $ttl;
    public ?int $prio;
    /** Registrar-side record id, when the registrar exposes one (NameSilo, GoDaddy has none, Namecheap HostId, Cloudflare id). */
    public ?string $id;
    public function __construct(string $type, string $host, string $value, int $ttl=3600, ?int $prio=null, ?string $id=null) {
        $this->type=strtoupper($type); $this->host=$host; $this->value=$value; $this->ttl=$ttl; $this->prio=$prio; $this->id=$id;
    }
    public function toArray(): array {
        return ['type'=>$this->type,'host'=>$this->host,'value'=>$this->value,'ttl'=>$this->ttl,'prio'=>$this->prio,'id'=>$this->id];
    }

    /** Turn "www.example.com" / "example.com" / "" into the relative form used everywhere: "www" / "@". */
    public static function relativeHost(string $host, string $domain): string {
        $host = rtrim(strtolower(trim($host)), '.'); $domain = strtolower($domain);
        if ($host === '' || $host === '@' || $host === $domain) return '@';
        $suffix = '.'.$domain;
        if (substr($host, -strlen($suffix)) === $suffix) return substr($host, 0, -strlen($suffix));
        return $host;
    }

    /** True when $record matches every key given in $selector (type/host/value/id/prio). */
    public static function matches(array $record, array $selector): bool {
        foreach (['type','host','value','id','prio'] as $k) {
            if (!array_key_exists($k, $selector) || $selector[$k] === null) continue;
            $a = (string)($record[$k] ?? ''); $b = (string)$selector[$k];
            if ($k === 'type' || $k === 'host') { $a = strtolower($a); $b = strtolower($b); }
            if ($a !== $b) return false;
        }
        return true;
    }
}
