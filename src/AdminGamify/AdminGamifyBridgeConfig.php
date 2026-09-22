<?php

namespace FlatRate\SupabaseOAuth\AdminGamify;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Dedicated ADMIN-GAMIFY bridge credentials.
 * Never reuse FORUM_SSO_SHARED_SECRET as analytics authority.
 */
final class AdminGamifyBridgeConfig
{
    public const DEFAULT_BASE_URL = 'https://flatrate.wiki';

    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function secret(): string
    {
        $environmentSecret = trim((string) getenv('ADMIN_GAMIFY_BRIDGE_SECRET'));
        if (strlen($environmentSecret) >= 32) {
            return $environmentSecret;
        }

        // Optional reviewed private setting fallback for operations only.
        return trim((string) $this->settings->get('flatrate-admin-gamify.bridge_secret'));
    }

    public function baseUrl(): string
    {
        $environmentUrl = trim((string) getenv('ADMIN_GAMIFY_BRIDGE_URL'));
        if ($environmentUrl !== '') {
            return rtrim($environmentUrl, '/');
        }

        $setting = trim((string) $this->settings->get('flatrate-admin-gamify.bridge_url'));
        if ($setting !== '') {
            return rtrim($setting, '/');
        }

        return self::DEFAULT_BASE_URL;
    }

    public function isConfigured(): bool
    {
        return strlen($this->secret()) >= 32;
    }
}
