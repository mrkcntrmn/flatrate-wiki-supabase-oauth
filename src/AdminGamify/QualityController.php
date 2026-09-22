<?php

namespace FlatRate\SupabaseOAuth\AdminGamify;

use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

final class QualityController extends AbstractAdminGamifyController
{
    protected function handleAdmin(ServerRequestInterface $request, User $actor): array
    {
        $parsed = AdminGamifyHttp::parseQuery($request);
        // Window query is accepted for API symmetry but quality remains all-time
        // until PRODUCT-ACTIVITY vote events provide longitudinal timestamps.
        unset($actor);

        return [
            'ok' => true,
            'generated_at' => gmdate('c'),
            'requested_window' => $parsed['window'],
            'sources' => [
                'quality_signals' => ['status' => 'ok'],
            ],
            'data' => [
                'summary' => $this->quality->siteSummary(),
                'leaderboard' => $this->quality->contributorLeaderboard($parsed['limit']),
            ],
        ];
    }
}
