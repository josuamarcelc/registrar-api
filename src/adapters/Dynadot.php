<?php
namespace RegistrarAPI\Adapters;

use RegistrarAPI\Core\BaseAdapter;
use RegistrarAPI\Core\Http;
use RegistrarAPI\Core\DnsRecord;

/** Dynadot API3 (api3.json). Every reply is {"<Command>Response": {"ResponseCode": 0|-1, "Status": ..., "Error": ...}}. */
class Dynadot extends BaseAdapter {
    protected string $brand='dynadot';
    private string $base;

    public function __construct(array $creds){
        parent::__construct($creds);
        $this->requireCreds(['api_key']);
        $this->base=$creds['base']??'https://api.dynadot.com/api3.json';
    }

    /** `body` holds the inner <Command>Response object. */
    private function call(string $cmd, array $params=[]): array {
        $url=$this->base.'?'.http_build_query(array_merge(['key'=>$this->creds['api_key'],'command'=>$cmd],$params));
        [$code,$body,$err]=Http::get($url, [], 60);
        $j=$this->json($body);
        $inner=[];
        foreach ($j as $k=>$v) { if (is_array($v) && substr((string)$k,-8)==='Response') { $inner=$v; break; } }
        $rc=$inner['ResponseCode'] ?? ($inner['SuccessCode'] ?? null);
        $ok=$code>0 && $code<400 && !$err && $rc!==null && (string)$rc==='0' && strtolower((string)($inner['Status'] ?? 'success'))!=='error';
        if (!$ok && !$err) $err=(string)($inner['Error'] ?? ($code>=400 ? "HTTP {$code}" : 'unexpected response'));
        return $this->result($ok,$j,$code,$err,['body'=>$inner]);
    }

    private static function strip(array $r): array { unset($r['body']); return $r; }

    public function checkAvailability(array $domains): array {
        $params=[]; foreach (array_values($domains) as $i=>$d) $params['domain'.$i]=$d;
        $r=$this->call('search',$params);
        $available=[];$unavailable=[];$invalid=[];
        foreach (self::listOf($r['body']['SearchResults'] ?? []) as $row) {
            $d=strtolower($row['DomainName'] ?? '');
            $a=strtolower((string)($row['Available'] ?? ''));
            if ($a==='yes') $available[]=$d; elseif ($a==='no') $unavailable[]=$d; else $invalid[]=$d;
        }
        return self::strip($r)+['available'=>$available,'unavailable'=>$unavailable,'invalid'=>$invalid];
    }

    public function registerDomain(string $domain, array $opts): array {
        $params=['domain'=>$domain,'duration'=>(int)($opts['years']??1)];
        if (isset($opts['currency'])) $params['currency']=$opts['currency'];
        if (isset($opts['coupon']))   $params['coupon']=$opts['coupon'];
        // Dynadot uses contact ids from your account: create them once with raw('create_contact', ...).
        foreach (['registrant','admin','technical','billing'] as $k) {
            if (!empty($opts['contact_ids'][$k])) $params[$k.'_contact']=$opts['contact_ids'][$k];
        }
        return self::strip($this->call('register',$params));
    }

    public function renewDomain(string $domain, int $years=1, array $opts=[]): array {
        return self::strip($this->call('renew',['domain'=>$domain,'duration'=>$years]));
    }

    public function transferDomain(string $domain, array $opts): array {
        return self::strip($this->call('transfer',['domain'=>$domain,'auth'=>$opts['auth_code']??'']));
    }

    public function getDomain(string $domain): array {
        return self::strip($this->call('domain_info',['domain'=>$domain]));
    }

