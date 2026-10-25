<?php
use RegistrarAPI\RegistrarAPI;

$cf = RegistrarAPI::make('cloudflare', ['api_token'=>'T']);
$ok = function ($result, array $extra = []) { return [200, ['success'=>true,'errors'=>[],'result'=>$result] + $extra]; };

check('cloudflare check is unsupported, not a crash', ($cf->checkAvailability(['a.com'])['unsupported'] ?? false) === true);
check('cloudflare setNameServers unsupported', !$cf->setNameServers('a.com', ['ns1'])['ok']);

FakeHttp::reply($ok([['id'=>'Z1','name'=>'example.com']]),
    $ok([['id'=>'d1','type'=>'A','name'=>'example.com','content'=>'1.2.3.4','ttl'=>1,'proxied'=>true],
         ['id'=>'d2','type'=>'TXT','name'=>'www.example.com','content'=>'hi','ttl'=>300,'proxied'=>false]], ['result_info'=>['total_pages'=>1]]));
$r = $cf->getDNS('example.com');
check('cloudflare getDNS ok', $r['ok']);
same('cloudflare bearer header', 'Authorization: Bearer T', FakeHttp::$calls[0]['headers'][0]);
same('cloudflare records', [['type'=>'A','host'=>'@','value'=>'1.2.3.4','ttl'=>1,'prio'=>null,'id'=>'d1','proxied'=>true],
                            ['type'=>'TXT','host'=>'www','value'=>'hi','ttl'=>300,'prio'=>null,'id'=>'d2','proxied'=>false]], $r['records']);

// zone id is cached now
FakeHttp::reply($ok(['id'=>'new1']));
$r = $cf->addDNS('example.com', ['type'=>'CNAME','host'=>'blog','value'=>'example.com','proxied'=>true]);
same('cloudflare addDNS id', 'new1', $r['id']);
same('cloudflare addDNS fqdn name', 'blog.example.com', json_decode(FakeHttp::last()['body'], true)['name']);

FakeHttp::reply([403, ['success'=>false,'errors'=>[['code'=>10000,'message'=>'Authentication error']],'result'=>null]]);
$r = $cf->raw('user/tokens/verify');
check('cloudflare error surfaced', !$r['ok'] && $r['err'] === 'Authentication error');

FakeHttp::reply($ok(['id'=>'Z1','name'=>'example.com']), $ok([]), $ok(['id'=>'r9']));
$cf2 = RegistrarAPI::make('cf', ['api_token'=>'T']);
$cf2->ensureDnsRecord('Z1', 'A', 'www', '1.2.3.4');
same('cloudflare ensureDnsRecord looks up the full name', 'www.example.com', FakeHttp::query(1)['name']);

FakeHttp::reply([400, ['success'=>false,'errors'=>[['message'=>'bad']]]]);
try { $cf->purgeEverything('Z1'); check('zone helpers still throw', false); } catch (RuntimeException $e) { check('zone helpers still throw', true); }

$urls = []; for ($i = 0; $i < 31; $i++) $urls[] = "https://example.com/$i";
FakeHttp::reply($ok([]), $ok([]));
$cf->purgeByUrls('Z1', $urls);
same('cloudflare purgeByUrls chunks by 30', 2, count(FakeHttp::$calls));
