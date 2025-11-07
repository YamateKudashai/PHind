<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Exceptions;

use Exception;

class EmbeddingException extends Exception
{
    public static function providerNotAvailable(string $provider): self
    {
        return new self("Embedding provider '{$provider}' is not available or configured");
    }

    public static function invalidDimension(int $expected, int $actual): self
    {
        return new self("Invalid embedding dimension. Expected {$expected}, got {$actual}");
    }

    public static function inputTooLong(int $maxLength, int $actualLength): self
    {
        return new self("Input text is too long. Maximum {$maxLength} characters, got {$actualLength}");
    }

    public static function apiError(string $provider, string $message): self
    {
        return new self("API error from {$provider}: {$message}");
    }
}