<?php

namespace FlatRate\SupabaseOAuth\Identity;

use Flarum\Database\AbstractModel;
use Flarum\Http\SlugDriverInterface;
use Flarum\User\User;
use Flarum\User\UserRepository;

/** Guest-safe profile links; authenticated emission and legacy URLs delegate. */
final class GuestAwareUserSlugDriver implements SlugDriverInterface
{
    public function __construct(
        private SlugDriverInterface $inner,
        private GuestIdentityProjection $projection,
        private UserRepository $users
    ) {
    }

    public function toSlug(AbstractModel $instance): string
    {
        return $this->projection->isActive() && $instance instanceof User
            ? $this->projection->publicSlug($instance)
            : $this->inner->toSlug($instance);
    }

    public function fromSlug(string $slug, User $actor): AbstractModel
    {
        if (preg_match('/^([1-9][0-9]*)-tech_[0-9a-f]{8}$/D', $slug, $matches) === 1) {
            // Preserve native actor visibility/authorization on the lookup.
            $user = $this->users->findOrFail($matches[1], $actor);
            if ($this->projection->publicSlug($user) === $slug) {
                return $user;
            }
        }

        return $this->inner->fromSlug($slug, $actor);
    }
}
