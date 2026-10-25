<?php
use RegistrarAPI\RegistrarAPI;

$nc = RegistrarAPI::make('namecheap', ['api_user'=>'me','api_key'=>'K','client_ip'=>'203.0.113.1']);
$ok = function (string $inner) {
    return [200, '<?xml version="1.0" encoding="utf-8"?><ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response"><Errors /><CommandResponse>'.$inner.'</CommandResponse></ApiResponse>'];
};

FakeHttp::reply($ok('<DomainCheckResult Domain="free.com" Available="true" ErrorNo="0" /><DomainCheckResult Domain="taken.com" Available="false" ErrorNo="0" /><DomainCheckResult Domain="x.badtld" Available="false" ErrorNo="2030166" />'));
$r = $nc->checkAvailability(['free.com','taken.com','x.badtld']);
check('namecheap check ok', $r['ok']);
same('namecheap check results', [['free.com'],['taken.com'],['x.badtld']], [$r['available'],$r['unavailable'],$r['invalid']]);
$q = FakeHttp::query();
same('namecheap global params', ['me','me','203.0.113.1','namecheap.domains.check'], [$q['ApiUser'],$q['UserName'],$q['ClientIp'],$q['Command']]);

FakeHttp::reply([200, '<?xml version="1.0"?><ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response"><Errors><Error Number="1011102">API Key is invalid or API access has not been enabled</Error></Errors></ApiResponse>']);
$r = $nc->renewDomain('example.com', 1);
check('namecheap ERROR status is a failure, not an exception', !$r['ok'] && strpos($r['err'], 'API Key is invalid') !== false);

FakeHttp::reply([200, 'not xml']);
$r = $nc->getDomain('example.com');
check('namecheap invalid XML handled', !$r['ok'] && $r['err'] !== '');

FakeHttp::reply($ok('<DomainDNSGetHostsResult Domain="example.co.uk" IsUsingOurDNS="true"><host HostId="1" Name="@" Type="A" Address="1.2.3.4" MXPref="10" TTL="1800" /><host HostId="2" Name="@" Type="MX" Address="mx.example.co.uk" MXPref="20" TTL="1800" /></DomainDNSGetHostsResult>'));
$r = $nc->getDNS('example.co.uk');
same('namecheap SLD/TLD split', ['example','co.uk'], [FakeHttp::query()['SLD'], FakeHttp::query()['TLD']]);
same('namecheap records', [
    ['type'=>'A','host'=>'@','value'=>'1.2.3.4','ttl'=>1800,'prio'=>null,'id'=>'1'],
    ['type'=>'MX','host'=>'@','value'=>'mx.example.co.uk','ttl'=>1800,'prio'=>20,'id'=>'2'],
], $r['records']);

FakeHttp::reply(
    $ok('<DomainDNSGetHostsResult Domain="example.com"><host HostId="1" Name="@" Type="A" Address="1.2.3.4" MXPref="10" TTL="1800" /></DomainDNSGetHostsResult>'),
    $ok('<DomainDNSSetHostsResult Domain="example.com" IsSuccess="true" />')
);
$r = $nc->addDNS('example.com', ['type'=>'MX','host'=>'@','value'=>'mx.example.com','prio'=>5]);
check('namecheap addDNS ok', $r['ok']);
$q = FakeHttp::query();
same('namecheap addDNS keeps existing + appends', ['1.2.3.4','mx.example.com','5','MX'], [$q['Address1'],$q['Address2'],$q['MXPref2'],$q['EmailType']]);
same('namecheap setHosts is POST', 'POST', FakeHttp::last()['method']);

FakeHttp::reply(
    $ok('<DomainDNSGetHostsResult Domain="example.com"><host HostId="1" Name="@" Type="A" Address="1.2.3.4" TTL="1800" /><host HostId="2" Name="www" Type="CNAME" Address="example.com." TTL="1800" /></DomainDNSGetHostsResult>'),
    $ok('<DomainDNSSetHostsResult Domain="example.com" IsSuccess="true" />')
);
$r = $nc->delDNS('example.com', ['type'=>'CNAME','host'=>'www']);
same('namecheap delDNS deleted', 1, $r['deleted']);
check('namecheap delDNS kept the A record only', FakeHttp::query()['RecordType1'] === 'A' && !isset(FakeHttp::query()['RecordType2']));

$r = $nc->registerDomain('new.com', ['years'=>1]);
check('namecheap register refuses missing contact (no John Doe defaults)', !$r['ok'] && strpos($r['err'], 'registrant') !== false);

FakeHttp::reply($ok('<DomainCreateResult Domain="new.com" Registered="true" />'));
$reg = ['first_name'=>'Jane','last_name'=>'Doe','address'=>'1 St','city'=>'LA','state'=>'CA','zip'=>'90001','country'=>'US','phone'=>'+1.5555555555','email'=>'j@example.com'];
$r = $nc->registerDomain('new.com', ['years'=>1,'registrant'=>$reg,'privacy'=>true]);
$q = FakeHttp::query();
check('namecheap register ok', $r['ok']);
same('namecheap register contacts for all roles', ['Jane','Jane','Jane','Jane','yes'], [$q['RegistrantFirstName'],$q['TechFirstName'],$q['AdminFirstName'],$q['AuxBillingFirstName'],$q['AddFreeWhoisguard']]);

FakeHttp::reply($ok('<DomainDNSSetCustomResult Domain="example.com" Updated="true" />'));
$nc->setNameServers('example.com', ['ns1.x.com','ns2.x.com']);
same('namecheap setCustom', 'ns1.x.com,ns2.x.com', FakeHttp::query()['Nameservers']);
