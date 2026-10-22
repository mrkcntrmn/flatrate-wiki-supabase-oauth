<?php

namespace FlatRate\SupabaseOAuth\Canary;

use FlatRate\SupabaseOAuth\Sso\SharedSecretAuthenticator;
use FlatRate\SupabaseOAuth\Sso\SsoException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ProvisionController implements RequestHandlerInterface
{
    public function __construct(
        private SharedSecretAuthenticator $authenticator,
        private IdentityProvisioner $provisioner
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->authenticator->authenticate($request);
            $body = RequestPayload::read($request);
            $user = $this->provisioner->ensure(
                (string) ($body['sub'] ?? ''),
                (string) ($body['email'] ?? ''),
                filter_var($body['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
            );

            return $this->json([
                'ok' => true,
                'user_id' => $user->id,
            ]);
        } catch (SsoException $error) {
            return $this->json(['error' => $error->errorCode], $error->statusCode);
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