    public function getDNS(string $domain): array {
        $r=$this->call('get_dns',['domain'=>$domain]);
        $ns=$r['body']['GetDns']['NameServerSettings'] ?? [];
        $ttl=(int)($ns['TTL'] ?? 3600);
        $out=[];
        foreach (self::listOf($ns['MainDomains'] ?? []) as $x) {
            $out[]=self::record($x['RecordType'] ?? '', '@', $x, $ttl);
        }
        foreach (self::listOf($ns['SubDomains'] ?? []) as $x) {
            $out[]=self::record($x['RecordType'] ?? '', (string)($x['Subhost'] ?? ''), $x, $ttl);
        }
        return self::strip($r)+['records'=>$out];
    }

    private static function record(string $type, string $host, array $x, int $ttl): array {
        $prio = isset($x['Value2']) && $x['Value2']!=='' && strtoupper($type)==='MX' ? (int)$x['Value2'] : null;
        return (new DnsRecord($type, $host ?: '@', (string)($x['Value'] ?? ''), $ttl, $prio))->toArray();
    }

    /** Builds set_dns2 parameters. Dynadot applies one TTL to the whole zone (the first record's). */
    private function dnsParams(string $domain, array $records, bool $append): array {
        $p=['domain'=>$domain]; $m=0; $s=0;
        foreach ($records as $r) {
            $type=strtolower($r['type']); $host=DnsRecord::relativeHost($r['host'] ?? '@', $domain);
            if ($host==='@') {
                $p["main_record_type{$m}"]=$type; $p["main_record{$m}"]=$r['value'];
                if (isset($r['prio'])) $p["main_recordx{$m}"]=(int)$r['prio'];
                $m++;
            } else {
                $p["subdomain{$s}"]=$host; $p["sub_record_type{$s}"]=$type; $p["sub_record{$s}"]=$r['value'];
                if (isset($r['prio'])) $p["sub_recordx{$s}"]=(int)$r['prio'];
                $s++;
            }
            if (!isset($p['ttl']) && isset($r['ttl'])) $p['ttl']=(int)$r['ttl'];
        }
        if ($append) $p['add_dns_to_current_setting']='1';
        return $p;
    }

    /** Replaces the whole zone. */
    public function setDNS(string $domain, array $records): array {
        if (!$records) return $this->result(false,null,0,'dynadot: setDNS needs at least one record (use raw() to clear DNS)');
        return self::strip($this->call('set_dns2',$this->dnsParams($domain,$records,false)));
    }

    public function addDNS(string $domain, array $record): array {
        return self::strip($this->call('set_dns2',$this->dnsParams($domain,[$record],true)));
    }

    /** Selector: any of type/host/value/prio. Read-modify-write, because Dynadot has no per-record delete. */
    public function delDNS(string $domain, array $selector): array {
        if (!array_intersect_key($selector, ['type'=>1,'host'=>1,'value'=>1])) return $this->result(false,null,0,'dynadot: delDNS needs a type/host/value selector');
        if (isset($selector['host'])) $selector['host']=DnsRecord::relativeHost($selector['host'],$domain);
        $cur=$this->getDNS($domain);
        if (!$cur['ok']) return $cur;
        $keep=array_values(array_filter($cur['records'], function ($r) use ($selector) { return !DnsRecord::matches($r,$selector); }));
        $deleted=count($cur['records'])-count($keep);
        if ($deleted===0) return $this->result(true,null,0,'',['deleted'=>0]);
        if (!$keep) return $this->result(false,null,0,'dynadot: refusing to delete the last DNS record; use setDNS or raw()',['deleted'=>0]);
        return $this->setDNS($domain,$keep)+['deleted'=>$deleted];
    }

    public function setNameServers(string $domain, array $nameservers): array {
        $params=['domain'=>$domain]; foreach (array_values($nameservers) as $i=>$ns) $params['ns'.$i]=$ns;
        return self::strip($this->call('set_ns',$params));
    }

    /** $op is any API3 command, e.g. raw('get_contact', ['contact_id'=>123]). */
    public function raw(string $op, array $params=[]): array {
        return self::strip($this->call($op,$params));
    }
}
