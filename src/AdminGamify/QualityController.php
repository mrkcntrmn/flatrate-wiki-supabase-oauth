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

        $summary = $this->quality->siteSummary();
        try {
            $leaderboard = $this->quality->contributorLeaderboard($parsed['limit']);
        } catch (\Throwable $e) {
            $leaderboard = [
                'status' => 'unavailable',
                'error' => 'leaderboard_query_failed',
                'time_window_support' => is_array($summary) ? ($summary['time_window_support'] ?? false) : false,
                'time_window' => is_array($summary) ? ($summary['time_window'] ?? 'current_effective_state/all_time') : 'current_effective_state/all_time',
                'rows' => [],
            ];
        }

        return [
            'ok' => true,
            'generated_at' => gmdate('c'),
            'requested_window' => $parsed['window'],
            'sources' => [
                'quality_signals' => ['status' => ($summary['status'] ?? null) === 'ok' ? 'ok' : 'unavailable'],
            ],
            'data' => [
                'summary' => $summary,
                'leaderboard' => $leaderboard,
            ],
        ];
    }
}
