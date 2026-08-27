<?php

namespace FlatRate\SupabaseOAuth\Listeners;

use Flarum\User\Event\RegisteringFromProvider;

final class TrustVerifiedSupabaseEmail
{
    public function handle(RegisteringFromProvider $event): void
    {
        if ($event->provider !== 'flatrate') {
            return;
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        $verified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $providerEmail = trim((string) ($payload['email'] ?? ''));
        $userEmail = trim((string) $event->user->email);

        if (!$verified || $providerEmail === '' || $userEmail === '') {
            return;
        }

        // Activation is allowed only when the registration email exactly matches
        // the verified email returned by Supabase UserInfo. This happens after
        // the OAuth registration token is resolved, so it does not participate
        // in Flarum's pre-registration same-email account-linking behavior.
        if (strcasecmp($providerEmail, $userEmail) !== 0) {
            return;
        }

        $event->user->activate();
    }
}
