<?php
/**
 * RegistrarAPI.php
 * Unified PHP client for multiple domain registrars (NameSilo, GoDaddy, Namecheap, Dynadot).
 * PHP 7.4+ recommended.
 *
 * Idea designed by: https://github.com/josuamarcelc/
 *
 * Usage:
 *   $api = RegistrarAPI::make('namesilo', ['api_key' => 'XXXX']);
 *   $avail = $api->checkAvailability(['example.com','mybrand.io']);
 *   $reg   = $api->registerDomain('example.com', [
 *               'years'=>1, 'privacy'=>true, 'contacts'=>[ ... ],
 *               // brand-specific opts are allowed; unknown keys ignored by others
 *           ]);
 *
 * To add a registrar: extend RegistrarAdapter and implement the abstract methods.
 */

/////////////////////////////
// Basic HTTP client (cURL)
/////////////////////////////
final class Http {
    public static function get(string $url, array $headers=[], int $timeout=20): array {
        return self::req('GET', $url, null, $headers, $timeout);
    }
    public static function delete(string $url, array $headers=[], int $timeout=20): array {
        return self::req('DELETE', $url, null, $headers, $timeout);
    }
    public static function postJson(string $url, array $json, array $headers=[], int $timeout=30): array {
        $headers[] = 'Content-Type: application/json';
        return self::req('POST', $url, json_encode($json), $headers, $timeout);
    }
    public static function putJson(string $url, array $json, array $headers=[], int $timeout=30): array {
        $headers[] = 'Content-Type: application/json';
        return self::req('PUT', $url, json_encode($json), $headers, $timeout);
    }
    public static function postForm(string $url, array $form, array $headers=[], int $timeout=30): array {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        return self::req('POST', $url, http_build_query($form), $headers, $timeout);
    }
    private static function req(string $method, string $url, ?string $body, array $headers, int $timeout): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, $resp, $err];
    }
}

////////////////////////////////////
// Normalized record & contact types
////////////////////////////////////
final class DnsRecord {
    // type: A/AAAA/CNAME/TXT/MX/NS/SRV/CAA/ALIAS/URL/… (registrar-dependent)
    public string $type;
    public string $host;   // relative or absolute; adapters normalize
    public string $value;  // target/value
    public int    $ttl;    // seconds
    public ?int   $prio;   // for MX/SRV/CAA where relevant

    public function __construct(string $type, string $host, string $value, int $ttl=3600, ?int $prio=null) {
        $this->type=$type; $this->host=$host; $this->value=$value; $this->ttl=$ttl; $this->prio=$prio;
    }
    public function toArray(): array { return [
        'type'=>$this->type, 'host'=>$this->host, 'value'=>$this->value, 'ttl'=>$this->ttl, 'prio'=>$this->prio
    ]; }
}
final class Contacts {
    // All registrars need at least registrant; many reuse across admin/tech/billing
    public array $registrant; public array $admin; public array $tech; public array $billing;
    public function __construct(array $registrant, ?array $admin=null, ?array $tech=null, ?array $billing=null) {
        $this->registrant=$registrant;
        $this->admin   =$admin   ?: $registrant;
        $this->tech    =$tech    ?: $registrant;
        $this->billing =$billing ?: $registrant;
    }
    public function toArray(): array {
        return ['registrant'=>$this->registrant,'admin'=>$this->admin,'tech'=>$this->tech,'billing'=>$this->billing];
    }
}

////////////////////////////////////
// Abstract adapter & factory
////////////////////////////////////
abstract class RegistrarAdapter {
    protected string $brand;
    protected array $creds;
    public function __construct(array $creds) { $this->creds=$creds; }
    public function brand(): string { return $this->brand; }

