<?php

namespace FlatRate\SupabaseOAuth\Canary;

use FlatRate\SupabaseOAuth\Identity\NeutralIdentity;
use FlatRate\SupabaseOAuth\Sso\SsoException;
use Flarum\Api\Client;
use Flarum\Group\Group;
use Flarum\User\LoginProvider;
use Flarum\User\RegistrationToken;
use Flarum\User\User;

/**
 * Provisions through Flarum 2's user resource and registration token.
 * Public signup stays closed: the internal request acts as an administrator,
 * and the HTTP route itself remains HMAC-gated.
 */
final class IdentityProvisioner
{
    public function __construct(private Client $api)
    {
    }

    public function ensure(string $sub, string $email, bool $verified): User
    {
        $sub = trim($sub);
        $email = trim($email);

        if ($sub === '' || strlen($sub) > 128 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $sub)) {
            throw new SsoException('missing_subject', 400);
        }

        if (! $verified || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new SsoException('unverified_email', 403);
        }

        $linked = $this->linkedUser($sub);
        if ($linked) {
            $this->assertCanAuthenticate($linked);

            return $linked;
        }

        if (User::query()->where('email', $email)->exists()) {
            throw new SsoException('existing_account_requires_explicit_link', 409);
        }

        $created = $this->registerThroughCore($sub, $email, $verified);
        $linked = $this->linkedUser($sub);
        if (! $linked) {
            throw new SsoException('provider_link_incomplete', 409);
        }

        if ($created && (int) $created->id !== (int) $linked->id) {
            $this->deleteOrphan($created);
        }

        $this->assertCanAuthenticate($linked);

        return $linked;
    }

    private function registerThroughCore(string $sub, string $email, bool $verified): ?User
    {
        $admin = $this->administrator();
        $username = NeutralIdentity::handle($sub);
        if (! preg_match('/^(?![0-9]*$)[a-z0-9_-]{3,30}$/i', $username)) {
            throw new SsoException('identity_handle_invalid', 500);
        }
        $token = RegistrationToken::generate('flatrate', $sub, [
            'username' => $username,
            'email' => $email,
        ], [
            'email' => $email,
            'email_verified' => $verified,
        ]);
        $token->save();

        try {
            $response = $this->api
                ->withActor($admin)
                ->withoutErrorHandling()
                ->withBody([
                    'data' => [
                        'type' => 'users',
                        'attributes' => [
                            'username' => $username,
                            'token' => $token->token,
                        ],
                    ],
                ])
                ->post('/users');
            $status = $response->getStatusCode();
            $payload = json_decode((string) $response->getBody(), true);
        } catch (\Throwable) {
            $status = 0;
            $payload = null;
        }

        $createdId = is_array($payload) ? (int) ($payload['data']['id'] ?? 0) : 0;
        if ($status >= 200 && $status < 300 && $createdId > 0) {
            return User::find($createdId);
        }

        $linked = $this->awaitLinked($sub);
        if ($linked) {
            return null;
        }

        throw new SsoException('provisioning_rejected', 409);
    }

    private function awaitLinked(string $sub): ?User
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $linked = $this->linkedUser($sub);
            if ($linked) {
                return $linked;
            }
            usleep(50000);
        }

        return null;
    }

    private function administrator(): User
    {
        $admin = User::query()
            ->whereHas('groups', function ($query) {
                $query->where('groups.id', Group::ADMINISTRATOR_ID);
            })
            ->orderBy('users.id')
            ->first();

        if (! $admin instanceof User || ! $admin->isAdmin()) {
            throw new SsoException('provisioning_unavailable', 503);
        }

        return $admin;
    }

    private function linkedUser(string $sub): ?User
    {
        $provider = LoginProvider::query()
            ->where('provider', 'flatrate')
            ->where('identifier', $sub)
            ->first();

        if (! $provider) {
            return null;
        }

        $user = User::find($provider->user_id);
        if (! $user) {
            throw new SsoException('flatrate_provider_user_missing', 409);
        }

        return $user;
    }

    private function assertCanAuthenticate(User $user): void
    {
        if (Suspension::active($user)) {
            throw new SsoException('suspended_user', 403);
        }
    }

    private function deleteOrphan(User $orphan): void
    {
        $this->api
            ->withActor($this->administrator())
            ->withoutErrorHandling()
            ->delete('/users/'.$orphan->id);
    }
}
