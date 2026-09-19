<?php

namespace FlatRate\SupabaseOAuth\Activity;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Authenticated, argument-free HTTP surface for ActivityOutboxDrainer::drain().
 * Caller cannot select rows, set limits, or supply payloads.
 */
final class DrainActivityOutboxController implements RequestHandlerInterface
{
    public function __construct(
        private ActivityDrainAuthenticator $authenticator,
        private ActivityOutboxDrainer $drainer,
        private LoggerInterface $logger
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (! $this->authenticator->configured()) {
            return $this->json([
                'ok' => false,
                'error' => 'scheduler_not_configured',
            ], 503);
        }

        if (! $this->authenticator->authenticate($request)) {
            return $this->json([
                'ok' => false,
                'error' => 'unauthorized',
            ], 401);
        }

        if (trim((string) $request->getBody()) !== '') {
            return $this->json([
                'ok' => false,
                'error' => 'invalid_request',
            ], 400);
        }

        try {
            $processed = $this->drainer->drain();

            return $this->json([
                'ok' => true,
                'processed' => $processed,
            ]);
        } catch (\Throwable $error) {
            $this->logger->warning(
                'flatrate_activity_external_drain_failed',
                ['error_class' => $error::class]
            );

            return $this->json([
                'ok' => false,
                'error' => 'drain_failed',
            ], 503);
        }
    }

    private function json(array $payload, int $status = 200): JsonResponse
    {
        return new JsonResponse($payload, $status, [
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
