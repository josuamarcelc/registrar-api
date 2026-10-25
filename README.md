# Registrar API

A unified PHP library for managing domains across multiple registrars with a **single, consistent API**. Current adapters:

| Brand | `make()` name | Domains | DNS | Nameservers |
|---|---|---|---|---|
| NameSilo | `namesilo` | check, register, renew, transfer, info | full | yes |
| GoDaddy | `godaddy` | check, register, renew, transfer, info | full | yes |
| Namecheap | `namecheap` | check, register, renew, transfer, info | full | yes |
| Dynadot | `dynadot` | check, register, renew, transfer, info | full | yes |
| Cloudflare | `cloudflare` / `cf` | info (Registrar, read-only) | full + cache + zone settings | — |

Provides a consistent interface for common operations like domain availability checks, registration, renewal, transfer, and DNS record management.
Easily extendable via adapter classes to support additional registrars with minimal code changes.

> Drop-in architecture: add your own registrar by creating one class in `src/adapters/`.

---

## Requirements
- PHP **7.4+** (8.x recommended)
- Extensions: `curl`, `json`, `simplexml` (Namecheap)
- Composer

## Installation

```bash
composer require josuamarcelc/registrar-api
```

If you’re developing locally from the repo, ensure PSR‑4 autoloading is refreshed:

```bash
composer dump-autoload -o
```

## Quick Start

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use RegistrarAPI\RegistrarAPI;

// Pick your registrar by brand string (case-insensitive):
// 'namesilo', 'godaddy', 'namecheap', 'dynadot', 'cloudflare'
$api = RegistrarAPI::make('namesilo', [
    'api_key' => 'YOUR_NAMESILO_API_KEY'
]);

// Check availability for one or more domains
$result = $api->checkAvailability(['example.com', 'mybrand.io']);
print_r($result['available']);

// Set nameservers (works the same across all registrar adapters)
$api->setNameServers('example.com', ['ns1.host.com', 'ns2.host.com']);
```

---

## Credentials per Adapter

Each adapter accepts a config array. Missing required keys throw `InvalidArgumentException` from `make()`.
Every adapter also accepts `'base'` to override the API endpoint.

| Adapter | Required | Optional |
|---|---|---|
| NameSilo | `api_key` | `sandbox` (bool) |
| GoDaddy | `api_key`, `api_secret` | `sandbox` (bool, uses OTE), `client_ip` (for purchase consent) |
| Namecheap | `api_user`, `api_key`, `client_ip` (whitelisted in Namecheap) | `user_name` (defaults to `api_user`), `sandbox` (bool) |
| Dynadot | `api_key` | — |
| Cloudflare | `api_token` | `account_id` (zone creation and Registrar lookups) |

```php
$api = RegistrarAPI::make('godaddy', [
  'api_key'    => 'KEY',
  'api_secret' => 'SECRET',
  'sandbox'    => true,   // https://api.ote-godaddy.com
]);
```

---

## Common Operations (Unified Shape)

All adapters implement these methods (see `src/Core/BaseAdapter.php`). Every method returns an array with at least:

| Key | Meaning |
|---|---|
| `ok` | `true` only when the HTTP call **and** the registrar's own status say success (NameSilo reply code, Namecheap `Status="OK"`, Dynadot `ResponseCode`, Cloudflare `success`) |
| `raw` | the parsed registrar payload (JSON array, or XML converted to array) |
| `http` | HTTP status code (`0` when no request was made) |
| `err` | transport error or the registrar's error message, `''` on success |

Operations a registrar cannot do return `ok => false` and `unsupported => true` instead of throwing.

```php
// Availability
$api->checkAvailability(['example.com']);
// -> [..., 'available'=>[], 'unavailable'=>[], 'invalid'=>[]]

// Purchase / Lifecycle
$api->registerDomain('example.com', [
  'years' => 1,
  'privacy' => true,
  'auto_renew' => true,
  'nameservers' => ['ns1.host.com', 'ns2.host.com'],
  'registrant' => [/* see "Contacts" below */],
]);

$api->renewDomain('example.com', 1);
$api->transferDomain('example.com', ['auth_code' => 'EPP']);
$api->getDomain('example.com');

// DNS Records — every adapter returns the same record shape:
// ['type'=>'A', 'host'=>'@'|'www', 'value'=>'...', 'ttl'=>int, 'prio'=>?int, 'id'=>?string]
$api->getDNS('example.com');           // -> [..., 'records'=>[...]]

// Replace the whole zone
$api->setDNS('example.com', [
  ['type'=>'A','host'=>'@','value'=>'203.0.113.10','ttl'=>600],
  ['type'=>'CNAME','host'=>'www','value'=>'example.com','ttl'=>600],
  ['type'=>'MX','host'=>'@','value'=>'mx.example.com','ttl'=>3600,'prio'=>10],
]);

