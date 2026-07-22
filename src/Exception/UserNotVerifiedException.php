<?php

declare(strict_types=1);

namespace App\Exception;

class UserNotVerifiedException extends AuthenticationException
{
    public static function create(): self
    {
        return new self('Email is not verified.');
    }
}
