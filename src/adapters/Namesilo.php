<?php
namespace RegistrarAPI\Adapters;

use RegistrarAPI\Core\BaseAdapter;
use RegistrarAPI\Core\Http;
use RegistrarAPI\Core\DnsRecord;

class Namesilo extends BaseAdapter {
    protected string $brand='namesilo';
    private string $base;

    /** NameSilo reply codes that mean the operation went through (301/302 = done, with a warning). */
    private const OK_CODES = [300, 301, 302];

    public function __construct(array $creds) {
        parent::__construct($creds);
        $this->requireCreds(['api_key']);
        $this->base = rtrim($creds['base'] ?? (!empty($creds['sandbox']) ? 'https://sandbox.namesilo.com/api' : 'https://www.namesilo.com/api'), '/');
    }

    private function buildUrl(string $op, array $params=[]): string {
        $params = array_merge(['version'=>'1', 'type'=>'json', 'key'=>$this->creds['api_key']], $params);
        return $this->base . '/' . $op . '?' . http_build_query($params);
    }

    /** Runs one API operation and returns the standard result; `reply` holds NameSilo's reply block. */
    private function call(string $op, array $params=[]): array {
        [$code,$body,$err] = Http::get($this->buildUrl($op, $params));
        $j = $this->json($body);
        $reply = $j['reply'] ?? [];
        $rc = (int)($reply['code'] ?? 0);
        $ok = $code>0 && $code<400 && !$err && in_array($rc, self::OK_CODES, true);
        if (!$ok && !$err) $err = (string)($reply['detail'] ?? ($code>=400 ? "HTTP {$code}" : 'unexpected response'));
        return $this->result($ok, $j, $code, $err, ['reply'=>$reply]);
    }

    private static function domainNames($v): array {
        if (is_array($v) && isset($v['domain'])) $v = $v['domain'];
        $out = [];
        foreach (self::listOf($v) as $it) { $d = is_array($it) ? ($it['domain'] ?? '') : $it; if ($d !== '') $out[] = strtolower((string)$d); }
        return $out;
    }

    public function checkAvailability(array $domains): array {
        $r = $this->call('checkRegisterAvailability', ['domains'=>implode(',', $domains)]);
        $reply = $r['reply']; unset($r['reply']);
        return $r + [
            'available'   => self::domainNames($reply['available']   ?? []),
            'unavailable' => self::domainNames($reply['unavailable'] ?? []),
            'invalid'     => self::domainNames($reply['invalid']     ?? []),
        ];
    }

    public function registerDomain(string $domain, array $opts): array {
        $params = [
            'domain'=>$domain,'years'=>$opts['years'] ?? 1,
            'private'=>($opts['privacy'] ?? true) ? '1':'0',
            'auto_renew'=>($opts['auto_renew'] ?? false) ? '1':'0',
        ];
        if (!empty($opts['coupon']))     $params['coupon'] = $opts['coupon'];
        if (!empty($opts['contact_id'])) $params['contact_id'] = $opts['contact_id'];
        foreach (['fn'=>'first_name','ln'=>'last_name','cp'=>'company','ad'=>'address','ad2'=>'address2','cy'=>'city','st'=>'state','zp'=>'zip','ct'=>'country','em'=>'email','ph'=>'phone'] as $short=>$key) {
            if (isset($opts['registrant'][$key])) $params[$short] = $opts['registrant'][$key];
        }
        foreach (array_values($opts['nameservers'] ?? []) as $i=>$ns) $params['ns'.($i+1)] = $ns;
        return $this->strip($this->call('registerDomain', $params));
    }

    public function renewDomain(string $domain, int $years=1, array $opts=[]): array {
        return $this->strip($this->call('renewDomain', ['domain'=>$domain,'years'=>$years]));
    }

