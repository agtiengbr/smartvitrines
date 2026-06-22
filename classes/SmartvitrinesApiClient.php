<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

final class SmartvitrinesApiClient
{
    /** @var string */
    private $apiBaseUrl;

    public function __construct($apiBaseUrl)
    {
        $this->apiBaseUrl = (string) $apiBaseUrl;
    }

    /**
     * @return list<string>
     */
    public function getRecommendations($publicKey, $sku, $limit)
    {
        $publicKey = (string) $publicKey;
        $sku = (string) $sku;
        $limit = max(1, (int) $limit);
        if ($publicKey === '') {
            return [];
        }

        $query = [
            'public_key' => $publicKey,
            'limit' => $limit,
        ];
        if ($sku !== '') {
            $query['sku'] = $sku;
        }

        $url = rtrim($this->apiBaseUrl, '/') . '/v1/recommendations?' . http_build_query($query);

        $body = $this->httpGet($url);
        if ($body === null) {
            return [];
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [];
        }

        if (!is_array($decoded) || !isset($decoded['skus']) || !is_array($decoded['skus'])) {
            return [];
        }

        $skus = [];
        foreach ($decoded['skus'] as $item) {
            $value = trim((string) $item);
            if ($value !== '') {
                $skus[] = $value;
            }
        }

        return $skus;
    }

    public function postConversion($publicKey, $sessionId, $orderRef)
    {
        $url = rtrim($this->apiBaseUrl, '/') . '/v1/events/conversion';

        $payload = json_encode([
            'public_key' => (string) $publicKey,
            'session_id' => (string) $sessionId,
            'order_ref' => (string) $orderRef,
        ]);
        if ($payload === false) {
            return false;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return false;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
            ]);

            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $code === 202;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $payload,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return false;
        }

        if (!isset($http_response_header[0])) {
            return false;
        }

        return strpos($http_response_header[0], '202') !== false;
    }

    private function httpGet($url)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }

            curl_setopt_array($ch, [
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
            ]);

            $response = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $code !== 200) {
                return null;
            }

            return (string) $response;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\n",
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false || !isset($http_response_header[0]) || strpos($http_response_header[0], '200') === false) {
            return null;
        }

        return (string) $response;
    }
}
