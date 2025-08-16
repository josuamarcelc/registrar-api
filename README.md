# Universal PHP Domain Registrar API Client

A unified PHP library for managing domains across multiple registrars — currently supporting **NameSilo**, **GoDaddy**, **Namecheap**, and **Dynadot**.  
Provides a consistent interface for common operations like domain availability checks, registration, renewal, transfer, and DNS record management.  
Easily extendable via adapter classes to support additional registrars with minimal code changes.

---

## Features

- **Unified methods** across registrars:
  - `checkAvailability`
  - `registerDomain`
  - `renewDomain`
  - `transferDomain`
  - `getDomain`
  - `getDNS`
  - `setDNS`
  - `addDNS`
  - `delDNS`
- Normalized, predictable response structure.
- Built-in HTTP helper (cURL-based).
- Support for brand-specific raw API calls via `raw($op, $params)`.
- Easy to extend by adding new registrar adapters.

---

## Installation

Clone the repo or download `RegistrarAPI.php` into your project:

```bash
git clone https://github.com/yourusername/registrar-api-php.git
```

Then include it in your project:

```php
require 'RegistrarAPI.php';
```

---

## Usage

### Example: NameSilo

```php
$api = RegistrarAPI::make('namesilo', [
    'api_key' => 'YOUR_NAMESILO_API_KEY'
]);

$result = $api->checkAvailability(['example.com', 'mydomain.net']);
print_r($result);
```

### Example: GoDaddy

```php
$api = RegistrarAPI::make('godaddy', [
    'api_key'    => 'YOUR_GODADDY_KEY',
    'api_secret' => 'YOUR_GODADDY_SECRET',
    // 'base' => 'https://api.ote-godaddy.com/v1' // Sandbox
]);

print_r($api->registerDomain('example.com', [
    'years'   => 1,
    'privacy' => true,
    'contacts' => [
        'registrant' => [
            'nameFirst' => 'John',
            'nameLast'  => 'Doe',
            'email'     => 'john@example.com',
            'phone'     => '+1.1234567890',
            'addressMailing' => [
                'address1' => '123 Main St',
                'city'     => 'City',
                'state'    => 'ST',
                'postalCode' => '12345',
                'country'    => 'US'
            ]
        ]
    ]
]));
```

---

## Supported Registrars

| Registrar | Brand String | Required Credentials |
|-----------|--------------|----------------------|
| NameSilo  | `namesilo`   | `api_key` |
| GoDaddy   | `godaddy`    | `api_key`, `api_secret` or `api_token` |
| Namecheap | `namecheap`  | `api_user`, `username`, `api_key`, `client_ip` |
| Dynadot   | `dynadot`    | `api_key` |

---

## Extending to Other Registrars

1. Create a new adapter:  
   ```php
   final class MyRegistrarAdapter extends RegistrarAdapter {
       protected string $brand = 'myregistrar';
       // implement all abstract methods
   }
   ```

2. Add it to the factory in `RegistrarAPI::make()`.

---

## License

MIT License. See [LICENSE](LICENSE) for details.

---

## Disclaimer

- This library is **not** affiliated with or endorsed by NameSilo, GoDaddy, Namecheap, Dynadot, or any other registrar.
- You are responsible for complying with each registrar’s API terms of service.
- Use sandbox or test environments before running operations on production domains.
