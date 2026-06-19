<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

final class SmartvitrinesApiClient
{
    public function __construct(
        private string $apiBaseUrl,
    ) {}

    /**
     * @return list<string>
     */
    public function getRecommendations(string $publicKey, string $sku, int $limit = 4): array
    {
        if ($publicKey === '' || $sku === '') {
            return [];
        }

        $url = rtrim($this->apiBaseUrl, '/') . '/v1/recommendations?' . http_build_query([
            'public_key' => $publicKey,
            'sku' => $sku,
            'limit' => max(1, $limit),
        ]);

        $body = $this->httpGet($url);
        if ($body === null) {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
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

    public function postConversion(string $publicKey, string $sessionId, string $orderRef): bool
    {
        $url = rtrim($this->apiBaseUrl, '/') . '/v1/events/conversion';

        $payload = json_encode([
            'public_key' => $publicKey,
            'session_id' => $sessionId,
            'order_ref' => $orderRef,
        ], JSON_THROW_ON_ERROR);

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

        return str_contains($http_response_header[0], '202');
    }

    private function httpGet(string $url): ?string
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
        if ($response === false || !isset($http_response_header[0]) || !str_contains($http_response_header[0], '200')) {
            return null;
        }

        return (string) $response;
    }
}