    // Normalized interface
    abstract public function checkAvailability(array $domains): array;           // ['available'=>[], 'unavailable'=>[], 'invalid'=>[]]
    abstract public function registerDomain(string $domain, array $opts): array; // ['ok'=>bool, 'order_id'=>..., 'raw'=>...]
    abstract public function renewDomain(string $domain, int $years=1, array $opts=[]): array;
    abstract public function transferDomain(string $domain, array $opts): array;
    abstract public function getDomain(string $domain): array;                   // whois-ish + status
    abstract public function getDNS(string $domain): array;                      // ['ok'=>bool, 'records'=>[DnsRecord...]]
    abstract public function setDNS(string $domain, array $records): array;      // full replace where possible
    abstract public function addDNS(string $domain, array $record): array;
    abstract public function delDNS(string $domain, array $selector): array;     // ['type'=>'A','host'=>'@','value'=>'1.2.3.4'] minimal selector

    // Escape hatch for brand-specific operations (pass-through)
    abstract public function raw(string $op, array $params=[]): array;

    ///////////////////////
    // Helper: JSON decode
    protected function json(string $body): array {
        $j = json_decode($body, true);
        return $j===null ? ['_parse_error'=>true,'_raw'=>$body] : $j;
    }
}

final class RegistrarAPI {
    public static function make(string $brand, array $creds): RegistrarAdapter {
        $key = strtolower(trim($brand));
        return match ($key) {
            'namesilo' => new NameSiloAdapter($creds),
            'godaddy'  => new GoDaddyAdapter($creds),
            'namecheap'=> new NamecheapAdapter($creds),
            'dynadot'  => new DynadotAdapter($creds),
            default    => throw new InvalidArgumentException("Unknown registrar brand: $brand"),
        };
    }
}

/////////////////////////////
// NameSilo adapter
/////////////////////////////
final class NameSiloAdapter extends RegistrarAdapter {
    protected string $brand='namesilo';
    private string $base = 'https://www.namesilo.com/api';

    private function buildUrl(string $op, array $params=[]): string {
        $params = array_merge([
            'version'=>'1',
            'type'=>'json',
            'key'=>$this->creds['api_key'] ?? ''
        ], $params);
        return $this->base . '/' . $op . '?' . http_build_query($params);
    }

    public function checkAvailability(array $domains): array {
        $url = $this->buildUrl('checkRegisterAvailability', ['domains'=>implode(',', $domains)]);
        [$code,$body,$err] = Http::get($url);
        $j = $this->json($body);
        $r = $j['reply'] ?? [];
        $toArr = fn($v)=>is_array($v)?array_values($v):($v?[$v]:[]);
        $available   = $toArr($r['available']['domain']   ?? $r['available']   ?? []);
        $unavailable = $toArr($r['unavailable']['domain'] ?? $r['unavailable'] ?? []);
        $invalid     = $toArr($r['invalid']['domain']     ?? $r['invalid']     ?? []);
        return ['ok'=>$code<400 && !$err, 'available'=>$available, 'unavailable'=>$unavailable, 'invalid'=>$invalid, 'raw'=>$j, 'http'=>$code, 'err'=>$err];
    }

