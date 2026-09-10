<?php

namespace FlatRate\SupabaseOAuth\Activity;

use Flarum\User\User;
use Psr\Log\LoggerInterface;

/**
 * Feature-gated at-least-once emitter.
 * Canonical forum actions must succeed even when delivery fails.
 */
final class ActivityEmitter
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private ActivityClient $client,
        private FlatRateSubjectResolver $subjects,
        private OutboxStore $outbox,
        private LoggerInterface $logger
    ) {
    }

    public function emit(User $actor, string $kind, array $observation): void
    {
        try {
            if (! $this->client->enabled()) {
                return;
            }

            $sub = $this->subjects->resolveSub($actor);
            if ($sub === null) {
                $this->logger->info('flatrate_activity_skipped_no_identity', [
                    'kind' => $kind,
                    'flarum_user_id' => (int) $actor->id,
                ]);

                return;
            }

            $payload = [
                'schema_version' => self::SCHEMA_VERSION,
                'identity' => [
                    'sub' => $sub,
                    'flarum_user_id' => (int) $actor->id,
                ],
                'observation' => array_merge(['kind' => $kind], $observation),
            ];

            // Never include content/PII beyond sub (trusted transport only).
            $encoded = json_encode($payload);
            foreach (['post_body', 'discussion_title', 'email', 'phone', 'access_token'] as $forbidden) {
                if (is_string($encoded) && str_contains($encoded, '"'.$forbidden.'"')) {
                    $this->logger->warning('flatrate_activity_forbidden_payload_key', ['kind' => $kind]);

                    return;
                }
            }

            $outboxId = $this->outbox->enqueue($actor, $kind, $payload);
            $result = $this->client->postObservation($payload);
            if ($result['ok']) {
                $this->outbox->markDelivered($actor, $outboxId);
                $this->outbox->cleanupDelivered($actor);

                return;
            }

            $this->outbox->markFailure(
                $actor,
                $outboxId,
                (string) ($result['error'] ?? 'delivery_failed'),
                (bool) ($result['retryable'] ?? true)
            );
            $this->logger->warning('flatrate_activity_delivery_failed', [
                'kind' => $kind,
                'error' => $result['error'] ?? 'unknown',
                'status' => $result['status'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            // Analytics must never roll back canonical forum actions.
            $this->logger->warning('flatrate_activity_emit_exception', [
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
