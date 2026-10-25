<?php
namespace RegistrarAPI\Adapters;

use RegistrarAPI\Core\BaseAdapter;
use RegistrarAPI\Core\Http;
use RegistrarAPI\Core\DnsRecord;

class Namecheap extends BaseAdapter {
    protected string $brand='namecheap';
    private string $base;

    /** Unified contact keys => Namecheap field suffixes. */
    private const CONTACT = [
        'first_name'=>'FirstName','last_name'=>'LastName','company'=>'OrganizationName','address'=>'Address1','address2'=>'Address2',
        'city'=>'City','state'=>'StateProvince','zip'=>'PostalCode','country'=>'Country','phone'=>'Phone','email'=>'EmailAddress',
    ];

    public function __construct(array $creds) {
        parent::__construct($creds);
        $this->requireCreds(['api_user','api_key','client_ip']);
        $this->base = $creds['base'] ?? (!empty($creds['sandbox']) ? 'https://api.sandbox.namecheap.com/xml.response' : 'https://api.namecheap.com/xml.response');
    }

    /** Runs a command; `xml` holds the parsed <CommandResponse> (or null). Large payloads go as POST. */
    private function call(string $command, array $params=[], bool $post=false): array {
        $query = array_merge([
            'ApiUser'  => $this->creds['api_user'],
            'ApiKey'   => $this->creds['api_key'],
            'UserName' => $this->creds['user_name'] ?? $this->creds['api_user'],
            'ClientIp' => $this->creds['client_ip'],
            'Command'  => $command,
        ], $params);
        [$code,$body,$err] = $post ? Http::postForm($this->base, $query) : Http::get($this->base.'?'.http_build_query($query));

        $prev = libxml_use_internal_errors(true);
        $xml = $body !== '' ? simplexml_load_string($body) : false;
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        if ($xml === false) {
            return $this->result(false, $body, $code, $err ?: 'namecheap: invalid XML response', ['xml'=>null]);
        }
        $raw = json_decode(json_encode($xml), true);
        $ok = $code>0 && $code<400 && !$err && strtoupper((string)$xml['Status']) === 'OK';
        if (!$ok && !$err) {
            $msgs = [];
            if (isset($xml->Errors->Error)) foreach ($xml->Errors->Error as $e) $msgs[] = trim((string)$e).' ('.(string)$e['Number'].')';
            $err = $msgs ? implode('; ', $msgs) : "HTTP {$code}";
        }
        return $this->result($ok, $raw, $code, $err, ['xml'=>$ok ? $xml->CommandResponse : null]);
    }

    private static function strip(array $r): array { unset($r['xml']); return $r; }

    /** "example.co.uk" => ['example', 'co.uk'] */
    private static function split(string $domain): array {
        $p = explode('.', strtolower($domain), 2);
        return ['SLD'=>$p[0], 'TLD'=>$p[1] ?? ''];
    }

    public function checkAvailability(array $domains): array {
        $r = $this->call('namecheap.domains.check', ['DomainList'=>implode(',', $domains)]);
        $available=[];$unavailable=[];$invalid=[];
        if ($r['xml']) foreach ($r['xml']->DomainCheckResult as $row) {
            $d = strtolower((string)$row['Domain']);
            if ((string)$row['ErrorNo'] !== '' && (string)$row['ErrorNo'] !== '0') $invalid[]=$d;
            elseif (strtolower((string)$row['Available']) === 'true') $available[]=$d;
            else $unavailable[]=$d;
        }
        return self::strip($r) + ['available'=>$available,'unavailable'=>$unavailable,'invalid'=>$invalid];
    }

    /** opts: years, privacy, nameservers, registrant (unified keys) or contacts{registrant,admin,tech,billing}. */
    public function registerDomain(string $domain, array $opts): array {
        $contacts = $opts['contacts'] ?? ['registrant'=>$opts['registrant'] ?? []];
        $reg = $contacts['registrant'] ?? [];
        foreach (['first_name','last_name','address','city','state','zip','country','phone','email'] as $k) {
            if (empty($reg[$k])) return $this->result(false, null, 0, "namecheap: registrant.{$k} is required");
        }
        $params = ['DomainName'=>$domain, 'Years'=>(int)($opts['years'] ?? 1)];
        foreach (['Registrant'=>'registrant','Tech'=>'tech','Admin'=>'admin','AuxBilling'=>'billing'] as $role=>$key) {
            $c = $contacts[$key] ?? $reg;
            foreach (self::CONTACT as $k=>$field) if (isset($c[$k]) && $c[$k] !== '') $params[$role.$field] = $c[$k];
        }
        if (!empty($opts['privacy'])) { $params['AddFreeWhoisguard']='yes'; $params['WGEnabled']='yes'; }
        if (!empty($opts['nameservers'])) $params['Nameservers'] = implode(',', $opts['nameservers']);
        return self::strip($this->call('namecheap.domains.create', $params, true));
    }

