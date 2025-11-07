<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Engine;

use Phind\SemanticSearch\Contracts\SearchEngine;
use Phind\SemanticSearch\Data\SearchQuery;
use Phind\SemanticSearch\Data\SearchResult;
use Phind\SemanticSearch\Data\SearchHit;
use Phind\SemanticSearch\Exceptions\SearchException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class KeywordSearchEngine implements SearchEngine
{
    private Connection $connection;
    private string $table;
    private array $config;

    public function __construct(
        ?Connection $connection = null,
        string $table = 'search_index',
        array $config = []
    ) {
        $this->connection = $connection ?? DB::connection();
        $this->table = $table;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function search(SearchQuery $query): SearchResult
    {
        $startTime = microtime(true);

        try {
            $searchTerms = $this->parseSearchQuery($query->query);
            
            $builder = $this->connection->table($this->table)
                ->where('index_name', $query->index);

            // Apply search conditions
            if (!empty($searchTerms)) {
                $builder->where(function ($subQuery) use ($searchTerms, $query) {
                    foreach ($searchTerms as $term) {
                        if ($query->typoTolerant) {
                            $subQuery->orWhereRaw('content_vector @@ plainto_tsquery(?)', [$term])
                                    ->orWhere('title', 'ILIKE', "%{$term}%")
                                    ->orWhere('content', 'ILIKE', "%{$term}%");
                        } else {
                            $subQuery->orWhere('title', 'LIKE', "%{$term}%")
                                    ->orWhere('content', 'LIKE', "%{$term}%");
                        }
                    }
                });
            }

            // Apply filters
            foreach ($query->filters as $field => $value) {
                if (is_array($value)) {
                    $builder->whereIn("metadata->>'{$field}'", $value);
                } else {
                    $builder->where("metadata->>'{$field}'", $value);
                }
            }

            // Apply sorting
            if (!empty($query->sortBy)) {
                foreach ($query->sortBy as $field => $direction) {
                    $builder->orderBy($field, $direction);
                }
            } else {
                // Default relevance sorting using ts_rank for full-text search
                if (!empty($searchTerms)) {
                    $builder->selectRaw('*, ts_rank(content_vector, plainto_tsquery(?)) as relevance_score', [implode(' ', $searchTerms)])
                           ->orderBy('relevance_score', 'desc');
                } else {
                    $builder->select('*', DB::raw('1.0 as relevance_score'))
                           ->orderBy('updated_at', 'desc');
                }
            }

            $total = $builder->count();
            
            $results = $builder->skip($query->offset)
                              ->take($query->limit)
                              ->get();

            $hits = $results->map(function ($result) use ($query) {
                $metadata = json_decode($result->metadata ?? '{}', true);
                $document = array_merge($metadata, [
                    'id' => $result->document_id,
                    'title' => $result->title,
                    'content' => $result->content,
                ]);

                $highlights = $this->generateHighlights(
                    $result->content,
                    $this->parseSearchQuery($query->query),
                    $query->highlightFields
                );

                return new SearchHit(
                    id: $result->document_id,
                    document: $document,
                    score: (float) ($result->relevance_score ?? 1.0),
                    highlights: $highlights,
                    source: 'keyword'
                );
            })->toArray();

            return new SearchResult(
                hits: $hits,
                total: $total,
                offset: $query->offset,
                limit: $query->limit,
                processingTime: microtime(true) - $startTime,
                query: ['keyword_search' => $query->query]
            );
        } catch (\Exception $e) {
            throw new SearchException("Keyword search failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function index(string $id, array $document, string $index): void
    {
        $this->indexBatch([[$id, $document]], $index);
    }

    public function indexBatch(array $documents, string $index): void
    {
        try {
            $this->connection->transaction(function () use ($documents, $index) {
                foreach ($documents as [$id, $document]) {
                    $this->connection->table($this->table)->updateOrInsert(
                        ['document_id' => $id, 'index_name' => $index],
                        [
                            'title' => $document['title'] ?? '',
                            'content' => $this->extractContent($document),
                            'content_vector' => $this->connection->raw('to_tsvector(?)'),
                            'metadata' => json_encode($document),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                }
            });
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
            $this->connection->table($this->table)
                ->where('index_name', $index)
                ->whereIn('document_id', $ids)
                ->delete();
        } catch (\Exception $e) {
            throw new SearchException("Failed to remove documents: {$e->getMessage()}", 0, $e);
        }
    }

    public function clearIndex(string $index): void
    {
        try {
            $this->connection->table($this->table)
                ->where('index_name', $index)
                ->delete();
        } catch (\Exception $e) {
            throw new SearchException("Failed to clear index: {$e->getMessage()}", 0, $e);
        }
    }

    public function createIndex(string $index, array $config = []): void
    {
        try {
            // Create table if it doesn't exist
            if (!$this->connection->getSchemaBuilder()->hasTable($this->table)) {
                $this->connection->getSchemaBuilder()->create($this->table, function ($table) {
                    $table->string('document_id');
                    $table->string('index_name');
                    $table->text('title')->nullable();
                    $table->text('content');
                    $table->json('metadata');
                    $table->timestamps();
                    
                    $table->primary(['document_id', 'index_name']);
                    $table->index('index_name');
                });

                // Add full-text search column and index
                $this->connection->statement(
                    "ALTER TABLE {$this->table} ADD COLUMN content_vector tsvector"
                );
                
                $this->connection->statement(
                    "CREATE INDEX {$this->table}_content_vector_idx ON {$this->table} USING GIN(content_vector)"
                );
            }
        } catch (\Exception $e) {
            throw new SearchException("Failed to create index: {$e->getMessage()}", 0, $e);
        }
    }

    public function deleteIndex(string $index): void
    {
        $this->clearIndex($index);
    }

    public function indexExists(string $index): bool
    {
        try {
            return $this->connection->table($this->table)
                ->where('index_name', $index)
                ->exists();
        } catch (\Exception) {
            return false;
        }
    }

    public function getIndexStats(string $index): array
    {
        try {
            $stats = $this->connection->table($this->table)
                ->select([
                    $this->connection->raw('COUNT(*) as total_documents'),
                    $this->connection->raw('MIN(created_at) as oldest_document'),
                    $this->connection->raw('MAX(updated_at) as newest_document'),
                ])
                ->where('index_name', $index)
                ->first();

            return [
                'total_documents' => (int) $stats->total_documents,
                'oldest_document' => $stats->oldest_document,
                'newest_document' => $stats->newest_document,
                'index_name' => $index,
            ];
        } catch (\Exception $e) {
            throw new SearchException("Failed to get index stats: {$e->getMessage()}", 0, $e);
        }
    }

    private function parseSearchQuery(string $query): array
    {
        // Simple tokenization - could be enhanced with stemming, stop words, etc.
        return array_filter(
            array_map('trim', explode(' ', strtolower($query))),
            fn($term) => strlen($term) > 2
        );
    }

    private function extractContent(array $document): string
    {
        $fields = $this->config['searchable_fields'];
        $content = [];

        foreach ($fields as $field) {
            if (isset($document[$field]) && !empty($document[$field])) {
                $content[] = $document[$field];
            }
        }

        return implode(' ', $content);
    }

    private function generateHighlights(string $content, array $searchTerms, array $highlightFields = []): array
    {
        if (empty($searchTerms)) {
            return [];
        }

        $highlights = [];
        
        foreach ($searchTerms as $term) {
            $pattern = '/\b' . preg_quote($term, '/') . '\b/i';
            $highlighted = preg_replace($pattern, "<mark>{$term}</mark>", $content);
            
            if ($highlighted !== $content) {
                $highlights[] = $this->extractSnippet($highlighted, $term);
            }
        }

        return array_unique($highlights);
    }

    private function extractSnippet(string $content, string $term, int $snippetLength = 200): string
    {
        $pos = stripos($content, $term);
        if ($pos === false) {
            return substr($content, 0, $snippetLength) . '...';
        }

        $start = max(0, $pos - $snippetLength / 2);
        $snippet = substr($content, $start, $snippetLength);

        if ($start > 0) {
            $snippet = '...' . $snippet;
        }
        
        if (strlen($content) > $start + $snippetLength) {
            $snippet .= '...';
        }

        return $snippet;
    }

    private function getDefaultConfig(): array
    {
        return [
            'searchable_fields' => ['title', 'content', 'description'],
        ];
    }
}