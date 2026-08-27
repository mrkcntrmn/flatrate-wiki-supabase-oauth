<?php

namespace FlatRate\SupabaseOAuth\Providers;

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
        // rather than provide, it so Flarum never heuristically links an existing
        // account solely because the email address happens to match.
        $email = trim((string) ($payload['email'] ?? ''));
        if ($email !== '') {
            $registration->suggestEmail($email);
        }

        $handle = $this->neutralHandle($sub);

        $registration
            // Flarum's username is exposed in routes/API, so it must never be the
            // email address. Keep it as an opaque, stable public handle instead.
            ->suggestUsername($handle)
            // Flarum Nicknames owns the user-configurable public display name.
            // Start neutral and let the member change it from Settings.
            ->suggest('nickname', $this->neutralNickname($sub))
            ->setPayload($payload);

        $picture = trim((string) ($payload['picture'] ?? ''));
        if ($picture !== '') {
            $this->provideAvatar($registration, $picture);
        }
    }

    private function neutralHandle(string $sub): string
    {
        return 'tech_'.substr(hash('sha256', $sub), 0, 8);
    }

    private function neutralNickname(string $sub): string
    {
        return 'Tech '.strtoupper(substr(hash('sha256', $sub), 0, 4));
    }
}
