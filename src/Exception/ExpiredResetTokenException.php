<?php

declare(strict_types=1);

namespace App\Exception;

class ExpiredResetTokenException extends AuthenticationException
{
    public static function create(): self
    {
        return new self('Invalid or expired reset token.');
    }
}