    public function registerDomain(string $domain, array $opts): array {
        // Minimal: years, privacy, auto_renew, contacts
        $params = [
            'domain'    => $domain,
            'years'     => $opts['years']   ?? 1,
            'private'   => ($opts['privacy'] ?? true) ? '1':'0',
            'auto_renew'=> ($opts['auto_renew'] ?? false) ? '1':'0',
        ];
        if (!empty($opts['coupon'])) $params['coupon'] = $opts['coupon'];
        // Contacts (NameSilo uses contact IDs; to keep it simple, accept raw fields when using "registerDomain" with contact details)
        // See NameSilo contacts.create if you want to precreate; here we map basic registrant fields inline when supported.
        foreach (['fn'=>'first_name','ln'=>'last_name','ad'=>'address','cy'=>'city','st'=>'state','zp'=>'zip','ct'=>'country','em'=>'email','ph'=>'phone'] as $short=>$key) {
            if (isset($opts['registrant'][$key])) $params["rr_$short"] = $opts['registrant'][$key];
        }
        $url = $this->buildUrl('registerDomain', $params);
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err];
    }

    public function renewDomain(string $domain, int $years=1, array $opts=[]): array {
        $url = $this->buildUrl('renewDomain', ['domain'=>$domain,'years'=>$years]);
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err];
    }

    public function transferDomain(string $domain, array $opts): array {
        $url = $this->buildUrl('transferDomain', [
            'domain'=>$domain,
            'auth'=>$opts['auth_code'] ?? ''
        ]);
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err];
    }

    public function getDomain(string $domain): array {
        $url = $this->buildUrl('getDomainInfo', ['domain'=>$domain]);
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err];
    }

    public function getDNS(string $domain): array {
        $url = $this->buildUrl('dnsListRecords', ['domain'=>$domain]);
        [$code,$body,$err] = Http::get($url);
        $j = $this->json($body);
        $recs=[];
        foreach (($j['reply']['resource_record'] ?? []) as $r) {
            $recs[] = (new DnsRecord($r['type'],$r['host'],$r['value'], (int)($r['ttl']??3600), isset($r['distance'])?(int)$r['distance']:null))->toArray();
        }
        return ['ok'=>$code<400 && !$err, 'records'=>$recs, 'raw'=>$j, 'http'=>$code, 'err'=>$err];
    }

    public function setDNS(string $domain, array $records): array {
        // NameSilo has "dnsDeleteRecord" + "dnsAddRecord"; some accounts have "dnsUpdateRecord".
        // Strategy: delete all then add all (be careful in production).
        $cur = $this->getDNS($domain);
        if (!empty($cur['records'])) {
            foreach ($cur['records'] as $r) {
                $this->delDNS($domain, ['record_id'=>$r['record_id'] ?? null, 'host'=>$r['host'], 'type'=>$r['type'], 'value'=>$r['value']]);
            }
        }
        $ok=true; $raw=[];
        foreach ($records as $r) {
            $res = $this->addDNS($domain, $r);
            $ok = $ok && !empty($res['ok']);
            $raw[]=$res;
        }
        return ['ok'=>$ok,'raw'=>$raw];
    }

    public function addDNS(string $domain, array $record): array {
        $q = [
            'domain'=>$domain,
            'rrtype'=>$record['type'],
            'rrhost'=>$record['host'],
            'rrvalue'=>$record['value'],
            'rrttl'  =>$record['ttl'] ?? 3600
        ];
        if (isset($record['prio'])) $q['rrdistance']=(int)$record['prio'];
        $url = $this->buildUrl('dnsAddRecord', $q);
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err];
    }

    public function delDNS(string $domain, array $selector): array {
        // Prefer record_id if you have it; otherwise namesilo requires value matching.
        $url = $this->buildUrl('dnsDeleteRecord', [
            'domain'    =>$domain,
            'rrid'      =>$selector['record_id'] ?? '',
        ]);
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err];
    }

    public function raw(string $op, array $params=[]): array {
        $url = $this->buildUrl($op, $params);
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err, 'endpoint'=>$url];
    }
}

/////////////////////////////
// GoDaddy adapter (REST)
/////////////////////////////
final class GoDaddyAdapter extends RegistrarAdapter {
    protected string $brand='godaddy';
    private string $base;

    public function __construct(array $creds) {
        parent::__construct($creds);
        $this->base = $creds['base'] ?? 'https://api.godaddy.com/v1';
    }
    private function auth(): array {
        $key = $this->creds['api_key'] ?? '';
        $sec = $this->creds['api_secret'] ?? ($this->creds['api_token'] ?? '');
        return ["Authorization: sso-key {$key}:{$sec}", 'Accept: application/json'];
    }

