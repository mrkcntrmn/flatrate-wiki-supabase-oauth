<?php

namespace FlatRate\SupabaseOAuth\Canary;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Flarum adds Referrer-Policy: same-origin after the controller returns.
 * The ticket response must be the last policy the browser sees.
 */
final class TicketHeaderMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        if ($request->getUri()->getPath() !== '/auth/flatrate/session') {
            return $response;
        }

        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
