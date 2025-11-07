<?php

declare(strict_types=1);

namespace Phind\SemanticSearch;

use Phind\SemanticSearch\Contracts\SearchEngine;
use Phind\SemanticSearch\Contracts\EmbeddingProvider;
use Phind\SemanticSearch\Contracts\VectorStore;
use Phind\SemanticSearch\Data\SearchQuery;
use Phind\SemanticSearch\Data\SearchResult;
use Phind\SemanticSearch\Features\TypoTolerance;
use Phind\SemanticSearch\Features\FacetedSearch;
use Phind\SemanticSearch\Features\RelevanceTuning;
use Illuminate\Support\Facades\Cache;

class SemanticSearchManager
{
    private SearchEngine $searchEngine;
    private EmbeddingProvider $embeddingProvider;
    private VectorStore $vectorStore;
    private array $config;
    private ?TypoTolerance $typoTolerance = null;
    private ?FacetedSearch $facetedSearch = null;
    private ?RelevanceTuning $relevanceTuning = null;

    // Query builder state
    private ?string $currentQuery = null;
    private ?string $currentIndex = null;
    private array $currentFilters = [];
    private array $currentFacets = [];
    private int $currentLimit = 20;
    private int $currentOffset = 0;
    private bool $includeKeywords = true;
    private bool $includeSemantic = true;
    private float $semanticWeight = 0.7;
    private float $keywordWeight = 0.3;

    public function __construct(
        SearchEngine $searchEngine,
        EmbeddingProvider $embeddingProvider,
        VectorStore $vectorStore,
        array $config = []
    ) {
        $this->searchEngine = $searchEngine;
        $this->embeddingProvider = $embeddingProvider;
        $this->vectorStore = $vectorStore;
        $this->config = $config;
        $this->currentLimit = $config['limits']['default_limit'] ?? 20;

        $this->initializeFeatures();
    }

    /**
     * Start a search query builder.
     */
    public function query(string $query): self
    {
        $clone = clone $this;
        $clone->currentQuery = $query;
        return $clone;
    }

    /**
     * Set the index to search in.
     */
    public function in(string $index): self
    {
        $this->currentIndex = $index;
        return $this;
    }

    /**
     * Add filters to the search query.
     */
    public function where(string $field, mixed $value): self
    {
        $this->currentFilters[$field] = $value;
        return $this;
    }

    /**
     * Add multiple filters to the search query.
     */
    public function whereIn(string $field, array $values): self
    {
        $this->currentFilters[$field] = $values;
        return $this;
    }

    /**
     * Set facets to include in results.
     */
    public function withFacets(array $facets): self
    {
        $this->currentFacets = $facets;
        return $this;
    }

    /**
     * Set the maximum number of results.
     */
    public function limit(int $limit): self
    {
        $this->currentLimit = $limit;
        return $this;
    }

    /**
     * Set the offset for pagination.
     */
    public function offset(int $offset): self
    {
        $this->currentOffset = $offset;
        return $this;
    }

    /**
     * Enable or disable keyword search.
     */
    public function withKeywords(bool $enabled = true): self
    {
        $this->includeKeywords = $enabled;
        return $this;
    }

    /**
     * Enable or disable semantic search.
     */
    public function withSemantic(bool $enabled = true): self
    {
        $this->includeSemantic = $enabled;
        return $this;
    }

    /**
     * Set the weights for hybrid search.
     */
    public function withWeights(float $semanticWeight, float $keywordWeight = null): self
    {
        $this->semanticWeight = $semanticWeight;
        $this->keywordWeight = $keywordWeight ?? (1.0 - $semanticWeight);
        return $this;
    }

    /**
     * Use only keyword search.
     */
    public function onlyKeywords(): self
    {
        return $this->withKeywords(true)->withSemantic(false)->withWeights(0.0, 1.0);
    }

    /**
     * Use only semantic search.
     */
    public function onlySemantic(): self
    {
        return $this->withKeywords(false)->withSemantic(true)->withWeights(1.0, 0.0);
    }

    /**
     * Execute the search query.
     */
    public function search(): SearchResult
    {
        if (!$this->currentQuery || !$this->currentIndex) {
            throw new \InvalidArgumentException('Query and index must be set before searching');
        }

        // Apply typo tolerance if enabled
        $processedQuery = $this->currentQuery;
        if ($this->typoTolerance && $this->config['typo_tolerance']['enabled'] ?? false) {
            $processedQuery = $this->typoTolerance->correctQuery($this->currentQuery);
        }

        // Build search query
        $searchQuery = new SearchQuery(
            query: $processedQuery,
            index: $this->currentIndex,
            limit: $this->currentLimit,
            offset: $this->currentOffset,
            filters: $this->currentFilters,
            facets: $this->currentFacets,
            includeKeywords: $this->includeKeywords,
            includeSemantic: $this->includeSemantic,
            semanticWeight: $this->semanticWeight,
            keywordWeight: $this->keywordWeight
        );

        // Execute search with caching
        $cacheKey = $this->generateCacheKey($searchQuery);
        $result = null;

        if ($this->config['caching']['enabled'] ?? false) {
            $result = Cache::remember($cacheKey, $this->config['caching']['ttl'] ?? 3600, function () use ($searchQuery) {
                return $this->executeSearch($searchQuery);
            });
        } else {
            $result = $this->executeSearch($searchQuery);
        }

        // Process facets if requested
        if (!empty($this->currentFacets) && $this->facetedSearch) {
            $facets = $this->facetedSearch->processFacets($result, $this->currentFacets);
            $result = new SearchResult(
                hits: $result->getHits(),
                total: $result->getTotal(),
                offset: $result->getOffset(),
                limit: $result->getLimit(),
                processingTime: $result->getProcessingTime(),
                facets: $facets,
                query: $result->getQuery()
            );
        }

        // Apply relevance tuning if enabled
        if ($this->relevanceTuning && $this->config['relevance_tuning']['enabled'] ?? false) {
            $result = $this->relevanceTuning->boostResults($result, $this->config['relevance_tuning']);
        }

        return $result;
    }