    public function checkAvailability(array $domains): array {
        // GoDaddy supports batch availability in v1/domains/available?domain=...&domain=...
        $qs = [];
        foreach ($domains as $d) $qs[]='domain='.rawurlencode($d);
        $url = $this->base . '/domains/available?' . implode('&', $qs);
        [$code,$body,$err] = Http::get($url, $this->auth());
        $j = $this->json($body);
        $available=[]; $unavailable=[]; $invalid=[];
        $items = $j['domains'] ?? (isset($j['available']) ? [ $j ] : []); // handle single
        foreach ($items as $it) {
            $d = strtolower($it['domain']);
            if (!empty($it['available'])) $available[]=$d;
            elseif (!empty($it['definitive']) && $it['definitive']===false && isset($it['code']) && $it['code']==='INVALID') $invalid[]=$d;
            else $unavailable[]=$d;
        }
        return ['ok'=>$code<400 && !$err, 'available'=>$available,'unavailable'=>$unavailable,'invalid'=>$invalid, 'raw'=>$j, 'http'=>$code, 'err'=>$err];
    }

    public function registerDomain(string $domain, array $opts): array {
        $years = $opts['years'] ?? 1;
        $privacy = (bool)($opts['privacy'] ?? false);
        $auto = (bool)($opts['auto_renew'] ?? true);
        $contacts = $opts['contacts'] ?? ['registrant'=>$opts['registrant'] ?? []];
        $payload = [
            'consent' => [
                'agreedAt' => gmdate('c'),
                'agreedBy' => $opts['client_ip'] ?? '127.0.0.1',
                'agreementKeys' => ['DNRA']
            ],
            'contactAdmin'    => $contacts['admin']    ?? $contacts['registrant'],
            'contactBilling'  => $contacts['billing']  ?? $contacts['registrant'],
            'contactRegistrant'=> $contacts['registrant'],
            'contactTech'     => $contacts['tech']     ?? $contacts['registrant'],
            'domain' => $domain,
            'period' => $years,
            'privacy' => $privacy,
            'renewAuto' => $auto
        ];
        $url = $this->base.'/domains/purchases';
        [$code,$body,$err] = Http::postJson($url, ['items'=>[['domain'=>$domain,'period'=>$years,'privacy'=>$privacy]]]+$payload, $this->auth());
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err];
    }

    public function renewDomain(string $domain, int $years=1, array $opts=[]): array {
        $url = $this->base."/domains/{$domain}/renew";
        [$code,$body,$err] = Http::postJson($url, ['period'=>$years], $this->auth());
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err];
    }

    public function transferDomain(string $domain, array $opts): array {
        $url = $this->base."/domains/{$domain}/transfer";
        $payload = ['authCode'=>$opts['auth_code'] ?? ''];
        [$code,$body,$err] = Http::postJson($url, $payload, $this->auth());
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err];
    }

    public function getDomain(string $domain): array {
        $url = $this->base."/domains/{$domain}";
        [$code,$body,$err] = Http::get($url, $this->auth());
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err];
    }

    public function getDNS(string $domain): array {
        $url = $this->base."/domains/{$domain}/records";
        [$code,$body,$err] = Http::get($url, $this->auth());
        $j = $this->json($body);
        $out=[];
        foreach ($j as $r) $out[]=(new DnsRecord($r['type'],$r['name'],$r['data'], (int)($r['ttl'] ?? 3600)))->toArray();
        return ['ok'=>$code<400 && !$err, 'records'=>$out, 'raw'=>$j, 'http'=>$code, 'err'=>$err];
    }

    public function setDNS(string $domain, array $records): array {
        // Full replace
        $url = $this->base."/domains/{$domain}/records";
        $payload=[];
        foreach ($records as $r) {
            $payload[]=[
                'type'=>$r['type'],'name'=>$r['host'],'data'=>$r['value'],'ttl'=>$r['ttl'] ?? 3600
            ];
        }
        [$code,$body,$err] = Http::putJson($url, $payload, $this->auth());
        return ['ok'=>$code<400 && !$err, 'raw'=>$body ? $this->json($body) : [], 'http'=>$code, 'err'=>$err];
    }

    public function addDNS(string $domain, array $record): array {
        $url = $this->base."/domains/{$domain}/records";
        [$code,$body,$err] = Http::postJson($url, [[
            'type'=>$record['type'],'name'=>$record['host'],'data'=>$record['value'],'ttl'=>$record['ttl'] ?? 3600
        ]], $this->auth());
        return ['ok'=>$code<400 && !$err, 'raw'=>$body ? $this->json($body) : [], 'http'=>$code, 'err'=>$err];
    }

    public function delDNS(string $domain, array $selector): array {
        $type = $selector['type'] ?? 'A';
        $name = $selector['host'] ?? '@';
        $url = $this->base."/domains/{$domain}/records/{$type}/".rawurlencode($name);
        [$code,$body,$err] = Http::delete($url, $this->auth());
        return ['ok'=>$code<400 && !$err, 'raw'=>$body ? $this->json($body):[], 'http'=>$code, 'err'=>$err];
    }

    public function raw(string $op, array $params=[]): array {
        $url = rtrim($this->base,'/').'/'.ltrim($op,'/');
        [$code,$body,$err] = Http::get($url, $this->auth());
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err, 'endpoint'=>$url];
    }
}

