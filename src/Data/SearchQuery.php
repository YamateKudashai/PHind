<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Data;

class SearchQuery
{
    public function __construct(
        public readonly string $query,
        public readonly string $index,
        public readonly int $limit = 10,
        public readonly int $offset = 0,
        public readonly array $filters = [],
        public readonly array $facets = [],
        public readonly bool $includeKeywords = true,
        public readonly bool $includeSemantic = true,
        public readonly float $semanticWeight = 0.7,
        public readonly float $keywordWeight = 0.3,
        public readonly bool $typoTolerant = true,
        public readonly array $sortBy = [],
        public readonly array $highlightFields = [],
        public readonly float $minScore = 0.0,
        public readonly ?string $searchAfter = null,
    ) {}

    public function withLimit(int $limit): self
    {
        return new self(
            $this->query,
            $this->index,
            $limit,
            $this->offset,
            $this->filters,
            $this->facets,
            $this->includeKeywords,
            $this->includeSemantic,
            $this->semanticWeight,
            $this->keywordWeight,
            $this->typoTolerant,
            $this->sortBy,
            $this->highlightFields,
            $this->minScore,
            $this->searchAfter,
        );
    }

    public function withOffset(int $offset): self
    {
        return new self(
            $this->query,
            $this->index,
            $this->limit,
            $offset,
            $this->filters,
            $this->facets,
            $this->includeKeywords,
            $this->includeSemantic,
            $this->semanticWeight,
            $this->keywordWeight,
            $this->typoTolerant,
            $this->sortBy,
            $this->highlightFields,
            $this->minScore,
            $this->searchAfter,
        );
    }

    public function withFilters(array $filters): self
    {
        return new self(
            $this->query,
            $this->index,
            $this->limit,
            $this->offset,
            array_merge($this->filters, $filters),
            $this->facets,
            $this->includeKeywords,
            $this->includeSemantic,
            $this->semanticWeight,
            $this->keywordWeight,
            $this->typoTolerant,
            $this->sortBy,
            $this->highlightFields,
            $this->minScore,
            $this->searchAfter,
        );
    }

    public function withWeights(float $semanticWeight, float $keywordWeight): self
    {
        return new self(
            $this->query,
            $this->index,
            $this->limit,
            $this->offset,
            $this->filters,
            $this->facets,
            $this->includeKeywords,
            $this->includeSemantic,
            $semanticWeight,
            $keywordWeight,
            $this->typoTolerant,
            $this->sortBy,
            $this->highlightFields,
            $this->minScore,
            $this->searchAfter,
        );
    }

    public function onlyKeywords(): self
    {
        return new self(
            $this->query,
            $this->index,
            $this->limit,
            $this->offset,
            $this->filters,
            $this->facets,
            true,
            false,
            0.0,
            1.0,
            $this->typoTolerant,
            $this->sortBy,
            $this->highlightFields,
            $this->minScore,
            $this->searchAfter,
        );
    }

    public function onlySemantic(): self
    {
        return new self(
            $this->query,
            $this->index,
            $this->limit,
            $this->offset,
            $this->filters,
            $this->facets,
            false,
            true,
            1.0,
            0.0,
            $this->typoTolerant,
            $this->sortBy,
            $this->highlightFields,
            $this->minScore,
            $this->searchAfter,
        );
    }
}