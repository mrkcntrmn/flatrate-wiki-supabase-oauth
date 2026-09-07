<?php

namespace FlatRate\SupabaseOAuth\Subscription;

use Flarum\User\User;

/**
 * Loads candidate users by id for family recipient expansion.
 *
 * Isolated so runtime tests can substitute a stub without mutating tag_user.
 */
final class FamilyUserLookup
{
    /**
     * @param list<int> $ids
     * @return iterable<User>
     */
    public function findByIds(array $ids): iterable
    {
        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', array_values(array_unique($ids)))->get();
    }
}
