<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

final class SmartvitrinesApiClient
{
    public function __construct(
        private string $apiBaseUrl,
    ) {}

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
}
