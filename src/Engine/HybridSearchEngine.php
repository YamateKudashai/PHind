<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Engine;

use Phind\SemanticSearch\Contracts\SearchEngine;
use Phind\SemanticSearch\Contracts\VectorStore;
use Phind\SemanticSearch\Contracts\EmbeddingProvider;
use Phind\SemanticSearch\Data\SearchQuery;
use Phind\SemanticSearch\Data\SearchResult;
use Phind\SemanticSearch\Data\SearchHit;
use Phind\SemanticSearch\Exceptions\SearchException;
use Illuminate\Support\Facades\Cache;

class HybridSearchEngine implements SearchEngine
{
    private VectorStore $vectorStore;
    private EmbeddingProvider $embeddingProvider;
    private KeywordSearchEngine $keywordEngine;
    private array $config;

    public function __construct(
        VectorStore $vectorStore,
        EmbeddingProvider $embeddingProvider,
        KeywordSearchEngine $keywordEngine,
        array $config = []
    ) {
        $this->vectorStore = $vectorStore;
        $this->embeddingProvider = $embeddingProvider;
        $this->keywordEngine = $keywordEngine;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function search(SearchQuery $query): SearchResult
    {
        $startTime = microtime(true);

        try {
            $results = [];

            // Perform keyword search if enabled
            if ($query->includeKeywords) {
                $keywordResults = $this->performKeywordSearch($query);
                $results['keyword'] = $keywordResults;
            }

            // Perform semantic search if enabled
            if ($query->includeSemantic) {
                $semanticResults = $this->performSemanticSearch($query);
                $results['semantic'] = $semanticResults;
            }

            // Combine results using hybrid scoring
            $combinedResults = $this->combineResults($results, $query);

            return new SearchResult(
                hits: $combinedResults,
                total: count($combinedResults),
                offset: $query->offset,
                limit: $query->limit,
                processingTime: microtime(true) - $startTime,
                query: [
                    'query' => $query->query,
                    'semantic_weight' => $query->semanticWeight,
                    'keyword_weight' => $query->keywordWeight,
                ]
            );
        } catch (\Exception $e) {
            throw new SearchException("Hybrid search failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function index(string $id, array $document, string $index): void
    {
        $this->indexBatch([[$id, $document]], $index);
    }

    public function indexBatch(array $documents, string $index): void
    {
        try {
            // Index for keyword search
            $this->keywordEngine->indexBatch($documents, $index);

            // Generate embeddings and store in vector database
            $vectors = [];
            foreach ($documents as [$id, $document]) {
                $content = $this->extractSearchableContent($document);
                if (!empty($content)) {
                    $embedding = $this->generateEmbeddingWithCache($content);
                    $vectors[] = [$id, $embedding, $document];
                }
            }

            if (!empty($vectors)) {
                $this->vectorStore->storeBatch($vectors, $index);
            }
        } catch (\Exception $e) {
            throw new SearchException("Failed to index documents: {$e->getMessage()}", 0, $e);
        }
    }

    public function update(string $id, array $document, string $index): void
    {
        $this->index($id, $document, $index);
    }

    public function remove(string $id, string $index): void
    {
        $this->removeBatch([$id], $index);
    }

    public function removeBatch(array $ids, string $index): void
    {
        try {
            $this->keywordEngine->removeBatch($ids, $index);
            $this->vectorStore->deleteBatch($ids, $index);
        } catch (\Exception $e) {
            throw new SearchException("Failed to remove documents: {$e->getMessage()}", 0, $e);
        }
    }

    public function clearIndex(string $index): void
    {
        try {
            $this->keywordEngine->clearIndex($index);
            $this->vectorStore->deleteCollection($index);
        } catch (\Exception $e) {
            throw new SearchException("Failed to clear index: {$e->getMessage()}", 0, $e);
        }
    }

    public function createIndex(string $index, array $config = []): void
    {
        try {
            $this->keywordEngine->createIndex($index, $config);
            $this->vectorStore->createCollection(
                $index,
                $this->embeddingProvider->getDimension(),
                $config
            );
        } catch (\Exception $e) {
            throw new SearchException("Failed to create index: {$e->getMessage()}", 0, $e);
        }
    }

    public function deleteIndex(string $index): void
    {
        try {
            $this->keywordEngine->deleteIndex($index);
            $this->vectorStore->deleteCollection($index);
        } catch (\Exception $e) {
            throw new SearchException("Failed to delete index: {$e->getMessage()}", 0, $e);
        }
    }

    public function indexExists(string $index): bool
    {
        return $this->keywordEngine->indexExists($index) && 
               $this->vectorStore->collectionExists($index);
    }

    public function getIndexStats(string $index): array
    {
        return [
            'keyword_stats' => $this->keywordEngine->getIndexStats($index),
            'vector_stats' => $this->vectorStore->getCollectionStats($index),
        ];
    }

    private function performKeywordSearch(SearchQuery $query): array
    {
        $keywordResult = $this->keywordEngine->search($query);
        return $keywordResult->getHits();
    }

    private function performSemanticSearch(SearchQuery $query): array
    {
        $embedding = $this->generateEmbeddingWithCache($query->query);
        $vectorResult = $this->vectorStore->search(
            $embedding,
            $query->index,
            $query->limit * 2, // Get more results for better hybrid scoring
            $query->filters
        );
        
        return $vectorResult->getHits();
    }

    private function combineResults(array $results, SearchQuery $query): array
    {
        $combined = [];
        $seenIds = [];

        // Add keyword results with keyword scoring
        foreach ($results['keyword'] ?? [] as $hit) {
            $hybridScore = $hit->getScore() * $query->keywordWeight;
            
            $combined[$hit->getId()] = new SearchHit(
                id: $hit->getId(),
                document: $hit->getDocument(),
                score: $hybridScore,
                highlights: $hit->getHighlights(),
                metadata: array_merge($hit->getMetadata(), ['keyword_score' => $hit->getScore()]),
                source: 'keyword'
            );
            
            $seenIds[$hit->getId()] = true;
        }

        // Add or boost semantic results
        foreach ($results['semantic'] ?? [] as $hit) {
            $semanticScore = $hit->getScore() * $query->semanticWeight;
            
            if (isset($combined[$hit->getId()])) {
                // Combine scores for documents found in both searches
                $existingHit = $combined[$hit->getId()];
                $newScore = $existingHit->getScore() + $semanticScore;
                
                $combined[$hit->getId()] = new SearchHit(
                    id: $hit->getId(),
                    document: $existingHit->getDocument(),
                    score: $newScore,
                    highlights: $existingHit->getHighlights(),
                    metadata: array_merge($existingHit->getMetadata(), [
                        'semantic_score' => $hit->getScore(),
                    ]),
                    source: 'hybrid'
                );
            } else {
                // Add semantic-only results
                $combined[$hit->getId()] = new SearchHit(
                    id: $hit->getId(),
                    document: $hit->getDocument(),
                    score: $semanticScore,
                    highlights: [],
                    metadata: array_merge($hit->getMetadata(), ['semantic_score' => $hit->getScore()]),
                    source: 'semantic'
                );
            }
        }

        // Sort by combined score and apply limits
        $sorted = collect($combined)
            ->sortByDesc('score')
            ->skip($query->offset)
            ->take($query->limit)
            ->values()
            ->all();

        return $sorted;
    }

    private function generateEmbeddingWithCache(string $text): array
    {
        $cacheKey = 'embedding:' . md5($text . $this->embeddingProvider->getName());
        
        return Cache::remember($cacheKey, 3600, function () use ($text) {
            return $this->embeddingProvider->embed($text);
        });
    }

    private function extractSearchableContent(array $document): string
    {
        $searchableFields = $this->config['searchable_fields'] ?? ['title', 'content', 'description'];
        $content = [];

        foreach ($searchableFields as $field) {
            if (isset($document[$field]) && !empty($document[$field])) {
                $content[] = $document[$field];
            }
        }

        return implode(' ', $content);
    }

    private function getDefaultConfig(): array
    {
        return [
            'searchable_fields' => ['title', 'content', 'description'],
            'cache_embeddings' => true,
            'cache_ttl' => 3600,
        ];
    }
}