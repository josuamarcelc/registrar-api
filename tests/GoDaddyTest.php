<?php
use RegistrarAPI\RegistrarAPI;

$gd = RegistrarAPI::make('godaddy', ['api_key'=>'K','api_secret'=>'S']);

FakeHttp::reply([200, ['available'=>true,'domain'=>'free.com']]);
$r = $gd->checkAvailability(['free.com']);
same('godaddy single check', ['free.com'], $r['available']);
same('godaddy single check is GET', 'GET', FakeHttp::last()['method']);
check('godaddy sso-key header', in_array('Authorization: sso-key K:S', FakeHttp::last()['headers'], true));

FakeHttp::reply([200, ['domains'=>[['domain'=>'a.com','available'=>true],['domain'=>'b.com','available'=>false]],'errors'=>[['domain'=>'c..com','code'=>'INVALID']]]]);
$r = $gd->checkAvailability(['a.com','b.com','c..com']);
same('godaddy bulk check is POST', 'POST', FakeHttp::last()['method']);
same('godaddy bulk body', ['a.com','b.com','c..com'], json_decode(FakeHttp::last()['body'], true));
same('godaddy bulk results', [['a.com'],['b.com'],['c..com']], [$r['available'],$r['unavailable'],$r['invalid']]);

$contact = ['nameFirst'=>'Jane','nameLast'=>'Doe','email'=>'j@example.com','phone'=>'+1.5555555555',
            'addressMailing'=>['address1'=>'1 St','city'=>'LA','state'=>'CA','postalCode'=>'90001','country'=>'US']];
FakeHttp::reply([200, [['agreementKey'=>'DNRA'],['agreementKey'=>'DNPA']]], [200, ['orderId'=>1]]);
$r = $gd->registerDomain('new.com', ['years'=>2,'registrant'=>$contact,'client_ip'=>'203.0.113.9']);
check('godaddy register ok', $r['ok']);
$c = FakeHttp::last(); $b = json_decode($c['body'], true);
check('godaddy register hits /domains/purchase', substr($c['url'], -17) === '/domains/purchase');
same('godaddy register agreement keys fetched', ['DNRA','DNPA'], $b['consent']['agreementKeys']);
same('godaddy register period', 2, $b['period']);
check('godaddy register has no bogus items key', !isset($b['items']));

$r = $gd->registerDomain('new.com', []);
check('godaddy register without contact fails locally', !$r['ok'] && count(FakeHttp::$calls) === 2);

FakeHttp::reply([200, [['type'=>'A','name'=>'@','data'=>'1.2.3.4','ttl'=>600],['type'=>'MX','name'=>'@','data'=>'mx.example.com','ttl'=>3600,'priority'=>10]]]);
$r = $gd->getDNS('example.com');
same('godaddy MX prio', 10, $r['records'][1]['prio']);

FakeHttp::reply([401, ['code'=>'UNABLE_TO_AUTHENTICATE','message'=>'Unauthorized']]);
$r = $gd->getDNS('example.com');
check('godaddy error object is not parsed as records', !$r['ok'] && $r['records'] === [] && $r['err'] === 'Unauthorized');

FakeHttp::reply([200, '']);
$gd->addDNS('example.com', ['type'=>'TXT','host'=>'example.com','value'=>'hello']);
same('godaddy addDNS is PATCH', 'PATCH', FakeHttp::last()['method']);
same('godaddy addDNS host relative', '@', json_decode(FakeHttp::last()['body'], true)[0]['name']);

FakeHttp::reply([204, '']);
$r = $gd->setNameServers('example.com', ['ns1.x.com','ns2.x.com']);
check('godaddy nameservers ok', $r['ok']);
same('godaddy nameservers via PATCH /domains/{d}', ['PATCH', ['nameServers'=>['ns1.x.com','ns2.x.com']]], [FakeHttp::last()['method'], json_decode(FakeHttp::last()['body'], true)]);

FakeHttp::reply(
    [200, [['type'=>'TXT','name'=>'@','data'=>'keep','ttl'=>600],['type'=>'TXT','name'=>'@','data'=>'drop','ttl'=>600]]],
    [200, '']
);
$r = $gd->delDNS('example.com', ['type'=>'TXT','host'=>'@','value'=>'drop']);
same('godaddy delDNS by value rewrites rrset', [['data'=>'keep','ttl'=>600]], json_decode(FakeHttp::last()['body'], true));
same('godaddy delDNS by value is PUT', 'PUT', FakeHttp::last()['method']);

$ote = RegistrarAPI::make('godaddy', ['api_key'=>'K','api_secret'=>'S','sandbox'=>true]);
FakeHttp::reply([200, []]);
$ote->getDomain('example.com');
check('godaddy sandbox base', strpos(FakeHttp::last()['url'], 'https://api.ote-godaddy.com/v1/') === 0);
