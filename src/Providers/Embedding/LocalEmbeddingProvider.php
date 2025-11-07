<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Providers\Embedding;

use Phind\SemanticSearch\Contracts\EmbeddingProvider;
use Phind\SemanticSearch\Exceptions\EmbeddingException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class LocalEmbeddingProvider implements EmbeddingProvider
{
    private Client $client;
    private string $endpoint;
    private string $model;
    private int $dimension;
    private int $maxInputLength;

    public function __construct(
        string $endpoint,
        string $model = 'all-MiniLM-L6-v2',
        int $dimension = 384,
        int $maxInputLength = 512,
        ?Client $client = null
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->model = $model;
        $this->dimension = $dimension;
        $this->maxInputLength = $maxInputLength;
        $this->client = $client ?? new Client(['timeout' => 30]);
    }

    public function embed(string|array $text): array
    {
        if (!$this->isAvailable()) {
            throw new EmbeddingException('Local embedding provider is not available');
        }

        $inputs = is_array($text) ? $text : [$text];

        foreach ($inputs as $input) {
            if (strlen($input) > $this->maxInputLength) {
                throw new EmbeddingException(
                    "Input text exceeds maximum length of {$this->maxInputLength} characters"
                );
            }
        }

        try {
            $response = $this->client->post("{$this->endpoint}/embed", [
                'json' => [
                    'model' => $this->model,
                    'input' => $inputs,
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error'])) {
                throw new EmbeddingException("Local API error: {$data['error']}");
            }

            $embeddings = $data['embeddings'] ?? $data['data'] ?? [];

            if (empty($embeddings)) {
                throw new EmbeddingException('No embeddings returned from local provider');
            }

            return is_array($text) ? $embeddings : $embeddings[0];
        } catch (GuzzleException $e) {
            throw new EmbeddingException("Failed to connect to local embedding service: {$e->getMessage()}", 0, $e);
        }
    }

    public function getDimension(): int
    {
        return $this->dimension;
    }

    public function getMaxInputLength(): int
    {
        return $this->maxInputLength;
    }

    public function getName(): string
    {
        return "local:{$this->model}";
    }

    public function isAvailable(): bool
    {
        try {
            $response = $this->client->get("{$this->endpoint}/health", ['timeout' => 5]);
            return $response->getStatusCode() === 200;
        } catch (GuzzleException) {
            return false;
        }
    }

    /**
     * Test the connection and get model information.
     */
    public function getModelInfo(): array
    {
        try {
            $response = $this->client->get("{$this->endpoint}/models/{$this->model}");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new EmbeddingException("Failed to get model info: {$e->getMessage()}", 0, $e);
        }
    }
}