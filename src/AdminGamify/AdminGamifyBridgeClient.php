<?php

namespace FlatRate\SupabaseOAuth\AdminGamify;

use FlatRate\SupabaseOAuth\Activity\HmacSigner;

/**
 * Server-to-server HMAC client for FlatRate admin-gamify internal POSTs.
 */
final class AdminGamifyBridgeClient
{
    public const DEFAULT_TIMEOUT_SECONDS = 3.0;

    public const PATHS = [
        'overview' => '/api/internal/admin-gamify/overview',
        'sharing' => '/api/internal/admin-gamify/sharing',
        'referrals' => '/api/internal/admin-gamify/referrals',
        'test_session_start' => '/api/internal/admin-gamify/test-session/start',
        'test_session_end' => '/api/internal/admin-gamify/test-session/end',
        'test_session_status' => '/api/internal/admin-gamify/test-session/status',
        'test_share_create' => '/api/internal/admin-gamify/test-share/create',
        'test_share_status' => '/api/internal/admin-gamify/test-share/status',
        'test_launch_create' => '/api/internal/admin-gamify/test-launch/create',
    ];

    public function __construct(
        private AdminGamifyBridgeConfig $config,
        private HmacSigner $signer
    ) {
    }

    /**
     * @param array<string, mixed> $body
     * @return array{ok: bool, status: int, body: ?array, error: ?string}
     */
    public function post(string $routeKey, array $body): array
    {
        if (! isset(self::PATHS[$routeKey])) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'unknown_route'];
        }
        if (! $this->config->isConfigured()) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'bridge_not_configured'];
        }

        $path = self::PATHS[$routeKey];
        $url = $this->config->baseUrl().$path;
        $rawBody = json_encode($body, JSON_UNESCAPED_SLASHES);
        if (! is_string($rawBody)) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'encode_failed'];
        }

        $timestamp = (string) time();
        $nonce = $this->signer->freshNonce();
        $signature = $this->signer->sign(
            $this->config->secret(),
            $timestamp,
            $nonce,
            'POST',
            $path,
            $rawBody
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'X-FlatRate-Timestamp: '.$timestamp,
                    'X-FlatRate-Nonce: '.$nonce,
                    'X-FlatRate-Signature: v1='.$signature,
                ]),
                'content' => $rawBody,
                'timeout' => self::DEFAULT_TIMEOUT_SECONDS,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        if ($response === false && $status === 0) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'bridge_unavailable'];
        }

        $decoded = null;
        if (is_string($response) && $response !== '') {
            $parsed = json_decode($response, true);
            if (is_array($parsed)) {
                $decoded = $parsed;
            }
        }

        if ($status >= 200 && $status < 300 && is_array($decoded)) {
            return ['ok' => true, 'status' => $status, 'body' => $decoded, 'error' => null];
        }

        return [
            'ok' => false,
            'status' => $status,
            'body' => $decoded,
            'error' => is_array($decoded) && isset($decoded['error'])
                ? (string) $decoded['error']
                : 'http_'.$status,
        ];
    }
}
