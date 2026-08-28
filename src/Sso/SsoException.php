<?php

namespace FlatRate\SupabaseOAuth\Sso;

use RuntimeException;

final class SsoException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $statusCode = 400
    ) {
        parent::__construct($errorCode);
    }
}
