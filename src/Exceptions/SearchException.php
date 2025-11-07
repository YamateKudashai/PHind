<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Exceptions;

use Exception;

class SearchException extends Exception
{
    public static function indexNotFound(string $index): self
    {
        return new self("Search index '{$index}' not found");
    }

    public static function queryTooShort(int $minLength): self
    {
        return new self("Search query must be at least {$minLength} characters long");
    }

    public static function invalidQuery(string $reason): self
    {
        return new self("Invalid search query: {$reason}");
    }

    public static function engineNotAvailable(string $engine): self
    {
        return new self("Search engine '{$engine}' is not available");
    }
}