<?php

namespace FlatRate\SupabaseOAuth\Identity;

use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;

/**
 * FlatRate-only member-display policy. Core nickname syntax/uniqueness stays
 * on Flarum EditUser + Nicknames AddNicknameValidation.
 *
 * MEMBER_DISPLAY_REQUIRES_EDIT_NICKNAME_PERMISSION=true
 */
final class MemberDisplayPolicy
{
    public const REQUIRES_EDIT_NICKNAME_PERMISSION = true;

    public function assertActorMayChangeDisplay(object $actor, object $user): void
    {
        if ((int) ($actor->id ?? 0) < 1 || (int) ($actor->id ?? 0) !== (int) ($user->id ?? 0)) {
            throw new MemberDisplayException('permission_denied', 403);
        }

        if (! self::REQUIRES_EDIT_NICKNAME_PERMISSION) {
            return;
        }

        if (method_exists($actor, 'assertCan')) {
            try {
                $actor->assertCan('editNickname', $user);
            } catch (PermissionDeniedException $error) {
                throw new MemberDisplayException('permission_denied', 403);
            }

            return;
        }

        if (property_exists($actor, 'canEditNickname') && $actor->canEditNickname === false) {
            throw new MemberDisplayException('permission_denied', 403);
        }
    }

    /**
     * @return array{kind:string,nickname:string}
     */
    public function resolveCustomAction(object $profile, ?string $nickname): array
    {
        $retained = trim((string) ($profile->custom_nickname ?? ''));
        $origin = (string) ($profile->custom_nickname_origin ?? '');
        $requested = $nickname === null ? null : trim($nickname);

        if ($requested === '') {
            throw new MemberDisplayException('custom_nickname_required');
        }

        if ($requested === null) {
            if ($retained === '') {
                throw new MemberDisplayException('custom_nickname_required');
            }

            return $this->restoreOrEdit($retained, $origin);
        }

        if (ReservedTechNickname::matches($requested)) {
            if (
                $origin === MemberIdentity::ORIGIN_GRANDFATHERED
                && $retained !== ''
                && strcasecmp($requested, $retained) === 0
            ) {
                return [
                    'kind' => 'trusted_restore',
                    'nickname' => $retained,
                ];
            }

            throw new MemberDisplayException('reserved_tech_nickname');
        }

        return [
            'kind' => 'edit_user',
            'nickname' => $requested,
        ];
    }

    /**
     * @return array{kind:string,nickname:string}
     */
    private function restoreOrEdit(string $retained, string $origin): array
    {
        if (ReservedTechNickname::matches($retained)) {
            if ($origin !== MemberIdentity::ORIGIN_GRANDFATHERED) {
                throw new MemberDisplayException('reserved_tech_nickname');
            }

            return [
                'kind' => 'trusted_restore',
                'nickname' => $retained,
            ];
        }

        return [
            'kind' => 'edit_user',
            'nickname' => $retained,
        ];
    }

    public function assertCanonicalAssignable(User $user, callable $occupiedByOther): string
    {
        $memberNumber = MemberIdentity::memberNumber($user);
        if ($memberNumber !== (int) $user->id) {
            throw new MemberDisplayException('member_number_unavailable', 500);
        }

        $canonical = MemberIdentity::nickname($memberNumber);
        if ($occupiedByOther($canonical, $memberNumber)) {
            throw new MemberDisplayException('member_nickname_collision', 409);
        }

        return $canonical;
    }
}
