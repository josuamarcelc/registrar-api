<?php
namespace RegistrarAPI\adapters;

use RegistrarAPI\Core\BaseAdapter;
use RegistrarAPI\Core\Http;
use RegistrarAPI\Core\DnsRecord;

class GoDaddy extends BaseAdapter {
    protected string $brand='godaddy';
    private string $base;
    public function __construct(array $creds) { parent::__construct($creds); $this->base = $creds['base'] ?? 'https://api.godaddy.com/v1'; }
    private function auth(): array {
        $key = $this->creds['api_key'] ?? ''; $sec = $this->creds['api_secret'] ?? ($this->creds['api_token'] ?? '');
        return ["Authorization: sso-key {$key}:{$sec}", 'Accept: application/json'];
    }
    public function checkAvailability(array $domains): array {
        $qs=[]; foreach ($domains as $d) { $qs[]='domain='.rawurlencode($d); }
        [$code,$body,$err] = Http::get($this->base.'/domains/available?'.implode('&',$qs), $this->auth());
        $j=$this->json($body); $available=[];$unavailable=[];$invalid=[]; $items=$j['domains'] ?? (isset($j['available'])?[$j]:[]);
        foreach ($items as $it){ $d=strtolower($it['domain']); if(!empty($it['available']))$available[]=$d; elseif(!empty($it['code'])&&$it['code']==='INVALID')$invalid[]=$d; else $unavailable[]=$d; }
        return ['ok'=>$code<400&&!$err,'available'=>$available,'unavailable'=>$unavailable,'invalid'=>$invalid,'raw'=>$j,'http'=>$code,'err'=>$err];
    }
    public function registerDomain(string $domain, array $opts): array {
        $years=$opts['years']??1; $privacy=(bool)($opts['privacy']??false); $auto=(bool)($opts['auto_renew']??true);
        $contacts=$opts['contacts']??['registrant'=>$opts['registrant']??[]];
        $payload=[
            'consent'=>['agreedAt'=>gmdate('c'),'agreedBy'=>$opts['client_ip']??'127.0.0.1','agreementKeys'=>['DNRA']],
            'contactAdmin'=>$contacts['admin']??$contacts['registrant'],
            'contactBilling'=>$contacts['billing']??$contacts['registrant'],
            'contactRegistrant'=>$contacts['registrant'],
            'contactTech'=>$contacts['tech']??$contacts['registrant'],
            'domain'=>$domain,'period'=>$years,'privacy'=>$privacy,'renewAuto'=>$auto
        ];
        [$code,$body,$err] = Http::postJson($this->base.'/domains/purchases', ['items'=>[['domain'=>$domain,'period'=>$years,'privacy'=>$privacy]]] + $payload, $this->auth());
        return ['ok'=>$code<400&&!$err,'raw'=>$this->json($body),'http'=>$code,'err'=>$err];
    }
    public function renewDomain(string $domain, int $years=1, array $opts=[]): array {
        [$code,$body,$err]=Http::postJson($this->base."/domains/{$domain}/renew", ['period'=>$years], $this->auth());
        return ['ok'=>$code<400&&!$err,'raw'=>$this->json($body),'http'=>$code,'err'=>$err];
    }
    public function transferDomain(string $domain, array $opts): array {
        [$code,$body,$err]=Http::postJson($this->base."/domains/{$domain}/transfer", ['authCode'=>$opts['auth_code']??''], $this->auth());
        return ['ok'=>$code<400&&!$err,'raw'=>$this->json($body),'http'=>$code,'err'=>$err];
    }
    public function getDomain(string $domain): array {
        [$code,$body,$err]=Http::get($this->base."/domains/{$domain}", $this->auth());
        return ['ok'=>$code<400&&!$err,'raw'=>$this->json($body),'http'=>$code,'err'=>$err];
    }
    public function getDNS(string $domain): array {
        [$code,$body,$err]=Http::get($this->base."/domains/{$domain}/records", $this->auth());
        $j=$this->json($body); $out=[]; foreach($j as $r){ $out[]=(new DnsRecord($r['type'],$r['name'],$r['data'],(int)($r['ttl']??3600)))->toArray(); }
        return ['ok'=>$code<400&&!$err,'records'=>$out,'raw'=>$j,'http'=>$code,'err'=>$err];
    }
    public function setDNS(string $domain, array $records): array {
        $payload=[]; foreach($records as $r){ $payload[]=['type'=>$r['type'],'name'=>$r['host'],'data'=>$r['value'],'ttl'=>$r['ttl']??3600]; }
        [$code,$body,$err]=Http::putJson($this->base."/domains/{$domain}/records",$payload,$this->auth());
        return ['ok'=>$code<400&&!$err,'raw'=>$body?$this->json($body):[],'http'=>$code,'err'=>$err];
    }
    public function addDNS(string $domain, array $record): array {
        [$code,$body,$err]=Http::postJson($this->base."/domains/{$domain}/records",[
            ['type'=>$record['type'],'name'=>$record['host'],'data'=>$record['value'],'ttl'=>$record['ttl']??3600]
        ],$this->auth());
        return ['ok'=>$code<400&&!$err,'raw'=>$body?$this->json($body):[],'http'=>$code,'err'=>$err];
    }
    public function delDNS(string $domain, array $selector): array {
        $type=$selector['type']??'A'; $name=$selector['host']??'@';
        [$code,$body,$err]=Http::delete($this->base."/domains/{$domain}/records/{$type}/".rawurlencode($name),$this->auth());
        return ['ok'=>$code<400&&!$err,'raw'=>$body?$this->json($body):[],'http'=>$code,'err'=>$err];
    }
    public function setNameServers(string $domain, array $nameservers): array {
        [$code,$body,$err]=Http::putJson($this->base."/domains/{$domain}/nameservers",$nameservers,$this->auth());
        return ['ok'=>$code<400&&!$err,'raw'=>$body?$this->json($body):[],'http'=>$code,'err'=>$err];
    }
    public function raw(string $op, array $params=[]): array {
        $url=rtrim($this->base,'/').'/'.ltrim($op,'/'); [$code,$body,$err]=Http::get($url,$this->auth());
        return ['ok'=>$code<400&&!$err,'raw'=>$this->json($body),'http'=>$code,'err'=>$err,'endpoint'=>$url];
    }
}
