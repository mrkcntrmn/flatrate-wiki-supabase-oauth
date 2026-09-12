<?php

namespace FlatRate\SupabaseOAuth\Listeners;

use FlatRate\SupabaseOAuth\Identity\MemberDisplayException;
use FlatRate\SupabaseOAuth\Identity\MemberDisplayService;
use FlatRate\SupabaseOAuth\Identity\MemberProfileStore;
use Flarum\User\Event\Saving;
use Illuminate\Support\Arr;

/**
 * Keep member-profile metadata aligned when the generic nickname editor
 * saves a non-reserved custom nickname.
 */
final class SyncMemberDisplayFromNickname
{
    public function __construct(
        private MemberProfileStore $profiles,
        private MemberDisplayService $display
    ) {
    }

    public function handle(Saving $event): void
    {
        $attributes = Arr::get($event->data, 'attributes', []);
        if (! is_array($attributes) || ! array_key_exists('nickname', $attributes)) {
            return;
        }

        $nickname = is_string($attributes['nickname']) ? $attributes['nickname'] : '';
        $user = $event->user;
        if (! $user->exists || ! $user->id) {
            return;
        }

        try {
            $profile = $this->profiles->requireFor($user);
            $this->display->syncGenericCustom($user, $profile, $nickname);
            $profile->updated_at = date('Y-m-d H:i:s');
            $profile->save();
        } catch (MemberDisplayException $error) {
            // Reservation is owned by RejectReservedTechNickname.
            if ($error->errorCode === 'reserved_tech_nickname') {
                return;
            }

            throw $error;
        }
    }
}
