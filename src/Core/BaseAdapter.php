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

    // Available for all adapters in this library
    abstract public function setNameServers(string $domain, array $nameservers): array;

    abstract public function raw(string $op, array $params=[]): array;

    protected function json(string $body): array {
        $j = json_decode($body, true);
        return $j===null ? ['_parse_error'=>true, '_raw'=>$body] : $j;
    }
}
