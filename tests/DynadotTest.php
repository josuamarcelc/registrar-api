<?php
use RegistrarAPI\RegistrarAPI;

$dd = RegistrarAPI::make('dynadot', ['api_key'=>'K']);

FakeHttp::reply([200, ['SearchResponse'=>['ResponseCode'=>'0','SearchResults'=>[
    ['DomainName'=>'free.com','Available'=>'yes'],['DomainName'=>'taken.com','Available'=>'no']]]]]);
$r = $dd->checkAvailability(['free.com','taken.com']);
check('dynadot search ok', $r['ok']);
same('dynadot search results', [['free.com'],['taken.com']], [$r['available'],$r['unavailable']]);
same('dynadot search params', ['free.com','taken.com','search'], [FakeHttp::query()['domain0'], FakeHttp::query()['domain1'], FakeHttp::query()['command']]);

FakeHttp::reply([200, ['RenewResponse'=>['ResponseCode'=>'-1','Status'=>'error','Error'=>'not enough account balance']]]);
$r = $dd->renewDomain('example.com', 1);
check('dynadot ResponseCode -1 is a failure', !$r['ok'] && $r['err'] === 'not enough account balance');

FakeHttp::reply([200, ['GetDnsResponse'=>['ResponseCode'=>'0','Status'=>'success','GetDns'=>['NameServerSettings'=>[
    'Type'=>'Dynadot DNS','TTL'=>'300',
    'MainDomains'=>[['RecordType'=>'A','Value'=>'1.2.3.4'],['RecordType'=>'MX','Value'=>'mx.example.com','Value2'=>'10']],
    'SubDomains'=>[['Subhost'=>'www','RecordType'=>'CNAME','Value'=>'example.com']]]]]]]);
$r = $dd->getDNS('example.com');
same('dynadot getDNS', [
    ['type'=>'A','host'=>'@','value'=>'1.2.3.4','ttl'=>300,'prio'=>null,'id'=>null],
    ['type'=>'MX','host'=>'@','value'=>'mx.example.com','ttl'=>300,'prio'=>10,'id'=>null],
    ['type'=>'CNAME','host'=>'www','value'=>'example.com','ttl'=>300,'prio'=>null,'id'=>null],
], $r['records']);

FakeHttp::reply([200, ['SetDnsResponse'=>['ResponseCode'=>'0','Status'=>'success']]]);
$r = $dd->addDNS('example.com', ['type'=>'TXT','host'=>'_dmarc.example.com','value'=>'v=DMARC1; p=none','ttl'=>600]);
$q = FakeHttp::query();
check('dynadot addDNS ok', $r['ok']);
same('dynadot addDNS uses set_dns2 + append', ['set_dns2','_dmarc','txt','1','600'], [$q['command'],$q['subdomain0'],$q['sub_record_type0'],$q['add_dns_to_current_setting'],$q['ttl']]);

FakeHttp::reply([200, ['SetNsResponse'=>['ResponseCode'=>'0','Status'=>'success']]]);
$dd->setNameServers('example.com', ['ns1.x.com','ns2.x.com']);
same('dynadot set_ns ns0/ns1', ['ns1.x.com','ns2.x.com'], [FakeHttp::query()['ns0'], FakeHttp::query()['ns1']]);
