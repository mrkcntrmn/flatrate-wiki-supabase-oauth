<?php

namespace FlatRate\SupabaseOAuth\Activity;

use Psr\Log\LoggerInterface;

/**
 * Bounded automatic outbox consumer for at-least-once delivery.
 * Each attempt uses ActivityClient (fresh HMAC timestamp/nonce/signature).
 */
final class ActivityOutboxDrainer
{
    public const DEFAULT_BATCH_SIZE = 25;

    public function __construct(
        private ActivityClient $client,
        private OutboxStore $outbox,
        private LoggerInterface $logger
    ) {
    }

    public function drain(int $limit = self::DEFAULT_BATCH_SIZE): int
    {
        if (! $this->client->enabled()) {
            return 0;
        }

        $claimed = $this->outbox->claimDue(null, $limit);
        $processed = 0;

        foreach ($claimed as $row) {
            $processed++;
            $payload = json_decode((string) $row->payload_json, true);
            if (! is_array($payload) || ! empty($payload['redacted'])) {
                $this->outbox->markTerminal(null, (int) $row->id, 'payload_invalid');
                continue;
            }

            try {
                $result = $this->client->postObservation($payload);
            } catch (\Throwable $e) {
                $this->outbox->markFailure(null, (int) $row->id, 'drain_exception', true);
                $this->logger->warning('flatrate_activity_outbox_drain_exception', [
                    'outbox_id' => (int) $row->id,
                    'error_class' => $e::class,
                ]);
                continue;
            }

            if ($result['ok']) {
                $this->outbox->markDelivered(null, (int) $row->id);
                continue;
            }

            $this->outbox->markFailure(
                null,
                (int) $row->id,
                (string) ($result['error'] ?? 'delivery_failed'),
                (bool) ($result['retryable'] ?? true)
            );
            $this->logger->warning('flatrate_activity_outbox_retry', [
                'outbox_id' => (int) $row->id,
                'error' => $result['error'] ?? 'unknown',
                'status' => $result['status'] ?? 0,
                'retryable' => (bool) ($result['retryable'] ?? true),
            ]);
        }

        $this->outbox->cleanup();

        return $processed;
    }
}
