<?php

namespace FlatRate\SupabaseOAuth\Auth;

use FlatRate\SupabaseOAuth\Identity\ForumEmailPolicy;
use FlatRate\SupabaseOAuth\Identity\NeutralIdentity;
use FlatRate\SupabaseOAuth\Identity\TechNumber;
use FlatRate\SupabaseOAuth\Sso\SsoException;
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

        // Existing linked Community identities never require tech_number and
        // must never trigger site-side allocation on login.
        if ($linked = $this->linkedUser($sub)) {
            return $this->reconcileLinkedEmail($linked, $email, $emailVerified);
        }

        // Email is an attribute, never the cross-system identity key. If an
        // unrelated local account already owns it, require the explicit legacy
        // linking flow instead of silently joining two identities. Do this
        // before requesting a tech number so blocked identities allocate zero.
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
            $user = $connection->transaction(function () use ($sub, $email, $emailVerified, $payload, $username) {
                // Re-check inside the transaction so retries and concurrent
                // requests converge on an already-linked account when possible.
                if ($linked = $this->linkedUser($sub)) {
                    return $this->reconcileLinkedEmail($linked, $email, $emailVerified);
                }

                if (User::where('email', $email)->exists()) {
                    throw new AuthenticationException('existing_account_requires_explicit_link');
                }

                // Forward-only: Supabase allocates immutable tech numbers.
                // Existing linked users return above without requiring this
                // field. New/unlinked users must present a signed tech_number
                // inside the HMAC body (parsed only after linkage checks).
                $techNumber = TechNumber::parseOptional($payload);
                if ($techNumber === null) {
                    throw new SsoException('tech_number_required', 409);
                }

                $nickname = NeutralIdentity::nickname($techNumber);
                if ($this->nicknameOccupied($nickname)) {
                    throw new SsoException('tech_number_nickname_collision', 409);
                }

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
        } catch (SsoException $error) {
            throw $error;
        } catch (QueryException $error) {
            // Unique constraints on the deterministic username/provider link
            // provide the final race barrier. If another request won, resolve
            // its committed provider row instead of creating a duplicate user.
            for ($attempt = 0; $attempt < 3; $attempt++) {
                if ($linked = $this->linkedUser($sub)) {
                    return $this->reconcileLinkedEmail($linked, $email, $emailVerified);
                }
                usleep(20000);
            }

            if ($this->isNicknameCollision($error)) {
                throw new SsoException('tech_number_nickname_collision', 409);
            }

            throw $error;
        }
    }

    /**
     * One-way lazy promotion for an already-linked Flarum user.
     *
     * Allowed mutation: reserved internal placeholder -> confirmed real email.
     * Never: real -> placeholder, real A -> real B, or identity/provider changes.
     *
     * The explicit collision exists() check is advisory only. The unique DB
     * constraint on users.email is the final concurrency barrier; a racing
     * claim of the target address must preserve the placeholder-backed user
     * and Community access rather than escaping as an SSO failure.
     */
    private function reconcileLinkedEmail(User $linked, string $incomingEmail, bool $incomingEmailVerified): User
    {
        $connection = $linked->getConnection();

        try {
            return $connection->transaction(function () use ($linked, $incomingEmail, $incomingEmailVerified) {
                /** @var User|null $user */
                $user = User::query()
                    ->whereKey($linked->id)
                    ->lockForUpdate()
                    ->first();

                if (! $user) {
                    throw new RuntimeException('flatrate_provider_user_missing');
                }

                $currentEmail = trim((string) $user->email);

                if (! ForumEmailPolicy::canPromote($currentEmail, $incomingEmail, $incomingEmailVerified)) {
                    return $user;
                }

                $collision = User::query()
                    ->where('email', $incomingEmail)
                    ->where('id', '!=', $user->id)
                    ->exists();

                if ($collision) {
                    // Preserve the linked placeholder identity and Community access.
                    // Explicit reconciliation is required; do not create/merge users.
                    return $user;
                }

                $user->changeEmail($incomingEmail);
                $user->activate();
                $user->save();

                return $user;
            });
        } catch (QueryException $error) {
            return $this->recoverLinkedUserAfterPromotionRace($linked, $incomingEmail, $error);
        }
    }

    /**
     * After promotion-save rollback, preserve the linked placeholder only when
     * state proves an email-ownership race. Unrelated QueryExceptions rethrow.
     */
    private function recoverLinkedUserAfterPromotionRace(
        User $linked,
        string $incomingEmail,
        QueryException $error
    ): User {
        $persisted = User::find($linked->id);
        if (! $persisted) {
            throw $error;
        }

        $incomingOwnedByAnotherUser = User::query()
            ->where('email', $incomingEmail)
            ->where('id', '!=', $persisted->id)
            ->exists();

        if (ForumEmailPolicy::isPreservablePromotionRace(
            (string) $persisted->email,
            $incomingOwnedByAnotherUser
        )) {
            return $persisted;
        }

        throw $error;
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

    /**
     * Case-insensitive occupancy of the reserved numeric nickname namespace.
     * Preflight is advisory; DB uniqueness remains the final barrier.
     */
    private function nicknameOccupied(string $nickname): bool
    {
        $needle = strtolower($nickname);

        return User::query()
            ->whereRaw('LOWER(nickname) = ?', [$needle])
            ->exists();
    }

    private function isNicknameCollision(QueryException $error): bool
    {
        $message = strtolower($error->getMessage());

        return str_contains($message, 'nickname')
            && (str_contains($message, 'unique') || str_contains($message, 'duplicate'));
    }
}
