<?php

namespace FlatRate\SupabaseOAuth\AdminGamify;

use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

final class SharingController extends AbstractAdminGamifyController
{
    protected function handleAdmin(ServerRequestInterface $request, User $actor): array
    {
        $body = $this->bridgeBody($actor, $request);
        $bridge = $this->bridgeOrUnavailable('sharing', $body);

        return array_merge(['ok' => true], $bridge);
    }
}
