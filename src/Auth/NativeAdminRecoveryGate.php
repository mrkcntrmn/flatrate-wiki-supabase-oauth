<?php

declare(strict_types=1);

namespace FlatRate\SupabaseOAuth\Auth;

/**
 * Allow or refuse native Flarum password login and native signup.
 *
 * Password login is only the unadvertised administrator recovery path.
 * Ordinary accounts must use the FlatRate Wiki sign-in. Public
 * username/password signup stays closed. An administrator, or a
 * non-empty OAuth registration token, may still create an account.
 */
final class NativeAdminRecoveryGate
{
    public function allowsPasswordLogin(bool $userFound, bool $isAdmin): bool
    {
        return $userFound && $isAdmin;
    }

    public function allowsNativeSignup(bool $actorIsAdmin, mixed $registrationToken): bool
    {
        if ($actorIsAdmin) {
            return true;
        }

        return is_string($registrationToken) && trim($registrationToken) !== '';
    }
}
