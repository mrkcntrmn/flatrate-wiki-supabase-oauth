<?php

namespace FlatRate\SupabaseOAuth\Sso;

use FlatRate\SupabaseOAuth\Beta\BetaTesterProjectionStore;
use FlatRate\SupabaseOAuth\Beta\LinkedFlatRateUserResolver;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BetaAccessSyncController implements RequestHandlerInterface
{
    public function __construct(
        private SharedSecretAuthenticator $authenticator,
        private LinkedFlatRateUserResolver $linkedUsers,
        private BetaTesterProjectionStore $projection
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->authenticator->authenticate($request);
            $body = $this->payload($request);
            $sub = trim((string) ($body['sub'] ?? ''));
            if ($sub === '') {
                throw new SsoException('invalid_subject', 400);
            }

            $active = BetaTesterPayload::required($body);
            $user = $this->linkedUsers->findBySubject($sub);
            if (! $user) {
                return $this->json([
                    'ok' => true,
                    'linked' => false,
                    'changed' => false,
                ]);
            }

            $changed = $this->projection->sync($user, $active);

            return $this->json([
                'ok' => true,
                'linked' => true,
                'changed' => $changed,
            ]);
        } catch (SsoException $error) {
            return $this->json(['error' => $error->errorCode], $error->statusCode);
        }
    }

    private function payload(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body)) {
            return $body;
        }

        $decoded = json_decode((string) $request->getBody(), true);
        if (! is_array($decoded)) {
            throw new SsoException('invalid_json', 400);
        }

        return $decoded;
    }

    private function json(array $payload, int $status = 200): JsonResponse
    {
        return new JsonResponse($payload, $status, [
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
