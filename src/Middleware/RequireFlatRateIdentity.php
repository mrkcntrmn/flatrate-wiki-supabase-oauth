<?php

namespace FlatRate\SupabaseOAuth\Middleware;

use Flarum\Http\RequestUtil;
use Flarum\User\UserRepository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequireFlatRateIdentity implements MiddlewareInterface
{
    public function __construct(private UserRepository $users)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return $handler->handle($request);
        }

        $path = rtrim($request->getUri()->getPath(), '/');
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        if ($this->isPasswordLoginPath($path)) {
            $identification = trim((string) ($body['identification'] ?? ''));
            $user = $identification !== '' ? $this->users->findByIdentification($identification) : null;

            // Keep native password auth only as an unadvertised administrator
            // recovery mechanism. Ordinary users must authenticate through the
            // FlatRate Wiki OAuth provider.
            if (!$user || !$user->isAdmin()) {
                return $this->ssoRequired();
            }
        }

        if ($this->isUserCreationPath($path)) {
            $actor = RequestUtil::getActor($request);
            $token = $body['data']['attributes']['token'] ?? null;

            // OAuth registration arrives with a Flarum registration token.
            // Admins may still create recovery/operator accounts. Public native
            // username/password signup is rejected even if allow_sign_up=true.
            if (!$actor->isAdmin() && (!is_string($token) || trim($token) === '')) {
                return $this->ssoRequired();
            }
        }

        return $handler->handle($request);
    }

    private function isPasswordLoginPath(string $path): bool
    {
        return in_array($path, ['/login', '/api/token', '/token'], true);
    }

    private function isUserCreationPath(string $path): bool
    {
        return in_array($path, ['/api/users', '/users'], true);
    }

    private function ssoRequired(): JsonResponse
    {
        return new JsonResponse([
            'errors' => [[
                'status' => '403',
                'code' => 'flatrate_sso_required',
                'detail' => 'Continue with FlatRate Wiki to sign in or create your account.',
            ]],
        ], 403);
    }
}
