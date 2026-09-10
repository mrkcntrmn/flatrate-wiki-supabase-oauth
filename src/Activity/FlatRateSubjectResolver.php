<?php

namespace FlatRate\SupabaseOAuth\Activity;

use Flarum\User\LoginProvider;
use Flarum\User\User;

final class FlatRateSubjectResolver
{
    /**
     * Immutable Supabase sub from login_providers(provider=flatrate).
     * Never infer from email/nickname/username.
     */
    public function resolveSub(User $user): ?string
    {
        $row = LoginProvider::query()
            ->where('provider', 'flatrate')
            ->where('user_id', (int) $user->id)
            ->first();

        if (! $row) {
            return null;
        }

        $sub = trim((string) $row->identifier);
        if ($sub === '' || str_starts_with($sub, 'asub_')) {
            return null;
        }

        return $sub;
    }
}
