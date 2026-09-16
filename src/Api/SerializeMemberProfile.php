<?php

namespace FlatRate\SupabaseOAuth\Api;

use FlatRate\SupabaseOAuth\Identity\MemberIdentity;
use FlatRate\SupabaseOAuth\Identity\MemberProfile;
use FlatRate\SupabaseOAuth\Identity\OwnerDashboardDto;
use Flarum\Api\Serializer\UserSerializer;
use Flarum\User\User;

final class SerializeMemberProfile
{
    public function __invoke(UserSerializer $serializer, User $user, array $attributes): array
    {
        if (! $user->exists || ! $user->id) {
            return [];
        }

        $actor = $serializer->getActor();

        // Open-web / guest viewers must not receive Member # or tech_#N attrs.
        // Authenticated strangers and owners keep current Community behavior.
        if ($actor && $actor->isGuest()) {
            return [];
        }

        $memberNumber = (int) $user->id;
        $exposed = [
            'flatRateMemberNumber' => $memberNumber,
            'flatRateMemberNickname' => MemberIdentity::nickname($memberNumber),
        ];

        if (! $actor || (int) $actor->id !== $memberNumber) {
            return $exposed;
        }

        $exposed['flatRateOwnerDashboard'] = OwnerDashboardDto::make();

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
