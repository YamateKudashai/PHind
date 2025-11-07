<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Providers\VectorStore;

use Phind\SemanticSearch\Contracts\VectorStore;
use Phind\SemanticSearch\Data\SearchHit;
use Phind\SemanticSearch\Data\SearchResult;
use Phind\SemanticSearch\Exceptions\VectorStoreException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class QdrantVectorStore implements VectorStore
{
    private Client $client;
    private string $host;
    private ?string $apiKey;

    public function __construct(
        string $host = 'http://localhost:6333',
        ?string $apiKey = null,
        ?Client $client = null
    ) {
        $this->host = rtrim($host, '/');
        $this->apiKey = $apiKey;
        $this->client = $client ?? new Client(['timeout' => 30]);
    }

    public function store(string $id, array $vector, array $metadata, string $collection): void
    {
        $this->storeBatch([[$id, $vector, $metadata]], $collection);
    }

    public function storeBatch(array $vectors, string $collection): void
    {
        try {
            $points = [];
            
            foreach ($vectors as [$id, $vector, $metadata]) {
                $points[] = [
                    'id' => $id,
                    'vector' => $vector,
                    'payload' => $metadata,
                ];
            }

            $response = $this->client->put("{$this->host}/collections/{$collection}/points", [
                'headers' => $this->getHeaders(),
                'json' => ['points' => $points],
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
                'with_payload' => true,
                'with_vectors' => false,
            ];

            if (!empty($filters)) {
                $searchParams['filter'] = $this->buildFilter($filters);
            }

            $response = $this->client->post("{$this->host}/collections/{$collection}/points/search", [
                'headers' => $this->getHeaders(),
                'json' => $searchParams,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $this->checkResponseData($data);

            $hits = array_map(function ($hit) {
                $document = $hit['payload'] ?? [];
                $document['id'] = $hit['id'];

                return new SearchHit(
                    id: (string) $hit['id'],
                    document: $document,
                    score: $hit['score'] ?? 0.0,
                    source: 'semantic'
                );
            }, $data['result'] ?? []);

            return new SearchResult(
                hits: $hits,
                total: count($hits),
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
            $response = $this->client->post("{$this->host}/collections/{$collection}/points/delete", [
                'headers' => $this->getHeaders(),
                'json' => ['points' => $ids],
            ]);

            $this->checkResponse($response);
        } catch (GuzzleException $e) {
            throw new VectorStoreException("Failed to delete vectors: {$e->getMessage()}", 0, $e);
        }
    }

    public function createCollection(string $collection, int $dimension, array $config = []): void
    {
        try {
            $collectionConfig = array_merge([
                'vectors' => [
                    'size' => $dimension,
                    'distance' => 'Cosine',
                ],
            ], $config);

            $response = $this->client->put("{$this->host}/collections/{$collection}", [
                'headers' => $this->getHeaders(),
                'json' => $collectionConfig,
            ]);

            $this->checkResponse($response);
        } catch (GuzzleException $e) {
            throw new VectorStoreException("Failed to create collection: {$e->getMessage()}", 0, $e);
        }
    }

    public function deleteCollection(string $collection): void
    {
        try {
            $response = $this->client->delete("{$this->host}/collections/{$collection}", [
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
            $response = $this->client->get("{$this->host}/collections/{$collection}", [
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
            $response = $this->client->get("{$this->host}/collections/{$collection}", [
                'headers' => $this->getHeaders(),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['result'] ?? [];
        } catch (GuzzleException $e) {
            throw new VectorStoreException("Failed to get collection stats: {$e->getMessage()}", 0, $e);
        }
    }

    public function getName(): string
    {
        return 'qdrant';
    }

    private function getHeaders(): array
    {
        $headers = ['Content-Type' => 'application/json'];
        
        if ($this->apiKey) {
            $headers['api-key'] = $this->apiKey;
        }

        return $headers;
    }

    private function checkResponse($response): void
    {
        if ($response->getStatusCode() >= 400) {
            $body = $response->getBody()->getContents();
            $error = json_decode($body, true)['status']['error'] ?? $body;
            throw new VectorStoreException("Qdrant error: {$error}");
        }
    }

    private function checkResponseData(array $data): void
    {
        if (isset($data['status']['error'])) {
            throw new VectorStoreException("Qdrant error: {$data['status']['error']}");
        }
    }

    private function buildFilter(array $filters): array
    {
        $must = [];
        
        foreach ($filters as $field => $value) {
            if (is_array($value)) {
                $must[] = [
                    'key' => $field,
                    'match' => ['any' => $value],
                ];
            } else {
                $must[] = [
                    'key' => $field,
                    'match' => ['value' => $value],
                ];
            }
        }

        return ['must' => $must];
    }
}