// Append one record
$api->addDNS('example.com', ['type'=>'TXT','host'=>'@','value'=>'v=spf1 -all','ttl'=>300]);

// Delete every record matching the selector (any of id/type/host/value/prio)
$api->delDNS('example.com', ['type'=>'TXT','host'=>'@','value'=>'v=spf1 -all']); // -> [..., 'deleted'=>1]

// Nameservers
$api->setNameServers('example.com', ['ns1.host.com','ns2.host.com']);
```

**Hosts** are always relative: `@` is the apex, `www` is `www.example.com`. You may also pass a full name (`www.example.com`); adapters convert it.

### Per-registrar notes

- **NameSilo** — `delDNS` by `id` is one call; other selectors read the zone first. `setDNS` deletes every record, then adds the new ones.
- **GoDaddy** — `delDNS` with `type` + `host` removes that whole rrset; add `value` to remove only matching records. Agreement keys for purchase/transfer are fetched from `/domains/agreements` (override with `'agreement_keys' => [...]`). GoDaddy only enables the Domains API for accounts that meet its eligibility rules.
- **Namecheap** — the API can only replace all host records, so `addDNS`/`delDNS` read, modify and write the full set (`setHosts`, sent as POST). MX records set `EmailType=MX` automatically.
- **Dynadot** — uses API3 `set_dns2`. Dynadot applies **one TTL per zone** (the first record's). There is no per-record delete, so `delDNS` rewrites the zone and refuses to delete the last record.
- **Cloudflare** — DNS methods resolve the zone by name. Registrar availability, registration, renewal, transfer-in and custom nameservers are not in Cloudflare's public API and return `unsupported`. See [docs/cloudflare.md](docs/cloudflare.md).

---

## Contacts

`registerDomain()` takes the registrant in the same shape for NameSilo and Namecheap:

```php
'registrant' => [
  'first_name' => 'Jane',
  'last_name'  => 'Doe',
  'company'    => 'Acme',          // optional
  'email'      => 'jane@example.com',
  'phone'      => '+1.5555555555',
  'address'    => '123 Street',
  'address2'   => 'Suite 4',       // optional
  'city'       => 'LA',
  'state'      => 'CA',
  'zip'        => '90001',
  'country'    => 'US',
],
// Namecheap / GoDaddy also accept separate roles:
// 'contacts' => ['registrant'=>[...], 'admin'=>[...], 'tech'=>[...], 'billing'=>[...]]
```

- **Namecheap** refuses to register without a complete registrant (no placeholder data is ever sent).
- **NameSilo** can use a stored contact instead: `'contact_id' => 123`.
- **GoDaddy** expects its own contact shape (`nameFirst`, `nameLast`, `email`, `phone`, `addressMailing{address1, city, state, postalCode, country}`).
- **Dynadot** uses contact ids from your account: `'contact_ids' => ['registrant'=>1, 'admin'=>1, 'technical'=>1, 'billing'=>1]`. Create them once with `raw('create_contact', [...])`.

---

## Full Tutorials per Adapter

### NameSilo – Complete Flow
```php
use RegistrarAPI\RegistrarAPI;

$api = RegistrarAPI::make('namesilo', ['api_key' => 'KEY']);

// 1. Check availability
$check = $api->checkAvailability(['newdomain123.com']);
if (in_array('newdomain123.com', $check['available'], true)) {

    // 2. Register
    $reg = $api->registerDomain('newdomain123.com', [
      'years' => 1,
      'privacy' => true,
      'auto_renew' => false,
      'registrant' => [
        'first_name' => 'Jane',
        'last_name'  => 'Doe',
        'email'      => 'jane@example.com',
        'phone'      => '+1.5555555555',
        'address'    => '123 Street',
        'city'       => 'LA',
        'state'      => 'CA',
        'zip'        => '90001',
        'country'    => 'US'
      ]
    ]);
    if (!$reg['ok']) throw new RuntimeException($reg['err']);

    // 3. Set nameservers
    $api->setNameServers('newdomain123.com', ['ns1.custom.com','ns2.custom.com']);

    // 4. Add DNS
    $api->addDNS('newdomain123.com', ['type'=>'A','host'=>'@','value'=>'203.0.113.55','ttl'=>600]);
}
```

### GoDaddy – Complete Flow
```php
$api = RegistrarAPI::make('godaddy', ['api_key'=>'KEY','api_secret'=>'SECRET']);

$check = $api->checkAvailability(['newbrand.io']);
if ($check['available']) {
    $api->registerDomain('newbrand.io', [
        'years' => 1,
        'privacy' => true,
        'client_ip' => $_SERVER['REMOTE_ADDR'],   // who agreed to the registration terms
        'registrant' => [
            'nameFirst' => 'Jane', 'nameLast' => 'Doe',
            'email' => 'jane@example.com', 'phone' => '+1.5555555555',
            'addressMailing' => ['address1'=>'123 Street','city'=>'LA','state'=>'CA','postalCode'=>'90001','country'=>'US'],
        ],
    ]);
    $api->setDNS('newbrand.io', [
      ['type'=>'A','host'=>'@','value'=>'198.51.100.20','ttl'=>600],
      ['type'=>'CNAME','host'=>'www','value'=>'@','ttl'=>600],
    ]);
}
```

### Namecheap – Complete Flow
```php
$api = RegistrarAPI::make('namecheap', [
    'api_user'=>'USER',
    'api_key'=>'KEY',
    'client_ip'=>'203.0.113.22'
]);

