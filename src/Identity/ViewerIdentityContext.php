<?php

namespace FlatRate\SupabaseOAuth\Identity;

use Flarum\User\User;

/**
 * Request-scoped stack of authenticated viewers for guest identity projection.
 *
 * Empty stack must never be treated as guest (CLI, cron, mail, tests without HTTP).
 */
final class ViewerIdentityContext
{
    /** @var list<User> */
    private array $stack = [];

    public function push(User $actor): void
    {
        $this->stack[] = $actor;
    }

    public function pop(): void
    {
        if ($this->stack === []) {
            return;
        }

        array_pop($this->stack);
    }

    public function clear(): void
    {
        $this->stack = [];
    }

    public function depth(): int
    {
        return count($this->stack);
    }

    public function currentActor(): ?User
    {
        if ($this->stack === []) {
            return null;
        }

        return $this->stack[array_key_last($this->stack)];
    }

    /**
     * Guest projection is active only when a viewer is pushed and that viewer is a guest.
     */
    public function isGuestProjectionActive(): bool
    {
        $actor = $this->currentActor();

        return $actor !== null && $actor->isGuest();
    }
}
