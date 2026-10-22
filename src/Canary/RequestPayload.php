<?php

namespace FlatRate\SupabaseOAuth\Canary;

use FlatRate\SupabaseOAuth\Sso\SsoException;
use Psr\Http\Message\ServerRequestInterface;

final class RequestPayload
{
    public static function read(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body)) {
            return $body;
        }

        $decoded = json_decode((string) $request->getBody(), true);
        if (! is_array($decoded)) {
            throw new SsoException('invalid_json', 400);
        }

        return $decoded;
    }
}