    public function transferDomain(string $domain, array $opts): array {
        $params = ['domain'=>$domain,'auth'=>$opts['auth_code'] ?? ''];
        if (isset($opts['privacy']))    $params['private'] = $opts['privacy'] ? '1' : '0';
        if (isset($opts['auto_renew'])) $params['auto_renew'] = $opts['auto_renew'] ? '1' : '0';
        return $this->strip($this->call('transferDomain', $params));
    }

    public function getDomain(string $domain): array {
        return $this->strip($this->call('getDomainInfo', ['domain'=>$domain]));
    }

    public function getDNS(string $domain): array {
        $r = $this->call('dnsListRecords', ['domain'=>$domain]);
        $recs = [];
        foreach (self::listOf($r['reply']['resource_record'] ?? []) as $x) {
            $recs[] = (new DnsRecord($x['type'], DnsRecord::relativeHost((string)$x['host'], $domain), (string)$x['value'], (int)($x['ttl'] ?? 3600),
                (isset($x['distance']) && $x['distance'] !== '') ? (int)$x['distance'] : null, isset($x['record_id']) ? (string)$x['record_id'] : null))->toArray();
        }
        return $this->strip($r) + ['records'=>$recs];
    }

    /** Replaces the whole zone: deletes every existing record, then adds $records. */
    public function setDNS(string $domain, array $records): array {
        $cur = $this->getDNS($domain);
        if (!$cur['ok']) return $cur;
        $ok=true; $raw=[];
        foreach ($cur['records'] as $r) { $res=$this->delDNS($domain, ['id'=>$r['id']]); $ok=$ok && $res['ok']; $raw[]=$res; }
        foreach ($records as $r) { $res=$this->addDNS($domain, $r); $ok=$ok && $res['ok']; $raw[]=$res; }
        return $this->result($ok, $raw);
    }

    public function addDNS(string $domain, array $record): array {
        $host = DnsRecord::relativeHost($record['host'] ?? '@', $domain);
        $q = ['domain'=>$domain,'rrtype'=>strtoupper($record['type']),'rrhost'=>$host==='@' ? '' : $host,'rrvalue'=>$record['value'],'rrttl'=>$record['ttl'] ?? 3600];
        if (isset($record['prio'])) $q['rrdistance']=(int)$record['prio'];
        $r = $this->call('dnsAddRecord', $q);
        return $this->strip($r) + ['id'=>isset($r['reply']['record_id']) ? (string)$r['reply']['record_id'] : null];
    }

    /** Selector: ['id'=>...] (alias 'record_id'), or any of type/host/value to delete every matching record. */
    public function delDNS(string $domain, array $selector): array {
        if (isset($selector['record_id']) && !isset($selector['id'])) $selector['id'] = $selector['record_id'];
        if (!empty($selector['id'])) return $this->strip($this->call('dnsDeleteRecord', ['domain'=>$domain,'rrid'=>$selector['id']]));
        if (isset($selector['host'])) $selector['host'] = DnsRecord::relativeHost($selector['host'], $domain);
        if (!array_intersect_key($selector, ['type'=>1,'host'=>1,'value'=>1])) return $this->result(false, null, 0, 'namesilo: delDNS needs an id or a type/host/value selector');
        $cur = $this->getDNS($domain);
        if (!$cur['ok']) return $cur;
        $ok=true; $raw=[]; $deleted=0;
        foreach ($cur['records'] as $r) {
            if (!DnsRecord::matches($r, $selector)) continue;
            $res = $this->delDNS($domain, ['id'=>$r['id']]); $ok=$ok && $res['ok']; $raw[]=$res; $deleted++;
        }
        return $this->result($ok, $raw, 0, '', ['deleted'=>$deleted]);
    }

    public function setNameServers(string $domain, array $nameservers): array {
        $params = ['domain'=>$domain];
        foreach (array_values($nameservers) as $i=>$ns) $params['ns'.($i+1)] = $ns;
        return $this->strip($this->call('changeNameServers', $params));
    }

    public function raw(string $op, array $params=[]): array {
        return $this->strip($this->call($op, $params));
    }

    private function strip(array $r): array { unset($r['reply']); return $r; }
}
