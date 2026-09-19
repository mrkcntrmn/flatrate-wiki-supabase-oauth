<?php

namespace FlatRate\SupabaseOAuth\Api;

use Flarum\Api\Serializer\AbstractSerializer;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Expose FlatRate vote gate state to the forum SPA (boolean only).
 */
final class SerializeFlatRateVotingEnabled
{
    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function __invoke(AbstractSerializer $serializer, $model, array $attributes): array
    {
        $attributes['flatRateVotingEnabled'] = (bool) $this->settings->get('flatrate-voting.enabled');

        return $attributes;
    }
}
