<?php

namespace FlatRate\SupabaseOAuth\Beta;

use Flarum\User\User;

final class BetaTesterProjectionStore implements BetaTesterProjection
{
    public function __construct(private BetaTesterProjectionTable $rows)
    {
    }

    public function isActive(User $user): bool
    {
        $id = (int) $user->id;
        if ($id < 1) {
            return false;
        }

        return $this->rows->isActiveId($id);
    }

    public function sync(User $user, bool $active): bool
    {
        $id = (int) $user->id;
        if ($id < 1) {
            return false;
        }

        return $this->rows->syncId($id, $active);
    }
}
