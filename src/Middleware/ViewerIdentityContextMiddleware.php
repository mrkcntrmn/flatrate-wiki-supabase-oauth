<?php

namespace FlatRate\SupabaseOAuth\Middleware;

use FlatRate\SupabaseOAuth\Identity\ViewerIdentityContext;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Pushes the request actor onto ViewerIdentityContext for the duration of handling.
 * Always pops in finally so nested/internal requests and exceptions restore correctly.
 */
final class ViewerIdentityContextMiddleware implements MiddlewareInterface
{
    public function __construct(private ViewerIdentityContext $context)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->context->push(RequestUtil::getActor($request));

        try {
            return $handler->handle($request);
        } finally {
            $this->context->pop();
        }
    }
}
