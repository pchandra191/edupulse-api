<?php

declare(strict_types=1);

namespace App\Exception;

class AnalysisNotFoundException extends \RuntimeException
{
    public static function create(): self
    {
        return new self('Analysis not found.');
    }
}