<?php
namespace RegistrarAPI\Adapters;

use RegistrarAPI\Core\BaseAdapter;
use RegistrarAPI\Core\Http;
use RegistrarAPI\Core\DnsRecord;

class GoDaddy extends BaseAdapter {
    protected string $brand='godaddy';
    private string $base;

    public function __construct(array $creds) {
        parent::__construct($creds);
        if (!isset($this->creds['api_secret']) && isset($this->creds['api_token'])) $this->creds['api_secret'] = $this->creds['api_token'];
        $this->requireCreds(['api_key','api_secret']);
        $this->base = rtrim($creds['base'] ?? (!empty($creds['sandbox']) ? 'https://api.ote-godaddy.com/v1' : 'https://api.godaddy.com/v1'), '/');
    }

    private function auth(): array {
        return ["Authorization: sso-key {$this->creds['api_key']}:{$this->creds['api_secret']}", 'Accept: application/json'];
    }

    private function done(array $resp, array $extra=[]): array {
        [$code,$body,$err] = $resp;
        $j = $this->json($body);
        $ok = $code>0 && $code<400 && !$err;
        if (!$ok && !$err) $err = (string)($j['message'] ?? ($j['code'] ?? "HTTP {$code}"));
        return $this->result($ok, $j, $code, $err, $extra);
    }

    private function path(string $domain, string $rest=''): string {
        return $this->base.'/domains/'.rawurlencode($domain).$rest;
    }

    /** Single domain: GET /domains/available. Several: POST /domains/available (bulk, max 500). */
    public function checkAvailability(array $domains): array {
        $domains = array_values($domains);
        $resp = count($domains) === 1
            ? Http::get($this->base.'/domains/available?'.http_build_query(['domain'=>$domains[0],'checkType'=>'FAST']), $this->auth())
            : Http::postJson($this->base.'/domains/available?checkType=FAST', $domains, $this->auth());
        $r = $this->done($resp);
        $j = $r['raw']; $available=[];$unavailable=[];$invalid=[];
        $items = $j['domains'] ?? (isset($j['available']) ? [$j] : []);
        foreach ($items as $it) { $d=strtolower($it['domain']); if (!empty($it['available'])) $available[]=$d; else $unavailable[]=$d; }
        foreach (($j['errors'] ?? []) as $e) { if (isset($e['domain'])) $invalid[] = strtolower($e['domain']); }
        if (!$r['ok'] && count($domains) === 1 && ($j['code'] ?? '') !== '' && $r['http'] === 422) $invalid[] = strtolower($domains[0]);
        return $r + ['available'=>$available,'unavailable'=>$unavailable,'invalid'=>$invalid];
    }

    /** Legal agreements the buyer must consent to for this TLD (needed by purchase and transfer). */
    private function agreementKeys(string $domain, bool $privacy): array {
        $tld = substr($domain, strpos($domain, '.') + 1);
        $r = $this->done(Http::get($this->base.'/domains/agreements?'.http_build_query(['tlds'=>$tld,'privacy'=>$privacy ? 'true' : 'false']), $this->auth()));
        $keys = [];
        if ($r['ok']) foreach ($r['raw'] as $a) { if (!empty($a['agreementKey'])) $keys[] = $a['agreementKey']; }
        return $keys ?: ['DNRA'];
    }

    private function consent(string $domain, array $opts, bool $privacy): array {
        return [
            'agreedAt'      => gmdate('Y-m-d\TH:i:s\Z'),
            'agreedBy'      => $opts['client_ip'] ?? ($this->creds['client_ip'] ?? '127.0.0.1'),
            'agreementKeys' => $opts['agreement_keys'] ?? $this->agreementKeys($domain, $privacy),
        ];
    }

    /** opts: years, privacy, auto_renew, nameservers, contacts{registrant,admin,tech,billing} or registrant (GoDaddy contact shape). */
    public function registerDomain(string $domain, array $opts): array {
        $privacy=(bool)($opts['privacy']??false);
        $contacts=$opts['contacts']??['registrant'=>$opts['registrant']??[]];
        $reg=$contacts['registrant']??[];
        if (!$reg) return $this->result(false, null, 0, 'godaddy: registerDomain needs a registrant contact');
        $payload=[
            'consent'=>$this->consent($domain, $opts, $privacy),
            'contactAdmin'=>$contacts['admin']??$reg,
            'contactBilling'=>$contacts['billing']??$reg,
            'contactRegistrant'=>$reg,
            'contactTech'=>$contacts['tech']??$reg,
            'domain'=>$domain,'period'=>(int)($opts['years']??1),'privacy'=>$privacy,'renewAuto'=>(bool)($opts['auto_renew']??true),
        ];
        if (!empty($opts['nameservers'])) $payload['nameServers'] = array_values($opts['nameservers']);
        return $this->done(Http::postJson($this->base.'/domains/purchase', $payload, $this->auth()));
    }

    public function renewDomain(string $domain, int $years=1, array $opts=[]): array {
        return $this->done(Http::postJson($this->path($domain, '/renew'), ['period'=>$years], $this->auth()));
    }