    /**
     * Perform a simple search without query builder.
     */
    public function simpleSearch(string $query, string $index, array $options = []): SearchResult
    {
        return $this->query($query)
                   ->in($index)
                   ->limit($options['limit'] ?? $this->currentLimit)
                   ->offset($options['offset'] ?? 0)
                   ->search();
    }

    /**
     * Index a document.
     */
    public function index(string $id, array $document, string $index): void
    {
        $this->searchEngine->index($id, $document, $index);
        
        // Clear related caches
        $this->clearIndexCache($index);
    }

    /**
     * Index multiple documents at once.
     */
    public function indexBatch(array $documents, string $index): void
    {
        $this->searchEngine->indexBatch($documents, $index);
        
        // Clear related caches
        $this->clearIndexCache($index);
    }

    /**
     * Remove a document from the index.
     */
    public function remove(string $id, string $index): void
    {
        $this->searchEngine->remove($id, $index);
        
        // Clear related caches
        $this->clearIndexCache($index);
    }

    /**
     * Remove multiple documents from the index.
     */
    public function removeBatch(array $ids, string $index): void
    {
        $this->searchEngine->removeBatch($ids, $index);
        
        // Clear related caches
        $this->clearIndexCache($index);
    }

    /**
     * Create a new search index.
     */
    public function createIndex(string $index, array $config = []): void
    {
        $this->searchEngine->createIndex($index, $config);
    }

    /**
     * Delete a search index.
     */
    public function deleteIndex(string $index): void
    {
        $this->searchEngine->deleteIndex($index);
        $this->clearIndexCache($index);
    }

    /**
     * Generate embeddings for text.
     */
    public function generateEmbedding(string $text): array
    {
        return $this->embeddingProvider->embed($text);
    }

    /**
     * Get statistics for an index.
     */
    public function getIndexStats(string $index): array
    {
        return $this->searchEngine->getIndexStats($index);
    }

    /**
     * Get available embedding providers.
     */
    public function getEmbeddingProviders(): array
    {
        return array_keys($this->config['embeddings'] ?? []);
    }

    /**
     * Get available vector stores.
     */
    public function getVectorStores(): array
    {
        return array_keys($this->config['vector_stores'] ?? []);
    }

    /**
     * Test embedding provider connectivity.
     */
    public function testEmbeddingProvider(): bool
    {
        return $this->embeddingProvider->isAvailable();
    }

    /**
     * Test vector store connectivity.
     */
    public function testVectorStore(): bool
    {
        try {
            // Try to get stats for a non-existent collection
            $this->vectorStore->getCollectionStats('_test_connectivity');
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    private function executeSearch(SearchQuery $searchQuery): SearchResult
    {
        return $this->searchEngine->search($searchQuery);
    }

    private function generateCacheKey(SearchQuery $query): string
    {
        $prefix = $this->config['caching']['prefix'] ?? 'semantic_search';
        $data = [
            'query' => $query->query,
            'index' => $query->index,
            'filters' => $query->filters,
            'limit' => $query->limit,
            'offset' => $query->offset,
            'weights' => [$query->semanticWeight, $query->keywordWeight],
        ];
        
        return $prefix . ':' . md5(json_encode($data));
    }

    private function clearIndexCache(string $index): void
    {
        if (!($this->config['caching']['enabled'] ?? false)) {
            return;
        }

        $prefix = $this->config['caching']['prefix'] ?? 'semantic_search';
        
        // This is a simple implementation - in production you might want
        // to use cache tags or a more sophisticated cache invalidation strategy
        Cache::flush(); // or implement selective cache clearing
    }

    private function initializeFeatures(): void
    {
        if ($this->config['typo_tolerance']['enabled'] ?? false) {
            $this->typoTolerance = new TypoTolerance($this->config['typo_tolerance']);
        }

        if ($this->config['faceted_search']['enabled'] ?? false) {
            $this->facetedSearch = new FacetedSearch($this->config['faceted_search']);
        }

        if ($this->config['relevance_tuning']['enabled'] ?? false) {
            $this->relevanceTuning = new RelevanceTuning($this->config['relevance_tuning']);
        }
    }
}