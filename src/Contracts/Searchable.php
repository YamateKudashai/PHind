<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Contracts;

interface Searchable
{
    /**
     * Get the fields that should be indexed for search.
     */
    public function getSearchableFields(): array;

    /**
     * Get the content to be embedded and indexed.
     */
    public function getSearchableContent(): string;

    /**
     * Get additional metadata for the search index.
     */
    public function getSearchableMetadata(): array;

    /**
     * Get the unique identifier for this searchable item.
     */
    public function getSearchableId(): string;

    /**
     * Get the search index name for this model.
     */
    public function getSearchableIndex(): string;

    /**
     * Determine if the model should be indexed.
     */
    public function shouldBeSearchable(): bool;
}