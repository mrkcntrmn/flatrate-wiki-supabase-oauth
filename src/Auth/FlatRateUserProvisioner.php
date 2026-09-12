<?php

namespace FlatRate\SupabaseOAuth\Auth;

use FlatRate\SupabaseOAuth\Identity\ForumEmailPolicy;
use FlatRate\SupabaseOAuth\Identity\MemberIdentity;
use FlatRate\SupabaseOAuth\Identity\MemberProfileStore;
use FlatRate\SupabaseOAuth\Identity\NeutralIdentity;
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
    public function __construct(
        private RegisterUserHandler $registerUser,
        private MemberProfileStore $profiles
    ) {
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

        // Existing linked Community identities never require a tech_number and
        // must never trigger site-side 20031+ allocation on login.
        if ($linked = $this->linkedUser($sub)) {
            return $this->finishLinkedUser($linked, $email, $emailVerified);
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
        unset($payload['tech_number']);

        $username = NeutralIdentity::handle($sub);
        $temporaryNickname = $username;
        $connection = (new User())->getConnection();

        try {
            /** @var User $user */
            $user = $connection->transaction(function () use ($sub, $email, $emailVerified, $payload, $username, $temporaryNickname) {
                // Re-check inside the transaction so retries and concurrent
                // requests converge on an already-linked account when possible.
                if ($linked = $this->linkedUser($sub)) {
                    return $this->finishLinkedUser($linked, $email, $emailVerified);
                }

                if (User::where('email', $email)->exists()) {
                    throw new AuthenticationException('existing_account_requires_explicit_link');
                }

                $token = RegistrationToken::generate(
                    'flatrate',
                    $sub,
                    [
                        'username' => $username,
                        'email' => $email,
                        'nickname' => $temporaryNickname,
                    ],
                    $payload
                );
                $token->save();

                // Preserve Flarum core validation/events and the existing
                // RegisteringFromProvider listener. The provider identifier is
                // the immutable Supabase sub, so the resulting LoginProvider is
                // the durable cross-system link.
                $user = $this->registerUser->handle(new RegisterUser(
                    new Guest(),
                    [
                        'attributes' => [
                            'username' => $username,
                            'email' => $email,
                            'token' => $token->token,
                        ],
                    ]
                ));

                $memberNumber = MemberIdentity::memberNumber($user);
                $memberNickname = MemberIdentity::nickname($memberNumber);
                if ($this->nicknameOccupiedByOther($memberNickname, $memberNumber)) {
                    throw new SsoException('member_nickname_collision', 409);
                }

                $this->profiles->createForNewUser($user);
                $user->nickname = $memberNickname;
                $user->save();

                return $user;
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
                    return $this->finishLinkedUser($linked, $email, $emailVerified);
                }
                usleep(20000);
            }

            if ($this->isNicknameCollision($error)) {
                throw new SsoException('member_nickname_collision', 409);
            }

            throw $error;
        }
    }

    /**
     * Linked-user return path: email reconcile + deterministic profile self-heal.
     *
     * Nickname is unchanged unless the visible value is still the unfinished
     * temporary routing handle (username). Retries then converge to tech_#N.
     */
    private function finishLinkedUser(User $linked, string $email, bool $emailVerified): User
    {
        $user = $this->reconcileLinkedEmail($linked, $email, $emailVerified);
        $this->profiles->selfHeal($user);

        return $user->fresh() ?? $user;
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
     * Case-insensitive occupancy of a nickname by a different user.
     * Preflight is advisory; DB uniqueness remains the final barrier.
     */
    private function nicknameOccupiedByOther(string $nickname, int $userId): bool
    {
        $needle = strtolower($nickname);

        return User::query()
            ->whereRaw('LOWER(nickname) = ?', [$needle])
            ->where('id', '!=', $userId)
            ->exists();
    }

    private function isNicknameCollision(QueryException $error): bool
    {
        $message = strtolower($error->getMessage());

        return str_contains($message, 'nickname')
            && (str_contains($message, 'unique') || str_contains($message, 'duplicate'));
    }
}
