<?php
namespace RegistrarAPI\adapters;

use RegistrarAPI\Core\BaseAdapter;

class Namecheap extends BaseAdapter
{
    protected $endpoint = 'https://api.namecheap.com/xml.response';
    protected $apiUser;
    protected $clientIp;

    public function __construct(array $config)
    {
        parent::__construct($config);

        $this->apiUser  = $config['api_user'] ?? '';
        $this->apiKey   = $config['api_key'] ?? '';
        $this->clientIp = $config['client_ip'] ?? '';
    }

    protected function request($command, array $params = [])
    {
        $query = array_merge([
            'ApiUser'   => $this->apiUser,
            'ApiKey'    => $this->apiKey,
            'UserName'  => $this->apiUser,
            'ClientIp'  => $this->clientIp,
            'Command'   => $command
        ], $params);

        $url = $this->endpoint . '?' . http_build_query($query);
        $response = $this->http->get($url);

        return $this->parseResponse($response);
    }

    protected function parseResponse($xml)
    {
        $parsed = @simplexml_load_string($xml);
        if ($parsed === false) {
            throw new \Exception("Invalid XML from Namecheap");
        }

        if ((string)$parsed['Status'] !== 'OK') {
            $errors = [];
            foreach ($parsed->Errors->Error as $err) {
                $errors[] = (string)$err;
            }
            throw new \Exception("Namecheap API error: " . implode('; ', $errors));
        }

        return $parsed;
    }

    public function checkAvailability($domain)
    {
        $res = $this->request('namecheap.domains.check', [
            'DomainList' => $domain
        ]);

        return strtolower((string)$res->CommandResponse->DomainCheckResult['Available']) === 'true';
    }

    public function registerDomain($domain, $years, array $params = [])
    {
        $defaults = [
            'DomainName'   => $domain,
            'Years'        => $years,
            'RegistrantFirstName' => $params['RegistrantFirstName'] ?? 'John',
            'RegistrantLastName'  => $params['RegistrantLastName'] ?? 'Doe',
            'RegistrantAddress1'  => $params['RegistrantAddress1'] ?? '123 Example Street',
            'RegistrantCity'      => $params['RegistrantCity'] ?? 'City',
            'RegistrantStateProvince' => $params['RegistrantStateProvince'] ?? 'CA',
            'RegistrantPostalCode' => $params['RegistrantPostalCode'] ?? '90001',
            'RegistrantCountry'    => $params['RegistrantCountry'] ?? 'US',
            'RegistrantPhone'      => $params['RegistrantPhone'] ?? '+1.5555555555',
            'RegistrantEmailAddress' => $params['RegistrantEmailAddress'] ?? 'email@example.com'
        ];

        return $this->request('namecheap.domains.create', $defaults);
    }

    public function renewDomain($domain, $years)
    {
        return $this->request('namecheap.domains.renew', [
            'DomainName' => $domain,
            'Years'      => $years
        ]);
    }

    public function setNameServers($domain, array $nameservers)
    {
        return $this->request('namecheap.domains.dns.setCustom', [
            'SLD'        => $this->getSLD($domain),
            'TLD'        => $this->getTLD($domain),
            'NameServers'=> implode(',', $nameservers)
        ]);
    }

    public function getDNSRecords($domain)
    {
        return $this->request('namecheap.domains.dns.getHosts', [
            'SLD' => $this->getSLD($domain),
            'TLD' => $this->getTLD($domain)
        ]);
    }

    public function updateDNSRecords($domain, array $records)
    {
        $params = [
            'SLD' => $this->getSLD($domain),
            'TLD' => $this->getTLD($domain)
        ];

        $i = 1;
        foreach ($records as $record) {
            $params["HostName{$i}"] = $record['name'];
            $params["RecordType{$i}"] = $record['type'];
            $params["Address{$i}"] = $record['value'];
            $params["TTL{$i}"] = $record['ttl'] ?? '300';
            $i++;
        }

        return $this->request('namecheap.domains.dns.setHosts', $params);
    }

    protected function getSLD($domain)
    {
        $parts = explode('.', $domain, 2);
        return $parts[0];
    }

    protected function getTLD($domain)
    {
        $parts = explode('.', $domain, 2);
        return $parts[1] ?? '';
    }
}
