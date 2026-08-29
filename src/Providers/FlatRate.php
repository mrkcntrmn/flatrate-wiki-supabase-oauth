<?php

namespace FlatRate\SupabaseOAuth\Providers;

use FlatRate\SupabaseOAuth\Identity\NeutralIdentity;
use Flarum\Forum\Auth\Registration;
use FoF\OAuth\Provider;
use InvalidArgumentException;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\GenericProvider;
use RuntimeException;

final class FlatRate extends Provider
{
    public function name(): string
    {
        return 'flatrate';
    }

    public function icon(): string
    {
        return 'fas fa-sign-in-alt';
    }

    public function link(): string
    {
        return 'https://supabase.com/dashboard/';
    }

    public function fields(): array
    {
        return [
            'project_url' => 'required|url',
            'client_id' => 'required',
            'client_secret' => 'required',
            // Managed hosts do not always expose arbitrary environment variables.
            // This private Flarum setting is therefore an optional fallback for
            // the server-to-server SSO bridge secret. Environment configuration
            // remains preferred when it is available.
            'sso_shared_secret' => 'nullable|string|min:32|max:512',
        ];
    }

    public function provider(string $redirectUri): AbstractProvider
    {
        $projectUrl = rtrim($this->getSetting('project_url'), '/');
        if (!str_starts_with($projectUrl, 'https://')) {
            throw new InvalidArgumentException('Supabase project URL must use HTTPS.');
        }

        return new GenericProvider([
            'clientId' => $this->getSetting('client_id'),
            'clientSecret' => $this->getSetting('client_secret'),
            'redirectUri' => $redirectUri,
            'urlAuthorize' => $projectUrl.'/auth/v1/oauth/authorize',
            'urlAccessToken' => $projectUrl.'/auth/v1/oauth/token',
            'urlResourceOwnerDetails' => $projectUrl.'/auth/v1/oauth/userinfo',
            'scopes' => ['openid', 'email', 'profile'],
            'scopeSeparator' => ' ',
            'responseResourceOwnerId' => 'sub',
            'pkceMethod' => AbstractProvider::PKCE_METHOD_S256,
        ]);
    }

    public function options(): array
    {
        return [
            'scope' => ['openid', 'email', 'profile'],
        ];
    }

    public function suggestions(Registration $registration, $user, string $token)
    {
        $payload = $user->toArray();
        $sub = trim((string) ($payload['sub'] ?? $user->getId() ?? ''));

        if ($sub === '') {
            throw new RuntimeException('Supabase UserInfo response is missing sub.');
        }

        // Email remains the private account/login address. Deliberately suggest,
        // rather than provide, it so upstream Flarum behavior can never
        // heuristically join an existing account solely because email matches.
        // FlatRate's response factory separately performs verified, silent
        // provisioning for genuinely new identities.
        $email = trim((string) ($payload['email'] ?? ''));
        if ($email !== '') {
            $registration->suggestEmail($email);
        }

        $registration
            // Flarum's username is exposed in routes/API, so it must never be the
            // email address. Keep it as an opaque, stable public handle instead.
            ->suggestUsername(NeutralIdentity::handle($sub))
            // Do not synthesize the public nickname here. The reusable provisioner
            // allocates tech_<user count> while holding the provider-row lock, so
            // the ticket bridge and OAuth fallback share one race-safe sequence.
            ->setPayload($payload);

        $picture = trim((string) ($payload['picture'] ?? ''));
        if ($picture !== '') {
            $this->provideAvatar($registration, $picture);
        }
    }
}
