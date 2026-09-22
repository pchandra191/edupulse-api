<?php

declare(strict_types=1);

namespace App\Exception;

class UserNotVerifiedException extends AuthenticationException
{
    public static function create(): self
    {
        return new self('Please verify your email address before signing in.');
    }

    public function getErrorCode(): string
    {
        return 'EMAIL_NOT_VERIFIED';
    }
}