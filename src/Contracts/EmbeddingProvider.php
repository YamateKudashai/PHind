<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Contracts;

interface EmbeddingProvider
{
    /**
     * Generate embeddings for the given text.
     *
     * @param string|array $text Single text or array of texts
     * @return array Vector embeddings
     */
    public function embed(string|array $text): array;

    /**
     * Get the dimension of the embeddings.
     */
    public function getDimension(): int;

    /**
     * Get the maximum input length for this provider.
     */
    public function getMaxInputLength(): int;

    /**
     * Get the provider name.
     */
    public function getName(): string;

    /**
     * Check if the provider is available.
     */
    public function isAvailable(): bool;
}