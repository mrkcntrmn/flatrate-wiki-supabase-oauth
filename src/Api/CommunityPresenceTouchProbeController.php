<?php

namespace FlatRate\SupabaseOAuth\Api;

use FlatRate\SupabaseOAuth\Presence\TrustedCoarseRegion;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * H.0B probe-only route: POST /api/flatrate/community-presence/touch
 *
 * Administrator-only. Echoes normalized trusted geography headers for ingress
 * proof. Does not store presence, hash subjects, emit Activity, or log location.
 *
 * Replace probe behavior in H.1; remove the geography echo from the permanent API.
 */
final class CommunityPresenceTouchProbeController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        if (! $actor->isAdmin()) {
            throw new PermissionDeniedException();
        }

        // Ignore any client JSON body (user id / state / lat / lng are never trusted).
        $geo = TrustedCoarseRegion::fromRequest($request);

        $payload = [
            'probe' => true,
            'country_header_present' => $geo['country_header_present'],
            'region_header_present' => $geo['region_header_present'],
            'country' => $geo['country'],
            'region_code' => $geo['region_code'],
        ];

        return new JsonResponse($payload, 200, [
            'Cache-Control' => 'no-store',
        ]);
    }
}
