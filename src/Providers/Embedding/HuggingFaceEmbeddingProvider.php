<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Providers\Embedding;

use Phind\SemanticSearch\Contracts\EmbeddingProvider;
use Phind\SemanticSearch\Exceptions\EmbeddingException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class HuggingFaceEmbeddingProvider implements EmbeddingProvider
{
    private Client $client;
    private string $apiKey;
    private string $model;
    private int $dimension;
    private int $maxInputLength;

    public function __construct(
        string $apiKey,
        string $model = 'sentence-transformers/all-MiniLM-L6-v2',
        ?Client $client = null
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->client = $client ?? new Client([
            'base_uri' => 'https://api-inference.huggingface.co/',
            'timeout' => 30,
        ]);

        $this->setModelSpecs();
    }

    public function embed(string|array $text): array
    {
        if (!$this->isAvailable()) {
            throw new EmbeddingException('Hugging Face embedding provider is not available');
        }

        $inputs = is_array($text) ? $text : [$text];
        $embeddings = [];

        foreach ($inputs as $input) {
            if (strlen($input) > $this->maxInputLength) {
                throw new EmbeddingException(
                    "Input text exceeds maximum length of {$this->maxInputLength} characters"
                );
            }

            try {
                $response = $this->client->post("pipeline/feature-extraction/{$this->model}", [
                    'headers' => [
                        'Authorization' => "Bearer {$this->apiKey}",
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'inputs' => $input,
                        'options' => [
                            'wait_for_model' => true,
                        ],
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);

                if (isset($data['error'])) {
                    throw new EmbeddingException("Hugging Face API error: {$data['error']}");
                }

                // Handle different response formats
                $embedding = $this->normalizeEmbedding($data);
                $embeddings[] = $embedding;
            } catch (GuzzleException $e) {
                throw new EmbeddingException("Failed to generate embedding: {$e->getMessage()}", 0, $e);
            }
        }

        return is_array($text) ? $embeddings : $embeddings[0];
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
        return "huggingface:{$this->model}";
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    private function normalizeEmbedding(array $data): array
    {
        // Handle different response structures from Hugging Face models
        if (is_array($data[0]) && is_array($data[0][0])) {
            // Sentence transformer models return [[[embedding]]]
            return $data[0][0];
        }

        if (is_array($data[0]) && is_numeric($data[0][0])) {
            // Some models return [[embedding]]
            return $data[0];
        }

        // Direct embedding array
        return $data;
    }

    private function setModelSpecs(): void
    {
        $specs = [
            'sentence-transformers/all-MiniLM-L6-v2' => ['dimension' => 384, 'maxLength' => 512],
            'sentence-transformers/all-mpnet-base-v2' => ['dimension' => 768, 'maxLength' => 512],
            'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2' => ['dimension' => 384, 'maxLength' => 512],
            'BAAI/bge-small-en-v1.5' => ['dimension' => 384, 'maxLength' => 512],
            'BAAI/bge-base-en-v1.5' => ['dimension' => 768, 'maxLength' => 512],
            'BAAI/bge-large-en-v1.5' => ['dimension' => 1024, 'maxLength' => 512],
        ];

        $spec = $specs[$this->model] ?? ['dimension' => 384, 'maxLength' => 512];
        $this->dimension = $spec['dimension'];
        $this->maxInputLength = $spec['maxLength'];
    }
}