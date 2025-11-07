<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Data;

class SearchResult
{
    public function __construct(
        public readonly array $hits,
        public readonly int $total,
        public readonly int $offset,
        public readonly int $limit,
        public readonly float $processingTime,
        public readonly array $facets = [],
        public readonly array $query = [],
        public readonly ?string $nextCursor = null,
    ) {}

    public function getHits(): array
    {
        return $this->hits;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function hasMore(): bool
    {
        return $this->offset + $this->limit < $this->total;
    }

    public function isEmpty(): bool
    {
        return empty($this->hits);
    }

    public function getFirstHit(): ?SearchHit
    {
        return $this->hits[0] ?? null;
    }

    public function pluck(string $field): array
    {
        return array_map(
            fn (SearchHit $hit) => $hit->getDocument()[$field] ?? null,
            $this->hits
        );
    }

    public function toArray(): array
    {
        return [
            'hits' => array_map(fn (SearchHit $hit) => $hit->toArray(), $this->hits),
            'total' => $this->total,
            'offset' => $this->offset,
            'limit' => $this->limit,
            'processing_time' => $this->processingTime,
            'facets' => $this->facets,
            'query' => $this->query,
            'next_cursor' => $this->nextCursor,
        ];
    }
}