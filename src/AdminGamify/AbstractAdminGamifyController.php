<?php

namespace FlatRate\SupabaseOAuth\AdminGamify;

use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

abstract class AbstractAdminGamifyController implements RequestHandlerInterface
{
    public function __construct(
        protected AdminGamifyBridgeClient $bridge,
        protected QualitySignalsService $quality
    ) {
    }

    abstract protected function handleAdmin(ServerRequestInterface $request, User $actor): array;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);
            $actor->assertAdmin();

            $payload = $this->handleAdmin($request, $actor);

            return AdminGamifyHttp::json($payload);
        } catch (AdminGamifyRequestException $e) {
            return AdminGamifyHttp::json([
                'ok' => false,
                'error' => $e->errorCode,
            ], $e->statusCode);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function bridgeBody(User $actor, ServerRequestInterface $request): array
    {
        $parsed = AdminGamifyHttp::parseQuery($request);

        return [
            'actor_flarum_user_id' => (int) $actor->id,
            'window' => $parsed['window'],
            'limit' => $parsed['limit'],
            'cursor' => $parsed['cursor'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function bridgeOrUnavailable(string $routeKey, array $body): array
    {
        $result = $this->bridge->post($routeKey, $body);
        if (! $result['ok'] || ! is_array($result['body'])) {
            $sources = match ($routeKey) {
                'overview' => [
                    'sharing' => ['status' => 'unavailable'],
                    'referrals' => ['status' => 'unavailable'],
                ],
                default => [
                    $routeKey => ['status' => 'unavailable'],
                ],
            };

            return [
                'ok' => true,
                'generated_at' => gmdate('c'),
                'window' => $body['window'] ?? AdminGamifyHttp::DEFAULT_WINDOW,
                'sources' => $sources,
                'coverage' => null,
                'data' => null,
                'error' => $result['error'] ?? 'bridge_unavailable',
            ];
        }

        return AdminGamifyHttp::sanitizeBridgePayload($result['body']);
    }

    /**
     * Mutation-style bridge call: surface upstream errors instead of soft-unavailable.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    protected function bridgeAction(string $routeKey, array $body): array
    {
        $result = $this->bridge->post($routeKey, $body);
        if (! $result['ok'] || ! is_array($result['body'])) {
            $status = (int) ($result['status'] ?? 0);
            if ($status < 400 || $status > 599) {
                $status = 502;
            }

            throw new AdminGamifyRequestException(
                (string) ($result['error'] ?? 'bridge_unavailable'),
                $status
            );
        }

        return AdminGamifyHttp::sanitizeBridgePayload($result['body']);
    }
}
