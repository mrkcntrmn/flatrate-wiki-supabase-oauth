<?php

namespace FlatRate\SupabaseOAuth\Activity;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Resolve FORUM_SSO_SHARED_SECRET the same way as SharedSecretAuthenticator.
 */
final class SharedSecretResolver
{
    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function resolve(): string
    {
        $environmentSecret = trim((string) getenv('FORUM_SSO_SHARED_SECRET'));
        if (strlen($environmentSecret) >= 32) {
            return $environmentSecret;
        }

        return trim((string) $this->settings->get('fof-oauth.flatrate.sso_shared_secret'));
    }
}
