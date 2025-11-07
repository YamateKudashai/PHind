<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Contracts;

use Phind\SemanticSearch\Data\SearchQuery;
use Phind\SemanticSearch\Data\SearchResult;

interface SearchEngine
{
    /**
     * Perform a search operation.
     */
    public function search(SearchQuery $query): SearchResult;

    /**
     * Index a document for search.
     *
     * @param string $id Document identifier
     * @param array $document Document data
     * @param string $index Index name
     */
    public function index(string $id, array $document, string $index): void;

    /**
     * Index multiple documents at once.
     *
     * @param array $documents Array of [id, document] pairs
     * @param string $index Index name
     */
    public function indexBatch(array $documents, string $index): void;

    /**
     * Update a document in the index.
     */
    public function update(string $id, array $document, string $index): void;

    /**
     * Remove a document from the index.
     */
    public function remove(string $id, string $index): void;

    /**
     * Remove multiple documents from the index.
     */
    public function removeBatch(array $ids, string $index): void;

    /**
     * Clear all documents from an index.
     */
    public function clearIndex(string $index): void;

    /**
     * Create a new search index.
     */
    public function createIndex(string $index, array $config = []): void;

    /**
     * Delete a search index.
     */
    public function deleteIndex(string $index): void;

    /**
     * Check if an index exists.
     */
    public function indexExists(string $index): bool;

    /**
     * Get index statistics.
     */
    public function getIndexStats(string $index): array;
}