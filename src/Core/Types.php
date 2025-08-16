<?php
namespace RegistrarAPI\Core;

final class DnsRecord {
    public string $type;
    public string $host;
    public string $value;
    public int $ttl;
    public ?int $prio;
    public function __construct(string $type, string $host, string $value, int $ttl=3600, ?int $prio=null) {
        $this->type=$type; $this->host=$host; $this->value=$value; $this->ttl=$ttl; $this->prio=$prio;
    }
    public function toArray(): array {
        return ['type'=>$this->type,'host'=>$this->host,'value'=>$this->value,'ttl'=>$this->ttl,'prio'=>$this->prio];
    }
}
