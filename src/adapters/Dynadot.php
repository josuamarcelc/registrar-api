<?php
namespace RegistrarAPI\adapters;

use RegistrarAPI\Core\BaseAdapter;
use RegistrarAPI\Core\Http;
use RegistrarAPI\Core\DnsRecord;

class Dynadot extends BaseAdapter {
    protected string $brand='dynadot';
    private string $base;
    public function __construct(array $creds){ parent::__construct($creds); $this->base=$creds['base']??'https://api.dynadot.com/api3.json'; }
    private function url(string $cmd, array $params=[]): string {
        $p=array_merge(['key'=>$this->creds['api_key']??'','command'=>$cmd],$params); return $this->base.'?'.http_build_query($p);
    }
    public function checkAvailability(array $domains): array {
        [$code,$body,$err]=Http::get($this->url('search',['domain'=>implode(',', $domains)]));
        $j=json_decode($body,true)?:[]; $available=[];$unavailable=[];$invalid=[];
        foreach(($j['search']??[]) as $row){ $d=strtolower($row['domain']); if(($row['status']??'')==='available')$available[]=$d; elseif(($row['status']??'')==='invalid')$invalid[]=$d; else $unavailable[]=$d; }
        return ['ok'=>$code<400&&!$err,'available'=>$available,'unavailable'=>$unavailable,'invalid'=>$invalid,'raw'=>$j,'http'=>$code,'err'=>$err];
    }
    public function registerDomain(string $domain, array $opts): array {
        [$code,$body,$err]=Http::get($this->url('register',['domain'=>$domain,'duration'=>$opts['years']??1,'privacy'=>!empty($opts['privacy'])?'1':'0']));
        return ['ok'=>$code<400&&!$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }
    public function renewDomain(string $domain, int $years=1, array $opts=[]): array {
        [$code,$body,$err]=Http::get($this->url('renew',['domain'=>$domain,'duration'=>$years]));
        return ['ok'=>$code<400&&!$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }
    public function transferDomain(string $domain, array $opts): array {
        [$code,$body,$err]=Http::get($this->url('transfer',['domain'=>$domain,'epp_code'=>$opts['auth_code']??'']));
        return ['ok'=>$code<400&&!$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }
    public function getDomain(string $domain): array {
        [$code,$body,$err]=Http::get($this->url('get_domain_info',['domain'=>$domain]));
        return ['ok'=>$code<400&&!$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }
    public function getDNS(string $domain): array {
        [$code,$body,$err]=Http::get($this->url('get_dns',['domain'=>$domain]));
        $j=json_decode($body,true)?:[]; $out=[]; foreach(($j['records']??[]) as $r){ $out[]=(new DnsRecord($r['type'],$r['host'],$r['value'],(int)($r['ttl']??3600),$r['prio']??null))->toArray(); }
        return ['ok'=>$code<400&&!$err,'records'=>$out,'raw'=>$j,'http'=>$code,'err'=>$err];
    }
    public function setDNS(string $domain, array $records): array {
        $ok=true;$raw=[]; foreach($records as $r){ $raw[]=$this->addDNS($domain,$r); $ok=$ok && (end($raw)['ok']??false); } return ['ok'=>$ok,'raw'=>$raw];
    }
    public function addDNS(string $domain, array $record): array {
        [$code,$body,$err]=Http::get($this->url('set_dns',['domain'=>$domain,'record'=>json_encode([$record])]));
        return ['ok'=>$code<400&&!$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }
    public function delDNS(string $domain, array $selector): array {
        return ['ok'=>false,'err'=>'Dynadot delete DNS not implemented in this demo'];
    }
    public function setNameServers(string $domain, array $nameservers): array {
        [$code,$body,$err]=Http::get($this->url('set_ns',['domain'=>$domain,'ns'=>implode(',', $nameservers)]));
        return ['ok'=>$code<400&&!$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }
    public function raw(string $op, array $params=[]): array {
        [$code,$body,$err]=Http::get($this->url($op,$params)); return ['ok'=>$code<400&&!$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }
}
