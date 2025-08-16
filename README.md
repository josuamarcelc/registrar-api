# Registrar API

A unified PHP library for managing domains across multiple registrars with a **single, consistent API**. Current adapters:

- NameSilo
- GoDaddy
- Namecheap
- Dynadot
- ....
  
Provides a consistent interface for common operations like domain availability checks, registration, renewal, transfer, and DNS record management.  
Easily extendable via adapter classes to support additional registrars with minimal code changes.


> Drop-in architecture: add your own registrar by creating one class in `src/adapters/`.

---

## Requirements
- PHP **7.4+** (8.x recommended)
- cURL extension
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
// 'namesilo', 'godaddy', 'namecheap', 'dynadot'
$api = RegistrarAPI::make('namesilo', [
    'api_key' => 'YOUR_NAMESILO_API_KEY'
]);

// Check availability for one or more domains
$result = $api->checkAvailability(['example.com', 'mybrand.io']);
print_r($result);

// Set nameservers (works the same across all adapters)
$api->setNameServers('example.com', ['ns1.host.com', 'ns2.host.com']);
```

---

## Credentials per Adapter

Each adapter accepts a config array. The keys below are the **minimum** you usually need.

### NameSilo
```php
$api = RegistrarAPI::make('namesilo', [
  'api_key' => 'YOUR_KEY'
]);
```

### GoDaddy
```php
$api = RegistrarAPI::make('godaddy', [
  'api_key'    => 'KEY',
  'api_secret' => 'SECRET',
  // optional: 'base' => 'https://api.godaddy.com/v1'  // defaults to production
]);
```

### Namecheap
```php
$api = RegistrarAPI::make('namecheap', [
  'api_user'  => 'USERNAME',
  'api_key'   => 'KEY',
  'client_ip' => 'SERVER_PUBLIC_IP',
  // optional: 'base' => 'https://api.namecheap.com/xml.response'
]);
```

### Dynadot
```php
$api = RegistrarAPI::make('dynadot', [
  'api_key' => 'KEY',
  // optional: 'base' => 'https://api.dynadot.com/api3.json'
]);
```

---

## Common Operations (Unified Shape)

All adapters implement these methods (see `src/Core/BaseAdapter.php`). Return values are normalized as associative arrays so you can handle responses consistently.

```php
// Availability
$api->checkAvailability(['example.com']); 
// -> ['ok'=>bool, 'available'=>[], 'unavailable'=>[], 'invalid'=>[], 'raw'=>mixed]

// Purchase / Lifecycle
$api->registerDomain('example.com', [
  'years' => 1,
  'privacy' => true,
  'auto_renew' => true,
  // 'registrant' or adapter-specific contact fields (see adapter docs)
]);

$api->renewDomain('example.com', 1);   // -> ['ok'=>bool, 'raw'=>mixed]
$api->transferDomain('example.com', ['auth_code' => 'EPP']); // -> ['ok'=>bool, 'raw'=>mixed]
$api->getDomain('example.com');        // -> ['ok'=>bool, 'raw'=>mixed]

// DNS Records
$api->getDNS('example.com');           // -> ['ok'=>bool, 'records'=>[{'type','host','value','ttl','prio'?}], ...]

$api->setDNS('example.com', [
  ['type'=>'A','host'=>'@','value'=>'203.0.113.10','ttl'=>600],
  ['type'=>'CNAME','host'=>'www','value'=>'@','ttl'=>600],
]);

$api->addDNS('example.com', ['type'=>'TXT','host'=>'@','value'=>'v=spf1 -all','ttl'=>300]);
$api->delDNS('example.com', ['type'=>'TXT','host'=>'@']); // selector varies per adapter

