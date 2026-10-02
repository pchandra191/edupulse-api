<?php

declare(strict_types=1);

namespace App\Exception;

class AuthorizationException extends \RuntimeException
{
    public static function create(string $message = 'Access denied.'): self
    {
        return new self($message);
    }
}