<?php
use RegistrarAPI\RegistrarAPI;
use RegistrarAPI\Core\BaseAdapter;

$creds = [
    'namesilo'   => ['api_key'=>'k'],
    'godaddy'    => ['api_key'=>'k','api_secret'=>'s'],
    'namecheap'  => ['api_user'=>'u','api_key'=>'k','client_ip'=>'203.0.113.1'],
    'dynadot'    => ['api_key'=>'k'],
    'cloudflare' => ['api_token'=>'t'],
    'cf'         => ['api_token'=>'t'],
    'Name-Silo'  => ['api_key'=>'k'],
];
foreach ($creds as $brand => $c) {
    $a = RegistrarAPI::make($brand, $c);
    check("make($brand) returns a BaseAdapter", $a instanceof BaseAdapter);
}
same('brands()', ['Cloudflare','Namesilo','GoDaddy','Namecheap','Dynadot'], RegistrarAPI::brands());

try { RegistrarAPI::make('nope', []); check('unknown brand throws', false); } catch (InvalidArgumentException $e) { check('unknown brand throws', true); }
try { RegistrarAPI::make('namecheap', ['api_key'=>'k']); check('missing creds throw', false); } catch (InvalidArgumentException $e) { check('missing creds throw', strpos($e->getMessage(), 'api_user') !== false); }

use RegistrarAPI\Core\DnsRecord;
same('relativeHost apex', '@', DnsRecord::relativeHost('example.com', 'example.com'));
same('relativeHost fqdn', 'www', DnsRecord::relativeHost('WWW.example.com.', 'example.com'));
same('relativeHost relative', 'mail', DnsRecord::relativeHost('mail', 'example.com'));
same('relativeHost empty', '@', DnsRecord::relativeHost('', 'example.com'));
check('matches', DnsRecord::matches(['type'=>'TXT','host'=>'@','value'=>'v'], ['type'=>'txt','host'=>'@']));
check('matches value mismatch', !DnsRecord::matches(['type'=>'TXT','host'=>'@','value'=>'v'], ['value'=>'x']));
