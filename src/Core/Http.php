<?php
namespace RegistrarAPI\Core;

final class Http {
    public static function get(string $url, array $headers=[], int $timeout=20): array {
        return self::req('GET', $url, null, $headers, $timeout);
    }
    public static function delete(string $url, array $headers=[], int $timeout=20): array {
        return self::req('DELETE', $url, null, $headers, $timeout);
    }
    public static function postJson(string $url, array $json, array $headers=[], int $timeout=30): array {
        $headers[] = 'Content-Type: application/json';
        return self::req('POST', $url, json_encode($json), $headers, $timeout);
    }
    public static function putJson(string $url, array $json, array $headers=[], int $timeout=30): array {
        $headers[] = 'Content-Type: application/json';
        return self::req('PUT', $url, json_encode($json), $headers, $timeout);
    }
    public static function postForm(string $url, array $form, array $headers=[], int $timeout=30): array {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        return self::req('POST', $url, http_build_query($form), $headers, $timeout);
    }
    private static function req(string $method, string $url, ?string $body, array $headers, int $timeout): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, $resp, $err];
    }
}