// Nameservers (available for ALL adapters in this library)
$api->setNameServers('example.com', ['ns1.host.com','ns2.host.com']);
```

> ⚠️ **Contacts & Required Fields** differ slightly per registrar. The library forwards what you pass; check the registrar’s API docs if a call fails due to missing fields.

---

## Adapter‑Specific Examples

### NameSilo – Register a domain
```php
$api = RegistrarAPI::make('namesilo', ['api_key' => 'KEY']);
$api->registerDomain('brandnewdomain.com', [
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
```

### GoDaddy – DNS update
```php
$api = RegistrarAPI::make('godaddy', ['api_key'=>'KEY','api_secret'=>'SECRET']);
$api->setDNS('example.com', [
  ['type'=>'A','host'=>'@','value'=>'198.51.100.20','ttl'=>600],
  ['type'=>'A','host'=>'blog','value'=>'198.51.100.21','ttl'=>600],
]);
```

### Namecheap – Set custom nameservers
```php
$api = RegistrarAPI::make('namecheap', ['api_user'=>'USER','api_key'=>'KEY','client_ip'=>'203.0.113.22']);
$api->setNameServers('example.com', ['ns1.customdns.com','ns2.customdns.com']);
```

### Dynadot – Check and register
```php
$api = RegistrarAPI::make('dynadot', ['api_key'=>'KEY']);
$check = $api->checkAvailability(['mynew.io']);
if (!empty($check['available'])) {
  $api->registerDomain('mynew.io', ['years'=>1,'privacy'=>true]);
}
```

---

## Raw Passthrough (Escape Hatch)

Need a command the wrapper doesn’t expose yet? Call the adapter directly:

```php
$gd = RegistrarAPI::make('godaddy', ['api_key'=>'KEY','api_secret'=>'SECRET']);
$res = $gd->raw('domains/suggestions?query=mybrand&limit=5'); // path relative to GoDaddy base
print_r($res);
```

---

## Adding a New Registrar

1. Create a class in `src/adapters/{Brand}.php`:
```php
<?php
namespace RegistrarAPIdapters;

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
2. Composer autoloading will pick it up automatically with:
```php
$api = RegistrarAPI::make('myregistrar', [...creds...]);
```

---

## Error Handling

Every method returns a structure with:
- `ok` (bool) — quick success check
- `raw` — original parsed payload (JSON/XML/array)
- `http` — HTTP status code (when available)
- `err` — transport‑level error string (if any)

You can also wrap calls in try/catch if you layer exceptions in your project.

---

## Quick Start

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use RegistrarAPI\RegistrarAPI;

// Pick your registrar by brand string (case-insensitive):
// 'namesilo', 'godaddy', 'namecheap', 'dynadot'
$api = RegistrarAPI::make('namesilo', [
    'api_key' => 'YOUR_NAMESILO_API_KEY'
]);

// Check availability for one or more domains
$result = $api->checkAvailability(['example.com', 'mybrand.io']);
print_r($result);

// Set nameservers (works the same across all adapters)
$api->setNameServers('example.com', ['ns1.host.com', 'ns2.host.com']);
```

---

## Credentials per Adapter

Each adapter accepts a config array. The keys below are the **minimum** you usually need.

### NameSilo
```php
$api = RegistrarAPI::make('namesilo', [
  'api_key' => 'YOUR_KEY'
]);
```

### GoDaddy
```php
$api = RegistrarAPI::make('godaddy', [
  'api_key'    => 'KEY',
  'api_secret' => 'SECRET',
  // optional: 'base' => 'https://api.godaddy.com/v1'  // defaults to production
]);
```

### Namecheap
```php
$api = RegistrarAPI::make('namecheap', [
  'api_user'  => 'USERNAME',
  'api_key'   => 'KEY',
  'client_ip' => 'SERVER_PUBLIC_IP',
  // optional: 'base' => 'https://api.namecheap.com/xml.response'
]);
```

### Dynadot
```php
$api = RegistrarAPI::make('dynadot', [
  'api_key' => 'KEY',
  // optional: 'base' => 'https://api.dynadot.com/api3.json'
]);
```

---

## Common Operations (Unified Shape)

All adapters implement these methods (see `src/Core/BaseAdapter.php`). Return values are normalized as associative arrays so you can handle responses consistently.

```php
// Availability
$api->checkAvailability(['example.com']); 

// Purchase / Lifecycle
$api->registerDomain('example.com', [
  'years' => 1,
  'privacy' => true,
  'auto_renew' => true,
  // 'registrant' or adapter-specific contact fields (see adapter docs)
]);

$api->renewDomain('example.com', 1);
$api->transferDomain('example.com', ['auth_code' => 'EPP']);
$api->getDomain('example.com');

// DNS Records
$api->getDNS('example.com');

$api->setDNS('example.com', [
  ['type'=>'A','host'=>'@','value'=>'203.0.113.10','ttl'=>600],
  ['type'=>'CNAME','host'=>'www','value'=>'@','ttl'=>600],
]);

$api->addDNS('example.com', ['type'=>'TXT','host'=>'@','value'=>'v=spf1 -all','ttl'=>300]);
$api->delDNS('example.com', ['type'=>'TXT','host'=>'@']); 

// Nameservers
$api->setNameServers('example.com', ['ns1.host.com','ns2.host.com']);
```

> ⚠️ **Contacts & Required Fields** differ slightly per registrar.

---

## Full Tutorials per Adapter

### NameSilo – Complete Flow
```php
use RegistrarAPI\RegistrarAPI;

$api = RegistrarAPI::make('namesilo', ['api_key' => 'KEY']);

// 1. Check availability
$check = $api->checkAvailability(['newdomain123.com']);
if (!empty($check['available'])) {

    // 2. Register
    $api->registerDomain('newdomain123.com', [
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
if (!empty($check['available'])) {
    $api->registerDomain('newbrand.io', [
        'years' => 1,
        'privacy' => true
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
if (!empty($check['available'])) {
    $api->registerDomain('coolbrand.net', [
        'years' => 1,
        'privacy' => true
    ]);
    $api->setNameServers('coolbrand.net', ['ns1.customdns.com','ns2.customdns.com']);
}
```

### Dynadot – Complete Flow
```php
$api = RegistrarAPI::make('dynadot', ['api_key'=>'KEY']);

$check = $api->checkAvailability(['mynew.io']);
if (!empty($check['available'])) {
    $api->registerDomain('mynew.io', ['years'=>1,'privacy'=>true]);
    $api->setDNS('mynew.io', [
      ['type'=>'A','host'=>'@','value'=>'192.0.2.123','ttl'=>600],
      ['type'=>'TXT','host'=>'@','value'=>'v=spf1 -all','ttl'=>300],
    ]);
}
```

---

## Adding a New Registrar

1. Create `src/adapters/{Brand}.php`
2. Extend `BaseAdapter` and implement abstract methods
3. Example:

```php
namespace RegistrarAPI\Adapters;

use RegistrarAPI\Core\BaseAdapter;

class MyRegistrar extends BaseAdapter {
  protected string $brand = 'myregistrar';
  public function checkAvailability(array $domains): array { /* ... */ }
  // ... implement all abstract methods ...
}
```

---

## License
MIT ©  [josuamarcelc]


[josuamarcelc]: <https://josuamarcelc.com/>