/////////////////////////////
// Namecheap adapter
/////////////////////////////
final class NamecheapAdapter extends RegistrarAdapter {
    protected string $brand='namecheap';
    private string $base;

    public function __construct(array $creds) {
        parent::__construct($creds);
        // Sandbox: https://api.sandbox.namecheap.com/xml.response
        $this->base = $creds['base'] ?? 'https://api.namecheap.com/xml.response';
    }
    private function q(array $extra): string {
        $p = array_merge([
            'ApiUser'  => $this->creds['api_user'] ?? $this->creds['username'] ?? '',
            'ApiKey'   => $this->creds['api_key']  ?? '',
            'UserName' => $this->creds['username'] ?? ($this->creds['api_user'] ?? ''),
            'ClientIp' => $this->creds['client_ip'] ?? '127.0.0.1',
        ], $extra);
        return $this->base.'?'.http_build_query($p);
    }
    private function parseXml(string $xml): array {
        libxml_use_internal_errors(true);
        $s = simplexml_load_string($xml);
        if(!$s) return ['_parse_error'=>true,'_raw'=>$xml];
        return json_decode(json_encode($s), true);
    }

    public function checkAvailability(array $domains): array {
        $url = $this->q(['Command'=>'namecheap.domains.check','DomainList'=>implode(',', $domains)]);
        [$code,$body,$err] = Http::get($url);
        $j = $this->parseXml($body);
        $available=[];$unavailable=[];$invalid=[];
        foreach (($j['CommandResponse']['DomainCheckResult'] ?? []) as $row) {
            $d = strtolower($row['@attributes']['Domain'] ?? '');
            if (($row['@attributes']['Available'] ?? 'false')==='true') $available[]=$d;
            elseif (($row['@attributes']['IsValid'] ?? 'true')==='false') $invalid[]=$d;
            else $unavailable[]=$d;
        }
        return ['ok'=>$code<400 && !$err, 'available'=>$available,'unavailable'=>$unavailable,'invalid'=>$invalid, 'raw'=>$j, 'http'=>$code, 'err'=>$err];
    }

