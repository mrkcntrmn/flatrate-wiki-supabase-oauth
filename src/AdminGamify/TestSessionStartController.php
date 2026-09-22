<?php

namespace FlatRate\SupabaseOAuth\AdminGamify;

use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

final class TestSessionStartController extends AbstractAdminGamifyController
{
    protected function handleAdmin(ServerRequestInterface $request, User $actor): array
    {
        AdminGamifyHttp::parseJsonBody($request);

        $payload = $this->bridgeAction('test_session_start', [
            'actor_flarum_user_id' => (int) $actor->id,
        ]);

        return array_merge(['ok' => true], $payload);
    }
}
