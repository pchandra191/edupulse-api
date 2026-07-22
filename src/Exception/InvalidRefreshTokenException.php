<?php

declare(strict_types=1);

namespace App\Exception;

class InvalidRefreshTokenException extends AuthenticationException
{
    public static function create(): self
    {
        return new self('Invalid refresh token.');
    }
}