    public function registerDomain(string $domain, array $opts): array {
        $p = [
            'Command'  =>'namecheap.domains.create',
            'DomainName'=>$domain,
            'Years'    =>$opts['years'] ?? 1,
            'AddFreeWhoisguard'=>'yes',
            'WhoisGuard'=>'ENABLED',
        ];
        // contacts mapping (Registrant/Tech/Admin/Billing)
        $c = $opts['contacts']['registrant'] ?? $opts['registrant'] ?? [];
        foreach (['Registrant','Tech','Admin','AuxBilling'] as $role) {
            foreach (['FirstName','LastName','Address1','City','StateProvince','PostalCode','Country','Phone','EmailAddress','OrganizationName'] as $f) {
                $key = $role.$f;
                $src = $opts['contacts'][strtolower($role)][$f] ?? $c[$f] ?? null;
                if ($src) $p[$key]=$src;
            }
        }
        $url = $this->q($p);
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->parseXml($body), 'http'=>$code, 'err'=>$err];
    }

    public function renewDomain(string $domain, int $years=1, array $opts=[]): array {
        $url = $this->q(['Command'=>'namecheap.domains.renew','DomainName'=>$domain,'Years'=>$years]);
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->parseXml($body), 'http'=>$code, 'err'=>$err];
    }

    public function transferDomain(string $domain, array $opts): array {
        $url = $this->q([
            'Command'=>'namecheap.domains.transfer.create',
            'DomainName'=>$domain,
            'EPPCode'=>$opts['auth_code'] ?? ''
        ]);
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->parseXml($body), 'http'=>$code, 'err'=>$err];
    }

    public function getDomain(string $domain): array {
        $url = $this->q(['Command'=>'namecheap.domains.getInfo','DomainName'=>$domain,'HostName'=>$domain]);
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->parseXml($body), 'http'=>$code, 'err'=>$err];
    }

    public function getDNS(string $domain): array {
        $url = $this->q(['Command'=>'namecheap.domains.dns.getHosts','SLD'=>explode('.', $domain)[0],'TLD'=>substr($domain, strpos($domain,'.')+1)]);
        [$code,$body,$err] = Http::get($url);
        $j = $this->parseXml($body);
        $hosts = $j['CommandResponse']['DomainDNSGetHostsResult']['host'] ?? [];
        if (isset($hosts['@attributes'])) $hosts = [$hosts]; // normalize single
        $out=[];
        foreach ($hosts as $h) {
            $a = $h['@attributes'];
            $out[]=(new DnsRecord($a['Type'],$a['Name'],$a['Address'], (int)($a['TTL'] ?? 3600), isset($a['MXPref'])?(int)$a['MXPref']:null))->toArray();
        }
        return ['ok'=>$code<400 && !$err, 'records'=>$out, 'raw'=>$j, 'http'=>$code, 'err'=>$err];
    }

    public function setDNS(string $domain, array $records): array {
        $p = ['Command'=>'namecheap.domains.dns.setHosts','SLD'=>explode('.', $domain)[0],'TLD'=>substr($domain, strpos($domain,'.')+1)];
        $i=1;
        foreach ($records as $r) {
            $p["HostName{$i}"]=$r['host']; $p["RecordType{$i}"]=$r['type']; $p["Address{$i}"]=$r['value'];
            $p["TTL{$i}"]=$r['ttl'] ?? 3600; if(isset($r['prio'])) $p["MXPref{$i}"]=(int)$r['prio']; $i++;
        }
        $url = $this->q($p);
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->parseXml($body), 'http'=>$code, 'err'=>$err];
    }

    public function addDNS(string $domain, array $record): array {
        $cur = $this->getDNS($domain);
        $recs = $cur['records'] ?? [];
        $recs[] = $record;
        return $this->setDNS($domain, $recs);
    }

    public function delDNS(string $domain, array $selector): array {
        $cur = $this->getDNS($domain);
        $recs = array_values(array_filter($cur['records'] ?? [], function($r) use($selector){
            foreach (['type','host','value'] as $k) if(isset($selector[$k]) && $selector[$k]!==$r[$k]) return true;
            return false;
        }));
        return $this->setDNS($domain, $recs);
    }

    public function raw(string $op, array $params=[]): array {
        // $op must be a full Command string, e.g. "namecheap.users.getBalances"
        $url = $this->q(array_merge(['Command'=>$op], $params));
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->parseXml($body), 'http'=>$code, 'err'=>$err, 'endpoint'=>$url];
    }
}

