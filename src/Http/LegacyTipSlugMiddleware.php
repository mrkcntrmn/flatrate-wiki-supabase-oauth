<?php

declare(strict_types=1);

namespace FlatRate\SupabaseOAuth\Http;

use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Old Tip links used the machine slug job-breakdown. The live tag slug is tip.
 * This answers GET and HEAD for the old path with a same-origin redirect.
 * It does not look up the reply-marker table and it does not rename the tag.
 */
final class LegacyTipSlugMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if ($path !== '/t/job-breakdown') {
            return $handler->handle($request);
        }

        $target = '/t/tip';
        $query = $request->getUri()->getQuery();
        if ($query !== '' && preg_match('/^[A-Za-z0-9._~=&%+\-]*$/', $query) === 1) {
            $target .= '?'.$query;
        }

        return new RedirectResponse($target, 301);
    }
}