$check = $api->checkAvailability(['coolbrand.net']);
if ($check['available']) {
    $api->registerDomain('coolbrand.net', [
        'years' => 1,
        'privacy' => true,
        'registrant' => [/* see "Contacts" */],
    ]);
    $api->setNameServers('coolbrand.net', ['ns1.customdns.com','ns2.customdns.com']);
}
```

### Dynadot – Complete Flow
```php
$api = RegistrarAPI::make('dynadot', ['api_key'=>'KEY']);

$check = $api->checkAvailability(['mynew.io']);
if ($check['available']) {
    $api->registerDomain('mynew.io', ['years'=>1]);
    $api->setDNS('mynew.io', [
      ['type'=>'A','host'=>'@','value'=>'192.0.2.123','ttl'=>600],
      ['type'=>'TXT','host'=>'@','value'=>'v=spf1 -all'],
    ]);
}
```

---

## Raw Passthrough (Escape Hatch)

Need a command the wrapper doesn’t expose yet? Call the adapter directly. You get the same result shape.

```php
$gd = RegistrarAPI::make('godaddy', ['api_key'=>'KEY','api_secret'=>'SECRET']);
$gd->raw('domains/suggest?query=mybrand&limit=5');                       // GET, path relative to the base
$gd->raw('domains/example.com', ['method'=>'PATCH','body'=>['locked'=>true]]);

$ns = RegistrarAPI::make('namesilo', ['api_key'=>'KEY']);
$ns->raw('listDomains');                                                 // NameSilo operation name

$nc->raw('namecheap.users.getBalances');                                 // Namecheap command
$dd->raw('get_contact', ['contact_id'=>123]);                            // Dynadot API3 command
$cf->raw('user/tokens/verify');                                          // Cloudflare v4 path
```

---

## Adding a New Registrar

1. Create a class in `src/adapters/{Brand}.php`:
```php
<?php
namespace RegistrarAPI\Adapters;

use RegistrarAPI\Core\BaseAdapter;

class MyRegistrar extends BaseAdapter {
  protected string $brand = 'myregistrar';

  public function checkAvailability(array $domains): array { /* ... */ }
  public function registerDomain(string $domain, array $opts): array { /* ... */ }
  public function renewDomain(string $domain, int $years=1, array $opts=[]): array { /* ... */ }
  public function transferDomain(string $domain, array $opts): array { /* ... */ }
  public function getDomain(string $domain): array { /* ... */ }
  public function getDNS(string $domain): array { /* ... */ }
  public function setDNS(string $domain, array $records): array { /* ... */ }
  public function addDNS(string $domain, array $record): array { /* ... */ }
  public function delDNS(string $domain, array $selector): array { /* ... */ }
  public function setNameServers(string $domain, array $nameservers): array { /* ... */ }
  public function raw(string $op, array $params=[]): array { /* ... */ }
}
```
2. Use `Core\Http` for requests, `$this->result()` for the return shape, `$this->unsupported()` for things the registrar can't do, and `Core\DnsRecord` for records.
3. Composer autoloading will pick it up automatically with:
```php
$api = RegistrarAPI::make('myregistrar', [...creds...]);
```

---

## Testing

The test suite runs offline: `Core\Http::setTransport()` swaps cURL for a fake that records requests and replays canned registrar responses.

```bash
composer test        # or: php tests/run.php
```

You can use the same hook in your app to log or proxy every registrar call:

```php
RegistrarAPI\Core\Http::setTransport(function ($method, $url, $body, $headers, $timeout) { /* return [$code, $body, $err] */ });
RegistrarAPI\Core\Http::setTransport(null); // back to cURL
```

---

### Cloudflare Adapter

Manage Cloudflare **zones, DNS, cache**, and **free features** from the same API.

- Create a zone and get assigned nameservers
- Unified `getDNS` / `setDNS` / `addDNS` / `delDNS` (with `proxied`)
- Upsert DNS records (A/AAAA/CNAME/TXT/MX, proxied or not)
- Purge cache (everything or specific URLs)
- Toggle free features: Always Use HTTPS, Auto HTTPS Rewrites, Brotli, Rocket Loader, Dev Mode

➡️ See the full guide: [docs/cloudflare.md](docs/cloudflare.md)

---

## License
MIT ©  [josuamarcelc]


[josuamarcelc]: <https://josuamarcelc.com/>
