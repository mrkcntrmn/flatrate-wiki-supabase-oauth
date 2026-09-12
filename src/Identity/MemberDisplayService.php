<?php

namespace FlatRate\SupabaseOAuth\Identity;

/**
 * Display-mode transitions. Client never chooses the member number.
 *
 * Trusted restore may put a stored grandfathered reserved nickname back on
 * the same account. Generic editors must never claim reserved forms.
 */
final class MemberDisplayService
{
    /**
     * @param  object{id:mixed,nickname:mixed}  $user
     * @param  object{display_mode:mixed,custom_nickname:mixed,custom_nickname_origin:mixed}  $profile
     */
    public function applyMemberNumber(object $user, object $profile): void
    {
        $memberNumber = (int) $user->id;
        if ($memberNumber < 1) {
            throw new MemberDisplayException('member_number_unavailable', 500);
        }

        $user->nickname = MemberIdentity::nickname($memberNumber);
        $profile->display_mode = MemberIdentity::DISPLAY_MODE_MEMBER_NUMBER;
    }

    /**
     * @param  object{id:mixed,nickname:mixed}  $user
     * @param  object{display_mode:mixed,custom_nickname:mixed,custom_nickname_origin:mixed}  $profile
     */
    public function restoreCustom(object $user, object $profile): void
    {
        $custom = trim((string) ($profile->custom_nickname ?? ''));
        if ($custom === '') {
            throw new MemberDisplayException('custom_nickname_required');
        }

        $user->nickname = $custom;
        $profile->display_mode = MemberIdentity::DISPLAY_MODE_CUSTOM;
    }

    /**
     * Dedicated API custom assignment. Reserved values are allowed only when
     * they equal this profile's retained custom nickname.
     *
     * @param  object{id:mixed,nickname:mixed}  $user
     * @param  object{display_mode:mixed,custom_nickname:mixed,custom_nickname_origin:mixed}  $profile
     */
    public function applyTrustedCustom(object $user, object $profile, ?string $nickname): void
    {
        if ($nickname === null) {
            $this->restoreCustom($user, $profile);

            return;
        }

        $nickname = trim($nickname);
        if ($nickname === '') {
            throw new MemberDisplayException('custom_nickname_required');
        }

        $retained = trim((string) ($profile->custom_nickname ?? ''));
        if (ReservedTechNickname::matches($nickname)) {
            if ($retained !== '' && strcasecmp($nickname, $retained) === 0) {
                $user->nickname = $retained;
                $profile->display_mode = MemberIdentity::DISPLAY_MODE_CUSTOM;

                return;
            }

            throw new MemberDisplayException('reserved_tech_nickname');
        }

        $user->nickname = $nickname;
        $profile->display_mode = MemberIdentity::DISPLAY_MODE_CUSTOM;
        $profile->custom_nickname = $nickname;
        $profile->custom_nickname_origin = MemberIdentity::ORIGIN_USER;
    }

    /**
     * Generic nickname editor: reserved forms already rejected.
     *
     * @param  object{id:mixed,nickname:mixed}  $user
     * @param  object{display_mode:mixed,custom_nickname:mixed,custom_nickname_origin:mixed}  $profile
     */
    public function syncGenericCustom(object $user, object $profile, string $nickname): void
    {
        $nickname = trim($nickname);
        if ($nickname === '' || ReservedTechNickname::matches($nickname)) {
            throw new MemberDisplayException('reserved_tech_nickname');
        }

        $user->nickname = $nickname;
        $profile->display_mode = MemberIdentity::DISPLAY_MODE_CUSTOM;
        $profile->custom_nickname = $nickname;
        $profile->custom_nickname_origin = MemberIdentity::ORIGIN_USER;
    }

    /**
     * Existing linked-user self-heal. Does not change visible nickname unless
     * the current value is still the unfinished temporary routing handle.
     *
     * @param  object{id:mixed,username:mixed,nickname:mixed}  $user
     * @return array{display_mode:string,custom_nickname:?string,custom_nickname_origin:?string,complete_temporary:bool}
     */
    public function planSelfHeal(object $user): array
    {
        $memberNumber = (int) $user->id;
        $canonical = MemberIdentity::nickname($memberNumber);
        $nickname = trim((string) ($user->nickname ?? ''));
        $username = trim((string) ($user->username ?? ''));

        if (strcasecmp($nickname, $canonical) === 0) {
            return [
                'display_mode' => MemberIdentity::DISPLAY_MODE_MEMBER_NUMBER,
                'custom_nickname' => null,
                'custom_nickname_origin' => null,
                'complete_temporary' => false,
            ];
        }

        $temporaryRouting = $username !== ''
            && strcasecmp($nickname, $username) === 0
            && ! ReservedTechNickname::matches($nickname);

        if ($temporaryRouting) {
            return [
                'display_mode' => MemberIdentity::DISPLAY_MODE_MEMBER_NUMBER,
                'custom_nickname' => null,
                'custom_nickname_origin' => null,
                'complete_temporary' => true,
            ];
        }

        return [
            'display_mode' => MemberIdentity::DISPLAY_MODE_CUSTOM,
            'custom_nickname' => $nickname,
            'custom_nickname_origin' => MemberIdentity::ORIGIN_GRANDFATHERED,
            'complete_temporary' => false,
        ];
    }
}
