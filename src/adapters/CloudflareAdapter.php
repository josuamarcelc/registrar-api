<?php
namespace RegistrarAPI\Adapters;

use GuzzleHttp\Client;

class CloudflareAdapter /* extends BaseAdapter or implements your AdapterInterface */
{
    private Client $http;
    private ?string $accountId;

    public function __construct(array $config)
    {
        if (empty($config['api_token'])) {
            throw new \InvalidArgumentException('Cloudflare api_token is required');
        }
        $this->accountId = $config['account_id'] ?? null;
        $this->http = new Client([
            'base_uri' => 'https://api.cloudflare.com/client/v4/',
            'headers'  => [
                'Authorization' => 'Bearer '.$config['api_token'],
                'Content-Type'  => 'application/json'
            ],
            'http_errors' => false,
            'timeout'     => 30,
        ]);
    }

    private function call(string $method, string $uri, array $payload = null)
    {
        $opts = $payload ? (strtoupper($method)==='GET' ? ['query'=>$payload] : ['json'=>$payload]) : [];
        $res  = $this->http->request($method, $uri, $opts);
        $body = json_decode((string)$res->getBody(), true);
        if (!isset($body['success']) || !$body['success']) {
            $err = $body['errors'][0]['message'] ?? (string)$res->getBody();
            throw new \RuntimeException("Cloudflare API error: {$err}");
        }
        return $body['result'];
    }

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
        $r = $this->call('GET', 'zones', ['name' => $domain, 'per_page' => 1]);
        return isset($r[0]['id']) ? $r[0]['id'] : null;
    }

    public function getZone(string $zoneId): array
    {
        return $this->call('GET', "zones/{$zoneId}");
    }

    /* ---------------- DNS ---------------- */

    // Upsert a DNS record (A/AAAA/CNAME/TXT/MX/etc.)
    public function ensureDnsRecord(string $zoneId, string $type, string $name, string $content, int $ttl = 300, bool $proxied = false): array
    {
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

    /* ---------------- Cache ---------------- */

    public function purgeEverything(string $zoneId): bool
    {
        $this->call('POST', "zones/{$zoneId}/purge_cache", ['purge_everything' => true]);
        return true;
    }

    public function purgeByUrls(string $zoneId, array $urls): bool
    {
        $this->call('POST', "zones/{$zoneId}/purge_cache", ['files' => array_values($urls)]);
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
        $this->setSetting($zoneId, 'minify', ['css'=>$v, 'js'=>$v, 'html'=>$v]);
        // other common ones:
        // $this->setSetting($zoneId, 'security_level', 'medium'); // or: low/high/under_attack/essentially_off
        // $this->setSetting($zoneId, 'cache_level', 'aggressive'); // or: basic/simplified
    }
}
