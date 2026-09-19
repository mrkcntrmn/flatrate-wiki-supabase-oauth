<?php

namespace FlatRate\SupabaseOAuth\Voting;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/flatrate-voting/readiness — admin-only, no mutation, no secrets.
 */
final class VotingReadinessController implements RequestHandlerInterface
{
    public function __construct(private VotingReadiness $readiness)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        return new JsonResponse($this->readiness->build());
    }
}
