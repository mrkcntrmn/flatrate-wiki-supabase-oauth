<?php

namespace FlatRate\SupabaseOAuth\Activity;

use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Digest-only bearer authentication for the external outbox drain endpoint.
 * The forum stores SHA-256(hex) of the scheduler token, never the raw token.
 */
final class ActivityDrainAuthenticator
{
    public const SETTING_KEY = 'flatrate-activity.drain_token_sha256';
    public const ENV_KEY = 'FLATRATE_ACTIVITY_DRAIN_TOKEN_SHA256';

    public function __construct(
        private SettingsRepositoryInterface $settings
    ) {
    }

    public function configured(): bool
    {
        return $this->expectedDigest() !== null;
    }

    public function authenticate(ServerRequestInterface $request): bool
    {
        $expected = $this->expectedDigest();
        if ($expected === null) {
            return false;
        }

        $authorization = trim($request->getHeaderLine('Authorization'));

        if (! preg_match('/^Bearer ([A-Za-z0-9._~-]{32,256})$/D', $authorization, $matches)) {
            return false;
        }

        $actual = hash('sha256', $matches[1]);

        return hash_equals($expected, $actual);
    }

    private function expectedDigest(): ?string
    {
        $env = strtolower(trim((string) getenv(self::ENV_KEY)));

        if (preg_match('/^[a-f0-9]{64}$/D', $env)) {
            return $env;
        }

        $setting = strtolower(trim(
            (string) $this->settings->get(self::SETTING_KEY)
        ));

        if (preg_match('/^[a-f0-9]{64}$/D', $setting)) {
            return $setting;
        }

        return null;
    }
}