    public function renewDomain(string $domain, int $years=1, array $opts=[]): array {
        return self::strip($this->call('namecheap.domains.renew', ['DomainName'=>$domain, 'Years'=>$years]));
    }

    public function transferDomain(string $domain, array $opts): array {
        $params = ['DomainName'=>$domain, 'Years'=>(int)($opts['years'] ?? 1), 'EPPCode'=>$opts['auth_code'] ?? ''];
        if (!empty($opts['privacy'])) { $params['AddFreeWhoisguard']='yes'; $params['WGEnabled']='yes'; }
        return self::strip($this->call('namecheap.domains.transfer.create', $params));
    }

    public function getDomain(string $domain): array {
        return self::strip($this->call('namecheap.domains.getInfo', ['DomainName'=>$domain]));
    }

    public function getDNS(string $domain): array {
        $r = $this->call('namecheap.domains.dns.getHosts', self::split($domain));
        $out=[];
        if ($r['xml']) foreach ($r['xml']->DomainDNSGetHostsResult->host as $h) {
            $type = strtoupper((string)$h['Type']);
            $out[] = (new DnsRecord($type, (string)$h['Name'] ?: '@', (string)$h['Address'], (int)((string)$h['TTL'] ?: 1800),
                $type === 'MX' ? (int)(string)$h['MXPref'] : null, (string)$h['HostId'] ?: null))->toArray();
        }
        return self::strip($r) + ['records'=>$out];
    }

    /** Namecheap only has "replace all hosts", so setDNS is the primitive and add/del are read-modify-write. */
    public function setDNS(string $domain, array $records): array {
        $params = self::split($domain); $i = 1; $hasMx = false;
        foreach ($records as $rec) {
            $type = strtoupper($rec['type']);
            $params["HostName{$i}"]   = DnsRecord::relativeHost($rec['host'] ?? '@', $domain);
            $params["RecordType{$i}"] = $type;
            $params["Address{$i}"]    = $rec['value'];
            $params["TTL{$i}"]        = (int)($rec['ttl'] ?? 1800);
            if ($type === 'MX') { $params["MXPref{$i}"] = (int)($rec['prio'] ?? 10); $hasMx = true; }
            $i++;
        }
        if ($hasMx) $params['EmailType'] = 'MX';
        return self::strip($this->call('namecheap.domains.dns.setHosts', $params, true));
    }

    public function addDNS(string $domain, array $record): array {
        $cur = $this->getDNS($domain);
        if (!$cur['ok']) return $cur;
        $cur['records'][] = $record;
        return $this->setDNS($domain, $cur['records']);
    }

    /** Selector: any of id/type/host/value/prio; every matching host record is removed. */
    public function delDNS(string $domain, array $selector): array {
        if (!array_intersect_key($selector, ['id'=>1,'type'=>1,'host'=>1,'value'=>1])) return $this->result(false, null, 0, 'namecheap: delDNS needs a selector');
        if (isset($selector['host'])) $selector['host'] = DnsRecord::relativeHost($selector['host'], $domain);
        $cur = $this->getDNS($domain);
        if (!$cur['ok']) return $cur;
        $keep = array_values(array_filter($cur['records'], function ($r) use ($selector) { return !DnsRecord::matches($r, $selector); }));
        $deleted = count($cur['records']) - count($keep);
        if ($deleted === 0) return $this->result(true, null, 0, '', ['deleted'=>0]);
        return $this->setDNS($domain, $keep) + ['deleted'=>$deleted];
    }

    public function setNameServers(string $domain, array $nameservers): array {
        return self::strip($this->call('namecheap.domains.dns.setCustom', self::split($domain) + ['Nameservers'=>implode(',', $nameservers)]));
    }

    /** $op is the full command name, e.g. "namecheap.users.getBalances". */
    public function raw(string $op, array $params=[]): array {
        return self::strip($this->call($op, $params));
    }
}
