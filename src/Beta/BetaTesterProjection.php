<?php

namespace FlatRate\SupabaseOAuth\Beta;

use Flarum\User\User;

/**
 * Server-side beta projection seam for other FlatRate extensions.
 * Live Chat should resolve this from the container rather than reading the table.
 */
interface BetaTesterProjection
{
    public function isActive(User $actor): bool;
}
