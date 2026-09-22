<?php

namespace FlatRate\SupabaseOAuth\AdminGamify;

use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

final class OverviewController extends AbstractAdminGamifyController
{
    protected function handleAdmin(ServerRequestInterface $request, User $actor): array
    {
        $body = $this->bridgeBody($actor, $request);
        $bridge = $this->bridgeOrUnavailable('overview', $body);
        $quality = $this->quality->siteSummary();

        return [
            'ok' => true,
            'generated_at' => $bridge['generated_at'] ?? gmdate('c'),
            'window' => $body['window'],
            'sources' => [
                'quality_signals' => [
                    'status' => ($quality['status'] ?? 'unavailable') === 'ok' ? 'ok' : 'unavailable',
                ],
                'sharing' => $bridge['sources']['sharing'] ?? ['status' => 'unavailable'],
                'referrals' => $bridge['sources']['referrals'] ?? ['status' => 'unavailable'],
                'activity_funnel' => $bridge['sources']['activity_funnel'] ?? [
                    'status' => 'partial',
                    'note' => 'NOT_MEASURED stages remain',
                ],
            ],
            'coverage' => $bridge['coverage'] ?? null,
            'data' => [
                'quality_signals' => $quality,
                'sharing' => $bridge['data']['sharing'] ?? null,
                'referrals' => $bridge['data']['referrals'] ?? null,
            ],
        ];
    }
}
