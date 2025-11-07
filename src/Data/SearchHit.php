<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Data;

class SearchHit
{
    public function __construct(
        public readonly string $id,
        public readonly array $document,
        public readonly float $score,
        public readonly array $highlights = [],
        public readonly array $metadata = [],
        public readonly string $source = 'hybrid', // 'keyword', 'semantic', 'hybrid'
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getDocument(): array
    {
        return $this->document;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    public function getHighlights(): array
    {
        return $this->highlights;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function get(string $field, mixed $default = null): mixed
    {
        return $this->document[$field] ?? $default;
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->document);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'document' => $this->document,
            'score' => $this->score,
            'highlights' => $this->highlights,
            'metadata' => $this->metadata,
            'source' => $this->source,
        ];
    }
}