<?php

namespace FlatRate\SupabaseOAuth\Identity;

/**
 * Deterministic metadata-only member-profile backfill plan.
 *
 * Never mutates users.nickname. Hash-namespace collisions abort apply.
 */
final class MemberProfileBackfillPlanner
{
    /**
     * @param  iterable<array{id:int|string,nickname?:string|null}>  $users
     * @return array<string,mixed>
     */
    public function plan(iterable $users): array
    {
        $rows = [];
        $memberMode = 0;
        $customMode = 0;
        $grandfathered = 0;
        $collisions = 0;

        foreach ($users as $user) {
            $id = (int) ($user['id'] ?? 0);
            if ($id < 1) {
                continue;
            }

            $nickname = trim((string) ($user['nickname'] ?? ''));
            $canonical = MemberIdentity::nickname($id);
            $claimed = MemberIdentity::parseMemberNumber($nickname);
            if ($claimed !== null && $claimed !== $id) {
                $collisions++;
            }

            if (strcasecmp($nickname, $canonical) === 0) {
                $mode = MemberIdentity::DISPLAY_MODE_MEMBER_NUMBER;
                $custom = null;
                $origin = null;
                $memberMode++;
            } else {
                $mode = MemberIdentity::DISPLAY_MODE_CUSTOM;
                $custom = $nickname;
                $origin = MemberIdentity::ORIGIN_GRANDFATHERED;
                $customMode++;
                $grandfathered++;
            }

            $rows[] = [
                'user_id' => $id,
                'member_number' => $id,
                'reserved_member_nickname' => $canonical,
                'display_mode' => $mode,
                'custom_nickname' => $custom,
                'custom_nickname_origin' => $origin,
                'visible_nickname' => $nickname,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['user_id'] <=> $b['user_id']);

        $digestSource = json_encode($rows, JSON_UNESCAPED_SLASHES);
        if (! is_string($digestSource)) {
            $digestSource = '';
        }

        return [
            'BACKFILL_USER_COUNT' => count($rows),
            'MEMBER_MODE_COUNT' => $memberMode,
            'CUSTOM_MODE_COUNT' => $customMode,
            'GRANDFATHERED_COUNT' => $grandfathered,
            'HASH_MEMBER_NAMESPACE_COLLISION_COUNT' => $collisions,
            'VISIBLE_NICKNAME_MUTATION_COUNT' => 0,
            'BACKFILL_PLAN_SHA256' => hash('sha256', $digestSource),
            'rows' => $rows,
        ];
    }
}
