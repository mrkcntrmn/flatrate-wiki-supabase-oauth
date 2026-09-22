<?php

namespace FlatRate\SupabaseOAuth\AdminGamify;

use RuntimeException;

final class AdminGamifyRequestException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $statusCode = 400
    ) {
        parent::__construct($errorCode);
    }
}
