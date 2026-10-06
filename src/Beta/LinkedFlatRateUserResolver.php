<?php

namespace FlatRate\SupabaseOAuth\Beta;

use Flarum\User\LoginProvider;
use Flarum\User\User;

/**
 * Resolves an already-linked FlatRate subject. Does not provision users.
 */
final class LinkedFlatRateUserResolver
{
    public function findBySubject(string $sub): ?User
    {
        $sub = trim($sub);
        if ($sub === '') {
            return null;
        }

        $provider = LoginProvider::where('provider', 'flatrate')
            ->where('identifier', $sub)
            ->first();

        if (! $provider || ! $provider->user_id) {
            return null;
        }

        $user = User::find($provider->user_id);

        return $user instanceof User ? $user : null;
    }
}
