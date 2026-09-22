<?php

namespace FlatRate\SupabaseOAuth\AdminGamify;

use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

final class TestSessionStatusController extends AbstractAdminGamifyController
{
    protected function handleAdmin(ServerRequestInterface $request, User $actor): array
    {
        $json = AdminGamifyHttp::parseJsonBody($request);

        $body = [
            'actor_flarum_user_id' => (int) $actor->id,
        ];
        if (isset($json['test_session_id']) && $json['test_session_id'] !== null && $json['test_session_id'] !== '') {
            $body['test_session_id'] = $json['test_session_id'];
        }

        $payload = $this->bridgeAction('test_session_status', $body);

        return array_merge(['ok' => true], $payload);
    }
}
