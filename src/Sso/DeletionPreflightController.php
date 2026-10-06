<?php

namespace FlatRate\SupabaseOAuth\Sso;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DeletionPreflightController implements RequestHandlerInterface
{
    public function __construct(
        private SharedSecretAuthenticator $authenticator,
        private DeletionPreflight $preflight
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            // SharedSecretAuthenticator performs the existing bounded nonce
            // anti-replay security write. This endpoint performs no user,
            // content, session, ticket, or identity mutation.
            $this->authenticator->authenticate($request);
            $body = $this->payload($request);
            $sub = trim((string) ($body['sub'] ?? ''));

            return $this->json($this->preflight->inspect($sub));
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
