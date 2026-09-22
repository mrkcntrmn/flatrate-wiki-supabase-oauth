<?php

namespace FlatRate\SupabaseOAuth\AdminGamify;

use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

final class TestShareStatusController extends AbstractAdminGamifyController
{
    protected function handleAdmin(ServerRequestInterface $request, User $actor): array
    {
        $json = AdminGamifyHttp::parseJsonBody($request);

        $payload = $this->bridgeAction('test_share_status', [
            'actor_flarum_user_id' => (int) $actor->id,
            'test_session_id' => $json['test_session_id'] ?? null,
            'share_code' => $json['share_code'] ?? null,
        ]);

        return array_merge(['ok' => true], $payload);
    }
}
