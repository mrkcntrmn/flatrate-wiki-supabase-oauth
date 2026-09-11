<?php

namespace FlatRate\SupabaseOAuth\Listeners;

use FlatRate\SupabaseOAuth\Identity\ReservedTechNickname;
use Flarum\Foundation\ValidationException;
use Flarum\User\Event\Saving;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Arr;

/**
 * Reject human nickname claims in the reserved numeric tech_* namespace.
 *
 * Only runs when the request explicitly includes attributes.nickname so
 * grandfathered stored tech_N nicknames survive unrelated profile saves, and
 * FlatRate RegistrationToken system nicknames (applied onto the model, not via
 * request attributes) remain allowed during SSO registration.
 */
final class RejectReservedTechNickname
{
    public function __construct(private Translator $translator)
    {
    }

    public function handle(Saving $event): void
    {
        $attributes = Arr::get($event->data, 'attributes', []);
        if (! is_array($attributes) || ! array_key_exists('nickname', $attributes)) {
            return;
        }

        if (! ReservedTechNickname::matches($attributes['nickname'])) {
            return;
        }

        throw new ValidationException([
            'nickname' => $this->translator->trans('flatrate-identity.api.reserved_tech_nickname'),
        ]);
    }
}
