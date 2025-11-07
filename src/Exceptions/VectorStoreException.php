<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Exceptions;

use Exception;

class VectorStoreException extends Exception
{
    public static function collectionNotFound(string $collection): self
    {
        return new self("Collection '{$collection}' not found");
    }

    public static function connectionFailed(string $store, string $reason): self
    {
        return new self("Failed to connect to {$store}: {$reason}");
    }

    public static function invalidVector(string $reason): self
    {
        return new self("Invalid vector: {$reason}");
    }

    public static function operationFailed(string $operation, string $reason): self
    {
        return new self("Vector store operation '{$operation}' failed: {$reason}");
    }
}