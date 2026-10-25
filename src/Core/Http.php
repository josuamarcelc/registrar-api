<?php
namespace RegistrarAPI\Core;

final class Http {
    /** @var callable|null fn(string $method, string $url, ?string $body, array $headers, int $timeout): array [code, body, err] */
    private static $transport = null;

    /** Swap the cURL transport (tests, logging, proxies). Pass null to restore cURL. */
    public static function setTransport(?callable $transport): void { self::$transport = $transport; }

    public static function get(string $url, array $headers=[], int $timeout=20): array {
        return self::req('GET', $url, null, $headers, $timeout);
    }
    public static function delete(string $url, array $headers=[], int $timeout=20): array {
        return self::req('DELETE', $url, null, $headers, $timeout);
    }
    public static function postJson(string $url, $json, array $headers=[], int $timeout=30): array {
        return self::req('POST', $url, self::encode($json), array_merge($headers, ['Content-Type: application/json']), $timeout);
    }
    public static function putJson(string $url, $json, array $headers=[], int $timeout=30): array {
        return self::req('PUT', $url, self::encode($json), array_merge($headers, ['Content-Type: application/json']), $timeout);
    }
    public static function patchJson(string $url, $json, array $headers=[], int $timeout=30): array {
        return self::req('PATCH', $url, self::encode($json), array_merge($headers, ['Content-Type: application/json']), $timeout);
    }
    public static function postForm(string $url, array $form, array $headers=[], int $timeout=30): array {
        return self::req('POST', $url, http_build_query($form), array_merge($headers, ['Content-Type: application/x-www-form-urlencoded']), $timeout);
    }

    private static function encode($json): string {
        return json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function req(string $method, string $url, ?string $body, array $headers, int $timeout): array {
        if (self::$transport !== null) {
            return (self::$transport)($method, $url, $body, $headers, $timeout);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'registrar-api-php',
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (PHP_VERSION_ID < 80000) curl_close($ch);
        return [$code, $resp === false ? '' : (string)$resp, $err];
    }
}
