<?php

namespace FlatRate\SupabaseOAuth\Canary;

use FlatRate\SupabaseOAuth\Sso\SharedSecretAuthenticator;
use FlatRate\SupabaseOAuth\Sso\SsoException;
use FlatRate\SupabaseOAuth\Sso\TicketStore;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class TicketController implements RequestHandlerInterface
{
    public function __construct(
        private SharedSecretAuthenticator $authenticator,
        private IdentityProvisioner $provisioner,
        private TicketStore $tickets
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->authenticator->authenticate($request);
            $body = RequestPayload::read($request);
            $returnTo = $this->tickets->validateReturnTo((string) ($body['return_to'] ?? '/'));
            $user = $this->provisioner->ensure(
                (string) ($body['sub'] ?? ''),
                (string) ($body['email'] ?? ''),
                filter_var($body['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
            );
            $ticket = $this->tickets->issue($user, $returnTo);

            return $this->json([
                'ok' => true,
                'entry_path' => '/auth/flatrate/session?ticket='.rawurlencode($ticket),
                'expires_in' => TicketStore::TTL_SECONDS,
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
