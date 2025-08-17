# Cloudflare Adapter (for Registrar API)

Cloudflare support for the **Registrar API** project, implemented as `RegistrarAPI\Adapters\CloudflareAdapter` (file: `src/adapters/CloudflareAdapter.php`).

Use it to:
- **Create zones** (add a domain to your Cloudflare account)
- **Manage DNS** (create/update/delete or upsert)
- **Purge cache** (everything or specific URLs)
- **Toggle common free features** (Always Use HTTPS, Auto HTTPS Rewrites, Brotli, Minify, Rocket Loader, Development Mode, etc.)

> ℹ️ Cloudflare **cannot** change registrar nameservers. After creating a zone, set the returned CF nameservers at your **registrar** (e.g., Namecheap, Dynadot) using their adapters.

---

## Requirements

- PHP 7.4+ (8.x recommended)
- Guzzle (already required by this project)
- A **Cloudflare API Token** with proper scopes (see below)

---

---

## Quick Start

```php
use RegistrarAPI\RegistrarAPI;

// 1) Build the Cloudflare client
$cf = RegistrarAPI::make('cloudflare', [
  'api_token'  => getenv('CF_API_TOKEN'),  // required
  'account_id' => getenv('CF_ACCOUNT_ID'), // optional but recommended for zone creation
]);

// 2) Create a zone (domain) in your Cloudflare account
$zone   = $cf->createZone('example.com', true);   // jump_start=true tries to import DNS
$zoneId = $zone['id'];
$cfNS   = $zone['name_servers'];                  // ['emily.ns.cloudflare.com','will.ns.cloudflare.com']

// 3) At your registrar, point nameservers to Cloudflare (example: Namecheap)
$nc = RegistrarAPI::make('namecheap', [
  'api_user'  => getenv('NC_APIUSER'),
  'api_key'   => getenv('NC_APIKEY'),
  'user_name' => getenv('NC_USERNAME'),
  'client_ip' => getenv('NC_CLIENT_IP') // must be whitelisted in Namecheap
]);
$nc->setNameservers('example.com', $cfNS);

// 4) Manage DNS at Cloudflare
$cf->ensureDnsRecord($zoneId, 'A',   'example.com', '203.0.113.10', 300, true);
$cf->ensureDnsRecord($zoneId, 'A',   'www',         '203.0.113.10', 300, true);
$cf->ensureDnsRecord($zoneId, 'TXT', 'example.com', 'v=spf1 include:mailhost ~all', 3600, false);

// 5) Cache + settings
$cf->purgeEverything($zoneId);
$cf->toggleFreeFeatures($zoneId, true); // turn ON common free features
```

---

## Tutorial (Step-by-Step)

### 1) Create a scoped API Token in Cloudflare

Minimum scopes:
- Zone → **DNS:Edit**
- Zone → **Cache Purge:Edit**
- Zone → **Zone Settings:Edit**
- Zone → **Zone:Edit** (helpful for various ops)
- Account → **Read** (and “Add Zone” permission if your account requires it to create zones)

Store as `CF_API_TOKEN`. If your adapter needs it for zone creation, also keep `CF_ACCOUNT_ID`.

### 2) Create the zone

```php
$zone = $cf->createZone('example.com', true);
$zoneId = $zone['id'];
$nameservers = $zone['name_servers'];
```

### 3) Set nameservers at the registrar

**Namecheap**
```php
$nc->setNameservers('example.com', $nameservers);
```

**Dynadot**
```php
$dd = RegistrarAPI::make('dynadot', ['api_key' => getenv('DYNADOT_API_KEY')]);
$dd->setNameservers('example.com', $nameservers);
```

Wait for registry propagation. Cloudflare will mark the zone **active** once the NS match.

### 4) Add/Update DNS records

```php
$cf->ensureDnsRecord($zoneId, 'A', 'api', '203.0.113.42', 120, true);
$cf->ensureDnsRecord($zoneId, 'CNAME', 'cdn', 'example.com', 300, true);
$cf->ensureDnsRecord($zoneId, 'MX', 'example.com', 'mx1.mailhost.com', 3600, false);
```

### 5) Purge cache

```php
$cf->purgeEverything($zoneId);
// or:
$cf->purgeByUrls($zoneId, [
  'https://example.com/assets/app.js',
  'https://example.com/assets/app.css',
]);
```

### 6) Toggle free features

```php
$cf->toggleFreeFeatures($zoneId, true);
// Or individual settings:
$cf->setSetting($zoneId, 'always_use_https', 'on');
$cf->setSetting($zoneId, 'automatic_https_rewrites', 'on');
$cf->setSetting($zoneId, 'brotli', 'on');
$cf->setSetting($zoneId, 'rocket_loader', 'off');
$cf->setSetting($zoneId, 'development_mode', 'on'); // auto-off after ~3h
$cf->setSetting($zoneId, 'minify', ['css'=>'on','js'=>'on','html'=>'on']);
$cf->setSetting($zoneId, 'security_level', 'medium');   // off/low/medium/high/under_attack
$cf->setSetting($zoneId, 'cache_level', 'aggressive');  // basic/simplified/aggressive
```

---

## Method Reference

| Method | Description |
|---|---|
| `createZone(string $domain, bool $jumpStart=false): array` | Create a zone (domain) in your CF account. Returns zone details including nameservers. |
| `getZoneIdByName(string $domain): ?string` | Lookup zone ID by name. |
| `getZone(string $zoneId): array` | Get zone details. |
| `ensureDnsRecord(string $zoneId, string $type, string $name, string $content, int $ttl=300, bool $proxied=false): array` | **Upsert** a DNS record (create if missing, update if changed). |
| `updateDnsRecord(string $zoneId, string $recordId, array $fields): bool` | Update a DNS record (when you already know its ID). |
| `deleteDnsRecord(string $zoneId, string $recordId): bool` | Delete a DNS record. |
| `purgeEverything(string $zoneId): bool` | Purge all cache for the zone. |
| `purgeByUrls(string $zoneId, array $urls): bool` | Purge cache for specific URLs. |
| `setSetting(string $zoneId, string $name, $value): array` | Set one zone setting. |
| `toggleFreeFeatures(string $zoneId, bool $on=true): void` | Enable/disable a bundle of common free features. |

---

## Environment Variables (example)

```bash
# Cloudflare
export CF_API_TOKEN=xxxxxxxxxxxxxxxx
export CF_ACCOUNT_ID=yyyyyyyyyyyyyyyy

```

---

## Troubleshooting

- **`Unknown registrar brand …`**  
  Ensure your factory maps `cloudflare` or `cf` to `Adapters\CloudflareAdapter` and PSR-4 is configured (see *Installation / Autoload*).

- **403 / auth errors**  
  Token lacks scopes or targets the wrong account. Regenerate with scopes listed above.

- **Zone not active**  
  Registrar nameservers must match the **exact** CF nameservers returned by `createZone()`; wait for WHOIS/registry propagation.

- **Settings not applied**  
  Some toggles are plan-limited. The helper only toggles features available on the **Free** plan.

- **Record name confusion**  
  Use `name='www'` for a sub of the zone, or `name='sub.example.com'` for a full host. Root uses the zone name (e.g., `example.com`).

---

## FAQ

**Q: Why both registrar and Cloudflare adapters?**  
A: CF provides CDN/DNS. Your registrar holds the domain’s nameservers. Creating a CF zone + pointing NS at CF connects the two.

---

## License

Same as the main project license.
