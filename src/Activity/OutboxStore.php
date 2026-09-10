<?php

namespace FlatRate\SupabaseOAuth\Activity;

use Flarum\User\User;

final class OutboxStore
{
    public const CLAIM_LEASE_SECONDS = 120;
    public const TERMINAL_RETENTION_SECONDS = 86400;
    public const DELIVERED_RETENTION_SECONDS = 3600;

    /** @param object|null $connection Connection-like with table()/transaction() */
    public function __construct(private ?object $connection = null)
    {
    }

    private function connection(?User $actor = null): object
    {
        if ($this->connection) {
            return $this->connection;
        }
        if ($actor) {
            return $actor->getConnection();
        }

        return (new User())->getConnection();
    }

    public function enqueue(User $actor, string $eventKind, array $payload): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $db = $this->connection($actor);

        return (int) $db->table('flatrate_activity_outbox')->insertGetId([
            'event_kind' => $eventKind,
            'flarum_user_id' => (int) $actor->id,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'attempts' => 0,
            'available_at' => $now,
            'created_at' => $now,
            'delivered_at' => null,
            'terminal_at' => null,
            'last_error_code' => null,
        ]);
    }

    public function markDelivered(?User $actor, int $id): void
    {
        $this->connection($actor)->table('flatrate_activity_outbox')
            ->where('id', $id)
            ->update([
                'delivered_at' => gmdate('Y-m-d H:i:s'),
                'last_error_code' => null,
            ]);
    }

    public function markFailure(?User $actor, int $id, string $code, bool $retryable): void
    {
        $db = $this->connection($actor);
        $attempts = (int) ($db->table('flatrate_activity_outbox')->where('id', $id)->value('attempts') ?? 0) + 1;

        if (! $retryable) {
            $this->markTerminal($actor, $id, $code, $attempts);

            return;
        }

        $availableAt = gmdate('Y-m-d H:i:s', time() + min(300, 5 * $attempts));

        $db->table('flatrate_activity_outbox')->where('id', $id)->update([
            'attempts' => $attempts,
            'available_at' => $availableAt,
            'last_error_code' => substr($code, 0, 64),
        ]);
    }

    /**
     * Terminal/nonretryable: keep bounded diagnostics, redact trusted-transport identity.
     */
    public function markTerminal(?User $actor, int $id, string $code, ?int $attempts = null): void
    {
        $db = $this->connection($actor);
        $row = $db->table('flatrate_activity_outbox')->where('id', $id)->first();
        if (! $row) {
            return;
        }

        $eventKind = (string) $row->event_kind;
        $redacted = json_encode([
            'redacted' => true,
            'event_kind' => $eventKind,
            'schema_version' => 1,
        ], JSON_UNESCAPED_SLASHES);

        $db->table('flatrate_activity_outbox')->where('id', $id)->update([
            'attempts' => $attempts ?? ((int) $row->attempts + 1),
            'available_at' => gmdate('Y-m-d H:i:s', time() + self::TERMINAL_RETENTION_SECONDS),
            'terminal_at' => gmdate('Y-m-d H:i:s'),
            'last_error_code' => substr($code, 0, 64),
            'payload_json' => $redacted ?: '{"redacted":true}',
        ]);
    }

    /**
     * Claim due undelivered rows with a short lease to avoid duplicate workers.
     *
     * @return list<object>
     */
    public function claimDue(?User $actor, int $limit = 25): array
    {
        $db = $this->connection($actor);
        $now = gmdate('Y-m-d H:i:s');
        $leaseUntil = gmdate('Y-m-d H:i:s', time() + self::CLAIM_LEASE_SECONDS);

        return $db->transaction(function () use ($db, $now, $leaseUntil, $limit) {
            $rows = $db->table('flatrate_activity_outbox')
                ->whereNull('delivered_at')
                ->whereNull('terminal_at')
                ->where('available_at', '<=', $now)
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $claimed = [];
            foreach ($rows as $row) {
                $updated = $db->table('flatrate_activity_outbox')
                    ->where('id', $row->id)
                    ->whereNull('delivered_at')
                    ->whereNull('terminal_at')
                    ->where('available_at', '<=', $now)
                    ->update(['available_at' => $leaseUntil]);

                if ($updated === 1) {
                    $claimed[] = $row;
                }
            }

            return $claimed;
        });
    }

    public function cleanup(?User $actor = null): void
    {
        $db = $this->connection($actor);
        $deliveredCutoff = gmdate('Y-m-d H:i:s', time() - self::DELIVERED_RETENTION_SECONDS);
        $terminalCutoff = gmdate('Y-m-d H:i:s', time() - self::TERMINAL_RETENTION_SECONDS);

        $db->table('flatrate_activity_outbox')
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '<', $deliveredCutoff)
            ->delete();

        $db->table('flatrate_activity_outbox')
            ->whereNotNull('terminal_at')
            ->where('terminal_at', '<', $terminalCutoff)
            ->delete();
    }

    public function cleanupDelivered(User $actor, int $retainSeconds = self::DELIVERED_RETENTION_SECONDS): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $retainSeconds);
        $this->connection($actor)->table('flatrate_activity_outbox')
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '<', $cutoff)
            ->delete();
    }
}
