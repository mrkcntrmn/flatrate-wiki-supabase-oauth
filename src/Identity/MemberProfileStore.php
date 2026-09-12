<?php

namespace FlatRate\SupabaseOAuth\Identity;

use Flarum\User\User;
use RuntimeException;

final class MemberProfileStore
{
    public function __construct(private MemberDisplayService $display)
    {
    }

    public function find(int $userId): ?MemberProfile
    {
        return MemberProfile::query()->whereKey($userId)->first();
    }

    public function createForNewUser(User $user): MemberProfile
    {
        $memberNumber = MemberIdentity::memberNumber($user);
        $now = date('Y-m-d H:i:s');

        $profile = new MemberProfile();
        $profile->user_id = $memberNumber;
        $profile->member_number = $memberNumber;
        $profile->display_mode = MemberIdentity::DISPLAY_MODE_MEMBER_NUMBER;
        $profile->custom_nickname = null;
        $profile->custom_nickname_origin = null;
        $profile->assigned_at = $now;
        $profile->updated_at = $now;
        $profile->save();

        return $profile;
    }

    /**
     * Linked-user self-heal. Completes an unfinished temp routing nickname;
     * otherwise preserves the visible nickname exactly.
     */
    public function selfHeal(User $user): MemberProfile
    {
        $existing = $this->find((int) $user->id);
        if ($existing) {
            return $existing;
        }

        $plan = $this->display->planSelfHeal($user);
        $memberNumber = MemberIdentity::memberNumber($user);
        $now = date('Y-m-d H:i:s');

        if ($plan['complete_temporary']) {
            $user->nickname = MemberIdentity::nickname($memberNumber);
            $user->save();
        }

        $profile = new MemberProfile();
        $profile->user_id = $memberNumber;
        $profile->member_number = $memberNumber;
        $profile->display_mode = $plan['display_mode'];
        $profile->custom_nickname = $plan['custom_nickname'];
        $profile->custom_nickname_origin = $plan['custom_nickname_origin'];
        $profile->assigned_at = $now;
        $profile->updated_at = $now;
        $profile->save();

        return $profile;
    }

    public function requireFor(User $user): MemberProfile
    {
        $profile = $this->selfHeal($user);
        if (! $profile) {
            throw new RuntimeException('member_profile_missing');
        }

        return $profile;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    public function applyPlan(array $plan, string $expectedDigest): int
    {
        if (($plan['BACKFILL_PLAN_SHA256'] ?? '') !== $expectedDigest) {
            throw new MemberDisplayException('BACKFILL_PLAN_DRIFT', 409);
        }

        if ((int) ($plan['HASH_MEMBER_NAMESPACE_COLLISION_COUNT'] ?? 0) > 0) {
            throw new MemberDisplayException('HASH_MEMBER_NAMESPACE_PREEXISTING_COLLISION', 409);
        }

        if ((int) ($plan['VISIBLE_NICKNAME_MUTATION_COUNT'] ?? 0) !== 0) {
            throw new MemberDisplayException('VISIBLE_NICKNAME_MUTATION_DURING_BACKFILL', 409);
        }

        $now = date('Y-m-d H:i:s');
        $written = 0;

        foreach ($plan['rows'] ?? [] as $row) {
            $userId = (int) $row['user_id'];
            $profile = $this->find($userId) ?? new MemberProfile();
            $profile->user_id = $userId;
            $profile->member_number = (int) $row['member_number'];
            $profile->display_mode = (string) $row['display_mode'];
            $profile->custom_nickname = $row['custom_nickname'];
            $profile->custom_nickname_origin = $row['custom_nickname_origin'];
            if (! $profile->exists) {
                $profile->assigned_at = $now;
            }
            $profile->updated_at = $now;
            $profile->save();
            $written++;
        }

        return $written;
    }
}
