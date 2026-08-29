<?php

namespace FlatRate\SupabaseOAuth\Auth;

use FlatRate\SupabaseOAuth\Identity\NeutralIdentity;
use Flarum\User\Command\RegisterUser;
use Flarum\User\Command\RegisterUserHandler;
use Flarum\User\Guest;
use Flarum\User\LoginProvider;
use Flarum\User\RegistrationToken;
use Flarum\User\User;
use FoF\OAuth\Errors\AuthenticationException;
use Illuminate\Database\QueryException;
use RuntimeException;

final class FlatRateUserProvisioner
{
    public function __construct(private RegisterUserHandler $registerUser)
    {
    }

    public function ensure(string $sub, string $email, bool $emailVerified, array $payload = []): User
    {
        $sub = trim($sub);
        $email = trim($email);

        if ($sub === '') {
            throw new AuthenticationException('invalid_subject');
        }

        if ($email === '' || ! $emailVerified) {
            throw new AuthenticationException('verified_email_required');
        }

        if ($linked = $this->linkedUser($sub)) {
            return $linked;
        }

        // Email is an attribute, never the cross-system identity key. If an
        // unrelated local account already owns it, require the explicit legacy
        // linking flow instead of silently joining two identities.
        if (User::where('email', $email)->exists()) {
            throw new AuthenticationException('existing_account_requires_explicit_link');
        }

        $payload = array_merge($payload, [
            'sub' => $sub,
            'email' => $email,
            'email_verified' => true,
        ]);

        $username = NeutralIdentity::handle($sub);
        $connection = (new User())->getConnection();

        try {
            /** @var User $user */
            $user = $connection->transaction(function () use ($sub, $email, $payload, $username) {
                // Re-check inside the transaction so retries and concurrent
                // requests converge on an already-linked account when possible.
                if ($linked = $this->linkedUser($sub)) {
                    return $linked;
                }

                if (User::where('email', $email)->exists()) {
                    throw new AuthenticationException('existing_account_requires_explicit_link');
                }

                // The public default nickname is intentionally human-readable
                // and sequential. The immutable routing username remains the
                // hashed Supabase-sub handle above, and users may still edit
                // their nickname later through Flarum Nicknames.
                $userNumber = LoginProvider::where('provider', 'flatrate')->count() + 1;
                $nickname = NeutralIdentity::nickname($userNumber);

                $token = RegistrationToken::generate(
                    'flatrate',
                    $sub,
                    [
                        'username' => $username,
                        'email' => $email,
                        'nickname' => $nickname,
                    ],
                    $payload
                );
                $token->save();

                // Preserve Flarum core validation/events and the existing
                // RegisteringFromProvider listener. The provider identifier is
                // the immutable Supabase sub, so the resulting LoginProvider is
                // the durable cross-system link.
                return $this->registerUser->handle(new RegisterUser(
                    new Guest(),
                    [
                        'attributes' => [
                            'username' => $username,
                            'email' => $email,
                            'token' => $token->token,
                        ],
                    ]
                ));
            });

            return $user;
        } catch (QueryException $error) {
            // Unique constraints on the deterministic username/provider link
            // provide the final race barrier. If another request won, resolve
            // its committed provider row instead of creating a duplicate user.
            for ($attempt = 0; $attempt < 3; $attempt++) {
                if ($linked = $this->linkedUser($sub)) {
                    return $linked;
                }
                usleep(20000);
            }

            throw $error;
        }
    }

    private function linkedUser(string $sub): ?User
    {
        $provider = LoginProvider::where('provider', 'flatrate')
            ->where('identifier', $sub)
            ->first();

        if (! $provider) {
            return null;
        }

        $user = User::find($provider->user_id);
        if (! $user) {
            throw new RuntimeException('flatrate_provider_user_missing');
        }

        return $user;
    }
}
