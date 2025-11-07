<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Providers\Embedding;

use Phind\SemanticSearch\Contracts\EmbeddingProvider;
use Phind\SemanticSearch\Exceptions\EmbeddingException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class OpenAIEmbeddingProvider implements EmbeddingProvider
{
    private Client $client;
    private string $apiKey;
    private string $model;
    private int $dimension;
    private int $maxInputLength;

    public function __construct(
        string $apiKey,
        string $model = 'text-embedding-3-small',
        ?Client $client = null
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->client = $client ?? new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'timeout' => 30,
        ]);

        $this->setModelSpecs();
    }

    public function embed(string|array $text): array
    {
        if (!$this->isAvailable()) {
            throw new EmbeddingException('OpenAI embedding provider is not available');
        }

        $inputs = is_array($text) ? $text : [$text];
        
        // Validate input length
        foreach ($inputs as $input) {
            if (strlen($input) > $this->maxInputLength) {
                throw new EmbeddingException(
                    "Input text exceeds maximum length of {$this->maxInputLength} characters"
                );
            }
        }

        try {
            $response = $this->client->post('embeddings', [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'input' => $inputs,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error'])) {
                throw new EmbeddingException("OpenAI API error: {$data['error']['message']}");
            }

            $embeddings = array_map(
                fn ($embedding) => $embedding['embedding'],
                $data['data']
            );

            return is_array($text) ? $embeddings : $embeddings[0];
        } catch (GuzzleException $e) {
            throw new EmbeddingException("Failed to generate embeddings: {$e->getMessage()}", 0, $e);
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
        return "openai:{$this->model}";
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    private function setModelSpecs(): void
    {
        $specs = [
            'text-embedding-3-small' => ['dimension' => 1536, 'maxLength' => 8191],
            'text-embedding-3-large' => ['dimension' => 3072, 'maxLength' => 8191],
            'text-embedding-ada-002' => ['dimension' => 1536, 'maxLength' => 8191],
        ];

        $spec = $specs[$this->model] ?? $specs['text-embedding-3-small'];
        $this->dimension = $spec['dimension'];
        $this->maxInputLength = $spec['maxLength'];
    }
}