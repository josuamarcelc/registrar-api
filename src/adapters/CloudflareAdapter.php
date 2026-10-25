<?php
namespace RegistrarAPI\Adapters;

use RegistrarAPI\Core\BaseAdapter;
use RegistrarAPI\Core\Http;
use RegistrarAPI\Core\DnsRecord;

/**
 * Cloudflare: DNS zones, cache and settings, plus read access to Cloudflare Registrar.
 *
 * The unified methods (getDNS/setDNS/addDNS/delDNS/getDomain/raw) return the standard
 * ['ok','raw','http','err'] array. Cloudflare Registrar has no public API for availability,
 * registration, renewal, transfer-in or custom nameservers, so those return ok=false with
 * 'unsupported'=>true. The zone helpers (createZone, ensureDnsRecord, purge*, setSetting, ...)
 * keep their original contract: they return the API `result` and throw on error.
 */
class CloudflareAdapter extends BaseAdapter
{
    protected string $brand = 'cloudflare';
    private string $base = 'https://api.cloudflare.com/client/v4/';
    private ?string $accountId;
    /** @var array<string,string> zone id => zone name, and name => id */
    private array $zones = [];

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->requireCreds(['api_token']);
        $this->accountId = $config['account_id'] ?? null;
    }

    private function headers(): array
    {
        return ['Authorization: Bearer '.$this->creds['api_token'], 'Accept: application/json'];
    }

    /** Low level request returning the standard result shape; `result` holds the API result. */
    private function request(string $method, string $uri, ?array $payload = null): array
    {
        $method = strtoupper($method);
        $url = $this->base.ltrim($uri, '/');
        if ($method === 'GET' || $method === 'DELETE') {
            if ($payload) $url .= (strpos($url, '?') === false ? '?' : '&').http_build_query($payload);
            $resp = $method === 'GET' ? Http::get($url, $this->headers(), 30) : Http::delete($url, $this->headers(), 30);
        } elseif ($method === 'POST') {
            $resp = Http::postJson($url, $payload ?? new \stdClass(), $this->headers());
        } elseif ($method === 'PUT') {
            $resp = Http::putJson($url, $payload ?? new \stdClass(), $this->headers());
        } else {
            $resp = Http::patchJson($url, $payload ?? new \stdClass(), $this->headers());
        }
        [$code, $body, $err] = $resp;
        $j = $this->json($body);
        $ok = $code > 0 && $code < 400 && !$err && !empty($j['success']);
        if (!$ok && !$err) $err = (string)($j['errors'][0]['message'] ?? ($code >= 400 ? "HTTP {$code}" : 'unexpected response'));
        return $this->result($ok, $j, $code, $err, ['result' => $j['result'] ?? null]);
    }

    /** Throwing variant used by the zone helpers (original behaviour). */
    private function call(string $method, string $uri, ?array $payload = null)
    {
        $r = $this->request($method, $uri, $payload);
        if (!$r['ok']) throw new \RuntimeException("Cloudflare API error: {$r['err']}");
        return $r['result'];
    }

    private static function strip(array $r): array { unset($r['result']); return $r; }

    /* ---------------- Zones ---------------- */

    // Create a new zone (domain) in your CF account.
    public function createZone(string $domain, bool $jumpStart = false): array
    {
        $payload = ['name' => $domain, 'jump_start' => $jumpStart];
        if ($this->accountId) $payload['account'] = ['id' => $this->accountId];
        return $this->call('POST', 'zones', $payload); // returns id, name_servers, status, etc.
    }

    public function getZoneIdByName(string $domain): ?string
    {
        $domain = strtolower($domain);
        if (isset($this->zones[$domain])) return $this->zones[$domain];
        $q = ['name' => $domain, 'per_page' => 1];
        if ($this->accountId) $q['account.id'] = $this->accountId;
        $r = $this->call('GET', 'zones', $q);
        if (!isset($r[0]['id'])) return null;
        $this->zones[$domain] = $r[0]['id']; $this->zones[$r[0]['id']] = $domain;
        return $r[0]['id'];
    }

    public function getZone(string $zoneId): array
    {
        $z = $this->call('GET', "zones/{$zoneId}");
        if (isset($z['name'])) { $this->zones[$zoneId] = $z['name']; $this->zones[strtolower($z['name'])] = $zoneId; }
        return $z;
    }

    private function zoneName(string $zoneId): string
    {
        return $this->zones[$zoneId] ?? ($this->getZone($zoneId)['name'] ?? '');
    }

    /** "@" / "www" / "www.example.com" => "example.com" / "www.example.com" / "www.example.com" */
    private static function fqdn(string $name, string $zone): string
    {
        $name = rtrim(strtolower(trim($name)), '.'); $zone = strtolower($zone);
        if ($name === '' || $name === '@') return $zone;
        if ($name === $zone || substr($name, -strlen('.'.$zone)) === '.'.$zone) return $name;
        return $name.'.'.$zone;
    }

    /* ---------------- DNS ---------------- */

    // Upsert a DNS record (A/AAAA/CNAME/TXT/MX/etc.). $name may be "@", "www" or a full host name.
    public function ensureDnsRecord(string $zoneId, string $type, string $name, string $content, int $ttl = 300, bool $proxied = false): array
    {
        $name = self::fqdn($name, $this->zoneName($zoneId));
        $list = $this->call('GET', "zones/{$zoneId}/dns_records", [
            'type' => $type, 'name' => $name, 'per_page' => 100
        ]);
        $existing = $list[0] ?? null;

        $fields = ['type'=>$type,'name'=>$name,'content'=>$content,'ttl'=>$ttl,'proxied'=>$proxied];

        if ($existing && ($existing['content'] !== $content || (bool)$existing['proxied'] !== $proxied || (int)$existing['ttl'] !== $ttl)) {
            return $this->call('PATCH', "zones/{$zoneId}/dns_records/".$existing['id'], $fields);
        }
        if ($existing) return $existing;

        return $this->call('POST', "zones/{$zoneId}/dns_records", $fields);
    }

    public function updateDnsRecord(string $zoneId, string $recordId, array $fields): bool
    {
        $this->call('PATCH', "zones/{$zoneId}/dns_records/{$recordId}", $fields);
        return true;
    }

    public function deleteDnsRecord(string $zoneId, string $recordId): bool
    {
        $this->call('DELETE', "zones/{$zoneId}/dns_records/{$recordId}");
        return true;
    }

    /* ---------------- Cache ---------------- */

    public function purgeEverything(string $zoneId): bool
    {
        $this->call('POST', "zones/{$zoneId}/purge_cache", ['purge_everything' => true]);
        return true;
    }

    public function purgeByUrls(string $zoneId, array $urls): bool
    {
        // The API takes at most 30 URLs per request.
        foreach (array_chunk(array_values($urls), 30) as $chunk) {
            $this->call('POST', "zones/{$zoneId}/purge_cache", ['files' => $chunk]);
        }
        return true;
    }

    /* ---------------- Settings (free toggles) ---------------- */

    public function setSetting(string $zoneId, string $name, $value): array
    {
        return $this->call('PATCH', "zones/{$zoneId}/settings/{$name}", ['value' => $value]);
    }

    // One helper to toggle common free features at once.
    public function toggleFreeFeatures(string $zoneId, bool $on = true): void
    {
        $v = $on ? 'on' : 'off';
        $this->setSetting($zoneId, 'always_use_https', $v);
        $this->setSetting($zoneId, 'automatic_https_rewrites', $v);
        $this->setSetting($zoneId, 'brotli', $v);
        $this->setSetting($zoneId, 'rocket_loader', $v);
        $this->setSetting($zoneId, 'development_mode', $v); // note: CF auto-disables after 3h
        // Auto Minify was retired by Cloudflare in August 2024; the "minify" setting no longer exists.
        // other common ones:
        // $this->setSetting($zoneId, 'security_level', 'medium'); // or: low/high/under_attack/essentially_off
        // $this->setSetting($zoneId, 'cache_level', 'aggressive'); // or: basic/simplified
    }

    /* ---------------- Unified BaseAdapter API ---------------- */

    public function checkAvailability(array $domains): array { return $this->unsupported('checkAvailability'); }
    public function registerDomain(string $domain, array $opts): array { return $this->unsupported('registerDomain'); }
    public function renewDomain(string $domain, int $years = 1, array $opts = []): array { return $this->unsupported('renewDomain (Cloudflare Registrar renews automatically)'); }
    public function transferDomain(string $domain, array $opts): array { return $this->unsupported('transferDomain (start transfers in the dashboard)'); }
    public function setNameServers(string $domain, array $nameservers): array { return $this->unsupported('setNameServers (Cloudflare Registrar domains must use Cloudflare nameservers)'); }

    /** Registrar details when account_id is set, otherwise the zone. */
    public function getDomain(string $domain): array
    {
        if ($this->accountId) return self::strip($this->request('GET', "accounts/{$this->accountId}/registrar/domains/".rawurlencode($domain)));
        $zoneId = $this->zoneIdOrNull($domain);
        if ($zoneId === null) return $this->result(false, null, 0, "cloudflare: no zone named {$domain}");
        return self::strip($this->request('GET', "zones/{$zoneId}"));
    }

    private function zoneIdOrNull(string $domain): ?string
    {
        try { return $this->getZoneIdByName($domain); } catch (\RuntimeException $e) { return null; }
    }

    private function listRecords(string $zoneId, array $filter = []): array
    {
        $all = []; $page = 1; $last = null;
        do {
            $r = $this->request('GET', "zones/{$zoneId}/dns_records", $filter + ['per_page' => 100, 'page' => $page]);
            if (!$r['ok']) return [$r, []];
            $last = $r;
            foreach ((array)$r['result'] as $x) $all[] = $x;
            $pages = (int)($r['raw']['result_info']['total_pages'] ?? 1);
        } while (++$page <= $pages);
        return [$last, $all];
    }

    public function getDNS(string $domain): array
    {
        $zoneId = $this->zoneIdOrNull($domain);
        if ($zoneId === null) return $this->result(false, null, 0, "cloudflare: no zone named {$domain}", ['records' => []]);
        [$r, $list] = $this->listRecords($zoneId);
        $out = [];
        foreach ($list as $x) {
            $rec = (new DnsRecord($x['type'], DnsRecord::relativeHost($x['name'], $domain), (string)$x['content'], (int)$x['ttl'],
                isset($x['priority']) ? (int)$x['priority'] : null, (string)$x['id']))->toArray();
            $rec['proxied'] = (bool)($x['proxied'] ?? false);
            $out[] = $rec;
        }
        return self::strip($r) + ['records' => $out, 'zone_id' => $zoneId];
    }

    private function toCf(array $record, string $domain): array
    {
        $x = ['type' => strtoupper($record['type']), 'name' => self::fqdn($record['host'] ?? '@', $domain),
              'content' => $record['value'], 'ttl' => (int)($record['ttl'] ?? 1)]; // ttl 1 = automatic
        if (isset($record['prio'])) $x['priority'] = (int)$record['prio'];
        if (isset($record['proxied'])) $x['proxied'] = (bool)$record['proxied'];
        return $x;
    }

    public function addDNS(string $domain, array $record): array
    {
        $zoneId = $this->zoneIdOrNull($domain);
        if ($zoneId === null) return $this->result(false, null, 0, "cloudflare: no zone named {$domain}");
        $r = $this->request('POST', "zones/{$zoneId}/dns_records", $this->toCf($record, $domain));
        return self::strip($r) + ['id' => $r['result']['id'] ?? null];
    }

    /** Replaces every record in the zone (Cloudflare-managed apex NS records are left alone). */
    public function setDNS(string $domain, array $records): array
    {
        $cur = $this->getDNS($domain);
        if (!$cur['ok']) return $cur;
        $ok = true; $raw = [];
        foreach ($cur['records'] as $r) {
            if ($r['type'] === 'NS' && $r['host'] === '@') continue;
            $res = self::strip($this->request('DELETE', "zones/{$cur['zone_id']}/dns_records/{$r['id']}"));
            $ok = $ok && $res['ok']; $raw[] = $res;
        }
        foreach ($records as $rec) { $res = $this->addDNS($domain, $rec); $ok = $ok && $res['ok']; $raw[] = $res; }
        return $this->result($ok, $raw);
    }

    /** Selector: any of id/type/host/value/prio; every matching record is removed. */
    public function delDNS(string $domain, array $selector): array
    {
        if (!array_intersect_key($selector, ['id' => 1, 'type' => 1, 'host' => 1, 'value' => 1])) return $this->result(false, null, 0, 'cloudflare: delDNS needs a selector');
        if (isset($selector['host'])) $selector['host'] = DnsRecord::relativeHost($selector['host'], $domain);
        $cur = $this->getDNS($domain);
        if (!$cur['ok']) return $cur;
        $ok = true; $raw = []; $deleted = 0;
        foreach ($cur['records'] as $r) {
            if (!DnsRecord::matches($r, $selector)) continue;
            $res = self::strip($this->request('DELETE', "zones/{$cur['zone_id']}/dns_records/{$r['id']}"));
            $ok = $ok && $res['ok']; $raw[] = $res; $deleted++;
        }
        return $this->result($ok, $raw, 0, '', ['deleted' => $deleted]);
    }

    /** $op is a path relative to /client/v4/. params: method (GET|POST|PUT|PATCH|DELETE) and body (array). */
    public function raw(string $op, array $params = []): array
    {
        $r = $this->request($params['method'] ?? 'GET', $op, $params['body'] ?? null);
        return self::strip($r);
    }
}
