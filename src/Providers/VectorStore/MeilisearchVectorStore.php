<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Providers\VectorStore;

use Phind\SemanticSearch\Contracts\VectorStore;
use Phind\SemanticSearch\Data\SearchHit;
use Phind\SemanticSearch\Data\SearchResult;
use Phind\SemanticSearch\Exceptions\VectorStoreException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class MeilisearchVectorStore implements VectorStore
{
    private Client $client;
    private string $host;
    private string $masterKey;

    public function __construct(
        string $host = 'http://localhost:7700',
        string $masterKey = '',
        ?Client $client = null
    ) {
        $this->host = rtrim($host, '/');
        $this->masterKey = $masterKey;
        $this->client = $client ?? new Client(['timeout' => 30]);
    }

    public function store(string $id, array $vector, array $metadata, string $collection): void
    {
        $this->storeBatch([[$id, $vector, $metadata]], $collection);
    }

    public function storeBatch(array $vectors, string $collection): void
    {
        try {
            $documents = [];
            
            foreach ($vectors as [$id, $vector, $metadata]) {
                $documents[] = array_merge($metadata, [
                    'id' => $id,
                    '_vectors' => ['default' => $vector],
                ]);
            }

            $response = $this->client->post("{$this->host}/indexes/{$collection}/documents", [
                'headers' => $this->getHeaders(),
                'json' => $documents,
            ]);

            $this->checkResponse($response);
        } catch (GuzzleException $e) {
            throw new VectorStoreException("Failed to store vectors: {$e->getMessage()}", 0, $e);
        }
    }

    public function search(array $queryVector, string $collection, int $limit = 10, array $filters = []): SearchResult
    {
        $startTime = microtime(true);

        try {
            $searchParams = [
                'vector' => $queryVector,
                'limit' => $limit,
                'showRankingScore' => true,
            ];

            if (!empty($filters)) {
                $searchParams['filter'] = $this->buildFilterString($filters);
            }

            $response = $this->client->post("{$this->host}/indexes/{$collection}/search", [
                'headers' => $this->getHeaders(),
                'json' => $searchParams,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $this->checkResponseData($data);

            $hits = array_map(function ($hit) {
                return new SearchHit(
                    id: (string) $hit['id'],
                    document: $hit,
                    score: $hit['_rankingScore'] ?? 0.0,
                    source: 'semantic'
                );
            }, $data['hits'] ?? []);

            return new SearchResult(
                hits: $hits,
                total: $data['estimatedTotalHits'] ?? count($hits),
                offset: 0,
                limit: $limit,
                processingTime: microtime(true) - $startTime,
                query: ['vector_search' => true]
            );
        } catch (GuzzleException $e) {
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
            $response = $this->client->post("{$this->host}/indexes/{$collection}/documents/delete-batch", [
                'headers' => $this->getHeaders(),
                'json' => $ids,
            ]);

            $this->checkResponse($response);
        } catch (GuzzleException $e) {
            throw new VectorStoreException("Failed to delete vectors: {$e->getMessage()}", 0, $e);
        }
    }

    public function createCollection(string $collection, int $dimension, array $config = []): void
    {
        try {
            // Create index
            $response = $this->client->post("{$this->host}/indexes", [
                'headers' => $this->getHeaders(),
                'json' => ['uid' => $collection, 'primaryKey' => 'id'],
            ]);

            $this->checkResponse($response);

            // Configure embeddings
            $embeddingConfig = array_merge([
                'source' => 'userProvided',
                'dimensions' => $dimension,
            ], $config['embeddings'] ?? []);

            $this->client->patch("{$this->host}/indexes/{$collection}/settings/embedders", [
                'headers' => $this->getHeaders(),
                'json' => ['default' => $embeddingConfig],
            ]);
        } catch (GuzzleException $e) {
            throw new VectorStoreException("Failed to create collection: {$e->getMessage()}", 0, $e);
        }
    }

    public function deleteCollection(string $collection): void
    {
        try {
            $response = $this->client->delete("{$this->host}/indexes/{$collection}", [
                'headers' => $this->getHeaders(),
            ]);

            $this->checkResponse($response);
        } catch (GuzzleException $e) {
            throw new VectorStoreException("Failed to delete collection: {$e->getMessage()}", 0, $e);
        }
    }

    public function collectionExists(string $collection): bool
    {
        try {
            $response = $this->client->get("{$this->host}/indexes/{$collection}", [
                'headers' => $this->getHeaders(),
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException) {
            return false;
        }
    }

    public function getCollectionStats(string $collection): array
    {
        try {
            $response = $this->client->get("{$this->host}/indexes/{$collection}/stats", [
                'headers' => $this->getHeaders(),
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new VectorStoreException("Failed to get collection stats: {$e->getMessage()}", 0, $e);
        }
    }

    public function getName(): string
    {
        return 'meilisearch';
    }

    private function getHeaders(): array
    {
        $headers = ['Content-Type' => 'application/json'];
        
        if (!empty($this->masterKey)) {
            $headers['Authorization'] = "Bearer {$this->masterKey}";
        }

        return $headers;
    }

    private function checkResponse($response): void
    {
        if ($response->getStatusCode() >= 400) {
            $body = $response->getBody()->getContents();
            $error = json_decode($body, true)['message'] ?? $body;
            throw new VectorStoreException("Meilisearch error: {$error}");
        }
    }

    private function checkResponseData(array $data): void
    {
        if (isset($data['message'])) {
            throw new VectorStoreException("Meilisearch error: {$data['message']}");
        }
    }

    private function buildFilterString(array $filters): string
    {
        $conditions = [];
        
        foreach ($filters as $field => $value) {
            if (is_array($value)) {
                $values = implode(', ', array_map(fn($v) => "'{$v}'", $value));
                $conditions[] = "{$field} IN [{$values}]";
            } else {
                $conditions[] = "{$field} = '{$value}'";
            }
        }

        return implode(' AND ', $conditions);
    }
}