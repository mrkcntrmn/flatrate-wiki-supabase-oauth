<?php

namespace FlatRate\SupabaseOAuth\Identity;

use RuntimeException;

final class MemberDisplayException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $statusCode = 422
    ) {
        parent::__construct($errorCode);
    }
}