    public function transferDomain(string $domain, array $opts): array {
        $privacy=(bool)($opts['privacy']??false);
        $payload=['authCode'=>$opts['auth_code']??'','consent'=>$this->consent($domain, $opts, $privacy),
                  'period'=>(int)($opts['years']??1),'privacy'=>$privacy,'renewAuto'=>(bool)($opts['auto_renew']??true)];
        foreach (['registrant'=>'contactRegistrant','admin'=>'contactAdmin','tech'=>'contactTech','billing'=>'contactBilling'] as $k=>$f) {
            if (!empty($opts['contacts'][$k])) $payload[$f] = $opts['contacts'][$k];
        }
        return $this->done(Http::postJson($this->path($domain, '/transfer'), $payload, $this->auth()));
    }

    public function getDomain(string $domain): array {
        return $this->done(Http::get($this->path($domain), $this->auth()));
    }

    public function getDNS(string $domain): array {
        $r = $this->done(Http::get($this->path($domain, '/records'), $this->auth()));
        $out=[];
        if ($r['ok'] && is_array($r['raw'])) foreach ($r['raw'] as $x) {
            if (!is_array($x) || !isset($x['type'])) continue;
            $out[]=(new DnsRecord($x['type'],$x['name'],(string)$x['data'],(int)($x['ttl']??3600),isset($x['priority'])?(int)$x['priority']:null))->toArray();
        }
        return $r + ['records'=>$out];
    }

    private function toGd(array $r, string $domain): array {
        $x=['type'=>strtoupper($r['type']),'name'=>DnsRecord::relativeHost($r['host'] ?? '@', $domain),'data'=>$r['value'],'ttl'=>(int)($r['ttl']??3600)];
        if (isset($r['prio'])) $x['priority']=(int)$r['prio'];
        return $x;
    }

    /** Replaces every record in the zone. */
    public function setDNS(string $domain, array $records): array {
        $payload=[]; foreach($records as $r){ $payload[]=$this->toGd($r, $domain); }
        return $this->done(Http::putJson($this->path($domain, '/records'), $payload, $this->auth()));
    }

    /** PATCH /records appends without touching existing records. */
    public function addDNS(string $domain, array $record): array {
        return $this->done(Http::patchJson($this->path($domain, '/records'), [$this->toGd($record, $domain)], $this->auth()));
    }

    /** Selector: type + host (GoDaddy deletes every record of that type/name). Add value to delete only matching records. */
    public function delDNS(string $domain, array $selector): array {
        $type=strtoupper($selector['type']??''); $name=DnsRecord::relativeHost($selector['host']??'@', $domain);
        if ($type==='') return $this->result(false, null, 0, 'godaddy: delDNS needs a type');
        if (!isset($selector['value'])) {
            return $this->done(Http::delete($this->path($domain, '/records/'.rawurlencode($type).'/'.rawurlencode($name)), $this->auth()));
        }
        // Keep the others of the same type/name: rewrite that rrset without the matching value(s).
        $cur = $this->getDNS($domain);
        if (!$cur['ok']) return $cur;
        $keep=[]; $deleted=0;
        foreach ($cur['records'] as $r) {
            if (strtoupper($r['type'])!==$type || strtolower($r['host'])!==strtolower($name)) continue;
            if (DnsRecord::matches($r, ['value'=>$selector['value']])) { $deleted++; continue; }
            $x=['data'=>$r['value'],'ttl'=>$r['ttl']]; if ($r['prio']!==null) $x['priority']=$r['prio']; $keep[]=$x;
        }
        if ($deleted===0) return $this->result(true, null, 0, '', ['deleted'=>0]);
        $resp = $keep
            ? Http::putJson($this->path($domain, '/records/'.rawurlencode($type).'/'.rawurlencode($name)), $keep, $this->auth())
            : Http::delete($this->path($domain, '/records/'.rawurlencode($type).'/'.rawurlencode($name)), $this->auth());
        return $this->done($resp, ['deleted'=>$deleted]);
    }

    public function setNameServers(string $domain, array $nameservers): array {
        return $this->done(Http::patchJson($this->path($domain), ['nameServers'=>array_values($nameservers)], $this->auth()));
    }

    /** $op is a path relative to the base (e.g. "domains/suggest?query=x"). params: method (GET|POST|PUT|PATCH|DELETE) and body. */
    public function raw(string $op, array $params=[]): array {
        $url=$this->base.'/'.ltrim($op,'/'); $method=strtoupper($params['method']??'GET'); $body=$params['body']??[];
        switch ($method) {
            case 'POST':   $resp=Http::postJson($url,$body,$this->auth()); break;
            case 'PUT':    $resp=Http::putJson($url,$body,$this->auth()); break;
            case 'PATCH':  $resp=Http::patchJson($url,$body,$this->auth()); break;
            case 'DELETE': $resp=Http::delete($url,$this->auth()); break;
            default:       $resp=Http::get($url,$this->auth());
        }
        return $this->done($resp, ['endpoint'=>$url]);
    }
}
