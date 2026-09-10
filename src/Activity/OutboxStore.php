<?php

namespace FlatRate\SupabaseOAuth\Activity;

use Flarum\User\User;

final class OutboxStore
{
    public function enqueue(User $actor, string $eventKind, array $payload): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $db = $actor->getConnection();

        return (int) $db->table('flatrate_activity_outbox')->insertGetId([
            'event_kind' => $eventKind,
            'flarum_user_id' => (int) $actor->id,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'attempts' => 0,
            'available_at' => $now,
            'created_at' => $now,
            'delivered_at' => null,
            'last_error_code' => null,
        ]);
    }

    public function markDelivered(User $actor, int $id): void
    {
        $actor->getConnection()->table('flatrate_activity_outbox')
            ->where('id', $id)
            ->update([
                'delivered_at' => gmdate('Y-m-d H:i:s'),
                'last_error_code' => null,
            ]);
    }

    public function markFailure(User $actor, int $id, string $code, bool $retryable): void
    {
        $db = $actor->getConnection();
        $attempts = (int) ($db->table('flatrate_activity_outbox')->where('id', $id)->value('attempts') ?? 0) + 1;
        $availableAt = $retryable
            ? gmdate('Y-m-d H:i:s', time() + min(300, 5 * $attempts))
            : gmdate('Y-m-d H:i:s', time() + 86400);

        $db->table('flatrate_activity_outbox')->where('id', $id)->update([
            'attempts' => $attempts,
            'available_at' => $availableAt,
            'last_error_code' => substr($code, 0, 64),
        ]);
    }

    public function cleanupDelivered(User $actor, int $retainSeconds = 3600): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $retainSeconds);
        $actor->getConnection()->table('flatrate_activity_outbox')
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '<', $cutoff)
            ->delete();
    }
}
