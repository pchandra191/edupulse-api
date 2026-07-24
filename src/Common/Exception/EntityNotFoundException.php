<?php
declare(strict_types=1);

namespace App\Common\Exception;

class EntityNotFoundException extends \RuntimeException
{
    public static function forEntity(string $entity, string $identifier): self
    {
        return new self(sprintf('%s with identifier "%s" not found.', $entity, $identifier));
    }
}
