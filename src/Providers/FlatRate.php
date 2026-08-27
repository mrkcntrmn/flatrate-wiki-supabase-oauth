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
        return 'fas fa-wrench';
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

        // Deliberately suggest, rather than provide, the email. Flarum 1.8
        // auto-links a provider to an existing account when a provider marks an
        // email as "provided". FlatRate Wiki forbids heuristic cross-system
        // account linking by email; the immutable OAuth identifier is `sub`.
        $email = trim((string) ($payload['email'] ?? ''));
        if ($email !== '') {
            $registration->suggestEmail($email);
        }

        $registration
            ->suggestUsername($this->usernameSuggestion($payload, $sub))
            ->setPayload($payload);

        $picture = trim((string) ($payload['picture'] ?? ''));
        if ($picture !== '') {
            $this->provideAvatar($registration, $picture);
        }
    }

    private function usernameSuggestion(array $payload, string $sub): string
    {
        // Never derive the public forum username from email address or display
        // name. If the identity provider explicitly supplies a preferred public
        // username we may use that as a hint; otherwise use a neutral handle.
        $candidate = trim((string) ($payload['preferred_username'] ?? ''));
        $candidate = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '_', $candidate));
        $candidate = trim($candidate, '_-');

        if ($candidate === '') {
            $candidate = 'tech';
        }

        // Flarum ultimately owns the public username. This only produces a
        // collision-resistant suggestion; it is not the cross-system identity.
        $suffix = substr(hash('sha256', $sub), 0, 8);
        $prefix = substr($candidate, 0, 18);

        return $prefix.'_'.$suffix;
    }
}
