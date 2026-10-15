<?php

declare(strict_types=1);

namespace App\Exception;

class GoogleAuthException extends AuthenticationException
{
    public static function create(string $message): self
    {
        return new self($message);
    }
}