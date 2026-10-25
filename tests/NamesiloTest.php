<?php
use RegistrarAPI\RegistrarAPI;

$ns = RegistrarAPI::make('namesilo', ['api_key'=>'KEY']);

FakeHttp::reply([200, ['reply'=>['code'=>300,'detail'=>'success',
    'available'=>[['domain'=>'free.com','price'=>9.95]], 'unavailable'=>['domain'=>'taken.com'], 'invalid'=>'bad..com']]]);
$r = $ns->checkAvailability(['free.com','taken.com','bad..com']);
check('namesilo check ok', $r['ok']);
same('namesilo available', ['free.com'], $r['available']);
same('namesilo unavailable', ['taken.com'], $r['unavailable']);
same('namesilo invalid', ['bad..com'], $r['invalid']);
same('namesilo uses key', 'KEY', FakeHttp::query()['key']);

FakeHttp::reply([200, ['reply'=>['code'=>110,'detail'=>'Invalid API Key']]]);
$r = $ns->renewDomain('example.com', 2);
check('namesilo reply code 110 is a failure even on HTTP 200', !$r['ok']);
same('namesilo error detail', 'Invalid API Key', $r['err']);

FakeHttp::reply([200, ['reply'=>['code'=>300,'resource_record'=>
    ['record_id'=>'abc','type'=>'A','host'=>'example.com','value'=>'1.2.3.4','ttl'=>'7207','distance'=>'0']]]]);
$r = $ns->getDNS('example.com');
same('namesilo single record list', 1, count($r['records']));
same('namesilo record normalised', ['type'=>'A','host'=>'@','value'=>'1.2.3.4','ttl'=>7207,'prio'=>0,'id'=>'abc'], $r['records'][0]);

FakeHttp::reply(
    [200, ['reply'=>['code'=>300,'resource_record'=>[
        ['record_id'=>'r1','type'=>'TXT','host'=>'example.com','value'=>'old','ttl'=>3600],
        ['record_id'=>'r2','type'=>'A','host'=>'www.example.com','value'=>'1.1.1.1','ttl'=>3600]]]]],
    [200, ['reply'=>['code'=>300]]]
);
$r = $ns->delDNS('example.com', ['type'=>'TXT','host'=>'@']);
check('namesilo delDNS by selector ok', $r['ok']);
same('namesilo delDNS deleted', 1, $r['deleted']);
same('namesilo delDNS sends record id', 'r1', FakeHttp::query()['rrid']);

FakeHttp::reply(
    [200, ['reply'=>['code'=>300,'resource_record'=>[['record_id'=>'r1','type'=>'A','host'=>'example.com','value'=>'1.1.1.1','ttl'=>3600]]]]],
    [200, ['reply'=>['code'=>300]]],
    [200, ['reply'=>['code'=>300,'record_id'=>'new']]]
);
$r = $ns->setDNS('example.com', [['type'=>'A','host'=>'www','value'=>'2.2.2.2']]);
check('namesilo setDNS ok', $r['ok']);
same('namesilo setDNS deletes existing by id', 'r1', FakeHttp::query(1)['rrid']);
same('namesilo addDNS host is relative', 'www', FakeHttp::query(2)['rrhost']);

FakeHttp::reply([200, ['reply'=>['code'=>300]]]);
$ns->addDNS('example.com', ['type'=>'TXT','host'=>'@','value'=>'v=spf1 -all']);
same('namesilo apex host is empty', '', FakeHttp::query()['rrhost']);

FakeHttp::reply([200, ['reply'=>['code'=>300]]]);
$ns->setNameServers('example.com', ['ns1.host.com','ns2.host.com']);
$q = FakeHttp::query();
check('namesilo nameservers as ns1/ns2', ($q['ns1'] ?? '') === 'ns1.host.com' && ($q['ns2'] ?? '') === 'ns2.host.com');

FakeHttp::reply([200, ['reply'=>['code'=>300]]]);
$r = $ns->raw('listDomains');
check('namesilo raw does not leak the key', !isset($r['endpoint']));