/////////////////////////////
// Dynadot adapter
/////////////////////////////
final class DynadotAdapter extends RegistrarAdapter {
    protected string $brand='dynadot';
    private string $base;

    public function __construct(array $creds) {
        parent::__construct($creds);
        $this->base = $creds['base'] ?? 'https://api.dynadot.com/api3.json';
    }
    private function url(string $cmd, array $params=[]): string {
        $p = array_merge(['key'=>$this->creds['api_key'] ?? '', 'command'=>$cmd], $params);
        return $this->base.'?'.http_build_query($p);
    }

    public function checkAvailability(array $domains): array {
        $url = $this->url('search', ['domain'=>implode(',', $domains)]);
        [$code,$body,$err] = Http::get($url);
        $j = json_decode($body, true) ?: [];
        $available=[];$unavailable=[];$invalid=[];
        foreach (($j['search'] ?? []) as $row) {
            $d=strtolower($row['domain']);
            if (($row['status'] ?? '')==='available') $available[]=$d;
            elseif (($row['status'] ?? '')==='invalid') $invalid[]=$d;
            else $unavailable[]=$d;
        }
        return ['ok'=>$code<400 && !$err,'available'=>$available,'unavailable'=>$unavailable,'invalid'=>$invalid,'raw'=>$j,'http'=>$code,'err'=>$err];
    }

    public function registerDomain(string $domain, array $opts): array {
        $url = $this->url('register', [
            'domain'=>$domain,
            'duration'=>$opts['years'] ?? 1,
            'privacy'=>!empty($opts['privacy']) ? '1':'0',
        ]);
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }

    public function renewDomain(string $domain, int $years=1, array $opts=[]): array {
        $url = $this->url('renew', ['domain'=>$domain,'duration'=>$years]);
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }

    public function transferDomain(string $domain, array $opts): array {
        $url = $this->url('transfer', ['domain'=>$domain,'epp_code'=>$opts['auth_code'] ?? '']);
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }

    public function getDomain(string $domain): array {
        $url = $this->url('get_domain_info', ['domain'=>$domain]);
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }

    public function getDNS(string $domain): array {
        $url = $this->url('get_dns', ['domain'=>$domain]);
        [$code,$body,$err] = Http::get($url);
        $j = json_decode($body,true) ?: [];
        $out=[];
        foreach (($j['records'] ?? []) as $r) {
            $out[]=(new DnsRecord($r['type'],$r['host'],$r['value'], (int)($r['ttl'] ?? 3600), $r['prio'] ?? null))->toArray();
        }
        return ['ok'=>$code<400 && !$err, 'records'=>$out, 'raw'=>$j, 'http'=>$code, 'err'=>$err];
    }

    public function setDNS(string $domain, array $records): array {
        // Dynadot uses separate endpoints per type in v3; for demo, clear+add
        $cur = $this->getDNS($domain);
        foreach ($cur['records'] ?? [] as $r) { /* left as exercise if delete endpoint is available */ }
        $ok=true; $raw=[];
        foreach ($records as $r) { $raw[]=$this->addDNS($domain,$r); $ok=$ok && end($raw)['ok']; }
        return ['ok'=>$ok,'raw'=>$raw];
    }

    public function addDNS(string $domain, array $record): array {
        $url = $this->url('set_dns', [ // example endpoint; adjust per type if needed
            'domain'=>$domain, 'record'=>json_encode([$record])
        ]);
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }

    public function delDNS(string $domain, array $selector): array {
        // If API exposes delete endpoint; placeholder:
        return ['ok'=>false,'err'=>'Dynadot delete DNS not implemented in this demo'];
    }

    public function raw(string $op, array $params=[]): array {
        $url = $this->url($op, $params);
        [$code,$body,$err] = Http::get($url);
        return ['ok'=>$code<400 && !$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err,'endpoint'=>$url];
    }
}
