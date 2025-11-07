<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Contracts;

use Phind\SemanticSearch\Data\SearchQuery;
use Phind\SemanticSearch\Data\SearchResult;

interface VectorStore
{
    /**
     * Store a vector with its metadata.
     *
     * @param string $id Unique identifier
     * @param array $vector The embedding vector
     * @param array $metadata Associated metadata
     * @param string $collection Collection/index name
     */
    public function store(string $id, array $vector, array $metadata, string $collection): void;

    /**
     * Store multiple vectors at once.
     *
     * @param array $vectors Array of [id, vector, metadata]
     * @param string $collection Collection/index name
     */
    public function storeBatch(array $vectors, string $collection): void;

    /**
     * Search for similar vectors.
     *
     * @param array $queryVector The query embedding
     * @param string $collection Collection/index name
     * @param int $limit Number of results to return
     * @param array $filters Additional filters
     * @return SearchResult
     */
    public function search(array $queryVector, string $collection, int $limit = 10, array $filters = []): SearchResult;

    /**
     * Delete a vector by ID.
     */
    public function delete(string $id, string $collection): void;

    /**
     * Delete multiple vectors by IDs.
     */
    public function deleteBatch(array $ids, string $collection): void;

    /**
     * Create a new collection/index.
     *
     * @param string $collection Collection name
     * @param int $dimension Vector dimension
     * @param array $config Additional configuration
     */
    public function createCollection(string $collection, int $dimension, array $config = []): void;

    /**
     * Delete a collection/index.
     */
    public function deleteCollection(string $collection): void;

    /**
     * Check if a collection exists.
     */
    public function collectionExists(string $collection): bool;

    /**
     * Get collection statistics.
     */
    public function getCollectionStats(string $collection): array;

    /**
     * Get the store name/type.
     */
    public function getName(): string;
}