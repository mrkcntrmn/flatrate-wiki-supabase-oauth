<?php

namespace FlatRate\SupabaseOAuth\Sso;

use FlatRate\SupabaseOAuth\Auth\FlatRateUserProvisioner;
use FoF\OAuth\Errors\AuthenticationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class TicketController implements RequestHandlerInterface
{
    public function __construct(
        private SharedSecretAuthenticator $authenticator,
        private FlatRateUserProvisioner $provisioner,
        private TicketStore $tickets
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->authenticator->authenticate($request);
            $body = $this->payload($request);
            $sub = trim((string) ($body['sub'] ?? ''));
            $email = trim((string) ($body['email'] ?? ''));
            $verified = filter_var($body['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $returnTo = $this->tickets->validateReturnTo((string) ($body['return_to'] ?? '/'));

            $user = $this->provisioner->ensure($sub, $email, $verified, $body);
            $ticket = $this->tickets->issue($user, $returnTo);

            return $this->json([
                'ok' => true,
                'entry_path' => '/auth/flatrate/session?ticket='.rawurlencode($ticket),
                'expires_in' => TicketStore::TTL_SECONDS,
            ]);
        } catch (SsoException $error) {
            return $this->json(['error' => $error->errorCode], $error->statusCode);
        } catch (AuthenticationException $error) {
            $status = $error->getMessage() === 'existing_account_requires_explicit_link' ? 409 : 400;
            return $this->json(['error' => $error->getMessage()], $status);
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
