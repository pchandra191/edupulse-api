<?php
declare(strict_types=1);

namespace App\Exception;

class InstituteNotActiveException extends \RuntimeException
{
    public static function create(string $status): self
    {
        return new self("Institute is {$status}. Please contact support.");
    }
}
