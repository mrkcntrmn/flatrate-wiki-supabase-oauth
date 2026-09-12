<?php

namespace FlatRate\SupabaseOAuth\Api;

use FlatRate\SupabaseOAuth\Identity\MemberIdentity;
use FlatRate\SupabaseOAuth\Identity\MemberProfile;
use Flarum\Api\Serializer\UserSerializer;
use Flarum\User\User;

final class SerializeMemberProfile
{
    public function __invoke(UserSerializer $serializer, User $user, array $attributes): array
    {
        if (! $user->exists || ! $user->id) {
            return [];
        }

        $memberNumber = (int) $user->id;
        $exposed = [
            'flatRateMemberNumber' => $memberNumber,
            'flatRateMemberNickname' => MemberIdentity::nickname($memberNumber),
        ];

        $actor = $serializer->getActor();
        if (! $actor || (int) $actor->id !== $memberNumber) {
            return $exposed;
        }

        $profile = MemberProfile::query()->whereKey($memberNumber)->first();
        if (! $profile) {
            return $exposed;
        }

        $exposed['flatRateNicknameMode'] = $profile->display_mode;
        $exposed['flatRateCustomNickname'] = $profile->custom_nickname;
        $exposed['flatRateCustomNicknameOrigin'] = $profile->custom_nickname_origin;

        return $exposed;
    }
}
