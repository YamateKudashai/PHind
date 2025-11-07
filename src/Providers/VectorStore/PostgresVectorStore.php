<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Providers\VectorStore;

use Phind\SemanticSearch\Contracts\VectorStore;
use Phind\SemanticSearch\Data\SearchHit;
use Phind\SemanticSearch\Data\SearchResult;
use Phind\SemanticSearch\Exceptions\VectorStoreException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class PostgresVectorStore implements VectorStore
{
    private Connection $connection;
    private string $table;

    public function __construct(
        ?Connection $connection = null,
        string $table = 'vector_embeddings'
    ) {
        $this->connection = $connection ?? DB::connection();
        $this->table = $table;
    }

    public function store(string $id, array $vector, array $metadata, string $collection): void
    {
        $this->storeBatch([[$id, $vector, $metadata]], $collection);
    }

    public function storeBatch(array $vectors, string $collection): void
    {
        try {
            $this->connection->transaction(function () use ($vectors, $collection) {
                foreach ($vectors as [$id, $vector, $metadata]) {
                    $this->connection->table($this->table)->updateOrInsert(
                        ['id' => $id, 'collection' => $collection],
                        [
                            'vector' => '[' . implode(',', $vector) . ']',
                            'metadata' => json_encode($metadata),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                }
            });
        } catch (\Exception $e) {
            throw new VectorStoreException("Failed to store vectors: {$e->getMessage()}", 0, $e);
        }
    }

    public function search(array $queryVector, string $collection, int $limit = 10, array $filters = []): SearchResult
    {
        $startTime = microtime(true);

        try {
            $vectorStr = '[' . implode(',', $queryVector) . ']';
            
            $query = $this->connection->table($this->table)
                ->select([
                    'id',
                    'metadata',
                    $this->connection->raw("1 - (vector <=> '{$vectorStr}') as score")
                ])
                ->where('collection', $collection)
                ->orderBy('score', 'desc')
                ->limit($limit);

            // Apply filters
            foreach ($filters as $field => $value) {
                if (is_array($value)) {
                    $query->whereIn("metadata->>'{$field}'", $value);
                } else {
                    $query->where("metadata->>'{$field}'", $value);
                }
            }

            $results = $query->get();

            $hits = $results->map(function ($result) {
                $metadata = json_decode($result->metadata, true) ?? [];
                $document = array_merge($metadata, ['id' => $result->id]);

                return new SearchHit(
                    id: $result->id,
                    document: $document,
                    score: (float) $result->score,
                    source: 'semantic'
                );
            })->toArray();

            return new SearchResult(
                hits: $hits,
                total: count($hits),
                offset: 0,
                limit: $limit,
                processingTime: microtime(true) - $startTime,
                query: ['vector_search' => true]
            );
        } catch (\Exception $e) {
            throw new VectorStoreException("Failed to search vectors: {$e->getMessage()}", 0, $e);
        }
    }

    public function delete(string $id, string $collection): void
    {
        $this->deleteBatch([$id], $collection);
    }

    public function deleteBatch(array $ids, string $collection): void
    {
        try {
            $this->connection->table($this->table)
                ->where('collection', $collection)
                ->whereIn('id', $ids)
                ->delete();
        } catch (\Exception $e) {
            throw new VectorStoreException("Failed to delete vectors: {$e->getMessage()}", 0, $e);
        }
    }

    public function createCollection(string $collection, int $dimension, array $config = []): void
    {
        try {
            // Create table if it doesn't exist
            if (!$this->connection->getSchemaBuilder()->hasTable($this->table)) {
                $this->connection->getSchemaBuilder()->create($this->table, function ($table) use ($dimension) {
                    $table->string('id');
                    $table->string('collection');
                    $table->json('metadata');
                    $table->timestamps();
                    
                    // Add vector column - this requires pgvector extension
                    $table->getConnection()->statement(
                        "ALTER TABLE {$this->table} ADD COLUMN vector vector({$dimension})"
                    );
                    
                    $table->primary(['id', 'collection']);
                    $table->index('collection');
                });

                // Create HNSW index for vector similarity search
                $this->connection->statement(
                    "CREATE INDEX ON {$this->table} USING hnsw (vector vector_cosine_ops)"
                );
            }
        } catch (\Exception $e) {
            throw new VectorStoreException("Failed to create collection: {$e->getMessage()}", 0, $e);
        }
    }

    public function deleteCollection(string $collection): void
    {
        try {
            $this->connection->table($this->table)
                ->where('collection', $collection)
                ->delete();
        } catch (\Exception $e) {
            throw new VectorStoreException("Failed to delete collection: {$e->getMessage()}", 0, $e);
        }
    }

    public function collectionExists(string $collection): bool
    {
        try {
            return $this->connection->table($this->table)
                ->where('collection', $collection)
                ->exists();
        } catch (\Exception) {
            return false;
        }
    }

    public function getCollectionStats(string $collection): array
    {
        try {
            $stats = $this->connection->table($this->table)
                ->select([
                    $this->connection->raw('COUNT(*) as total_vectors'),
                    $this->connection->raw('MIN(created_at) as oldest_vector'),
                    $this->connection->raw('MAX(updated_at) as newest_vector'),
                ])
                ->where('collection', $collection)
                ->first();

            return [
                'total_vectors' => (int) $stats->total_vectors,
                'oldest_vector' => $stats->oldest_vector,
                'newest_vector' => $stats->newest_vector,
                'collection' => $collection,
            ];
        } catch (\Exception $e) {
            throw new VectorStoreException("Failed to get collection stats: {$e->getMessage()}", 0, $e);
        }
    }

    public function getName(): string
    {
        return 'postgresql-pgvector';
    }

    /**
     * Set up pgvector extension (requires superuser privileges).
     */
    public function setupPgVector(): void
    {
        try {
            $this->connection->statement('CREATE EXTENSION IF NOT EXISTS vector;');
        } catch (\Exception $e) {
            throw new VectorStoreException("Failed to setup pgvector extension: {$e->getMessage()}", 0, $e);
        }
    }
}