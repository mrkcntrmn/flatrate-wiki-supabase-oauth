<?php

namespace FlatRate\SupabaseOAuth\Activity;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Outbound HMAC client for POST /api/internal/forum-activity.
 * Every attempt uses a fresh timestamp/nonce/signature.
 */
final class ActivityClient
{
    public const DEFAULT_TIMEOUT_SECONDS = 2.5;

    public function __construct(
        private SharedSecretResolver $secrets,
        private HmacSigner $signer,
        private SettingsRepositoryInterface $settings
    ) {
    }

    public function enabled(): bool
    {
        $env = strtolower(trim((string) getenv('FLATRATE_ACTIVITY_EMIT_ENABLED')));
        if (in_array($env, ['1', 'true', 'yes'], true)) {
            return true;
        }
        if (in_array($env, ['0', 'false', 'no'], true)) {
            return false;
        }

        return (bool) $this->settings->get('flatrate-activity.emit_enabled');
    }

    public function ingestUrl(): ?string
    {
        $env = trim((string) getenv('FLATRATE_ACTIVITY_INGEST_URL'));
        if ($env !== '') {
            return $this->validateUrl($env);
        }

        $setting = trim((string) $this->settings->get('flatrate-activity.ingest_url'));
        if ($setting !== '') {
            return $this->validateUrl($setting);
        }

        return null;
    }

    private function validateUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if ($scheme === 'https') {
            return $url;
        }
        if ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true)) {
            return $url;
        }

        return null;
    }

    /**
     * @return array{ok:bool,status:int,retryable:bool,error:?string}
     */
    public function postObservation(array $payload): array
    {
        $secret = $this->secrets->resolve();
        if (strlen($secret) < 32) {
            return ['ok' => false, 'status' => 0, 'retryable' => false, 'error' => 'secret_missing'];
        }

        $url = $this->ingestUrl();
        if ($url === null) {
            return ['ok' => false, 'status' => 0, 'retryable' => false, 'error' => 'url_missing'];
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '/api/internal/forum-activity';
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (! is_string($rawBody)) {
            return ['ok' => false, 'status' => 0, 'retryable' => false, 'error' => 'encode_failed'];
        }

        $timestamp = (string) time();
        $nonce = $this->signer->freshNonce();
        $signature = $this->signer->sign($secret, $timestamp, $nonce, 'POST', $path, $rawBody);

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
            return ['ok' => false, 'status' => 0, 'retryable' => true, 'error' => 'network_error'];
        }

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'status' => $status, 'retryable' => false, 'error' => null];
        }

        $retryable = $status === 429 || $status >= 500 || $status === 0;

        return [
            'ok' => false,
            'status' => $status,
            'retryable' => $retryable,
            'error' => 'http_'.$status,
        ];
    }
}
