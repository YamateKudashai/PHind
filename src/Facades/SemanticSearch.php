<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Facades;

use Illuminate\Support\Facades\Facade;
use Phind\SemanticSearch\SemanticSearchManager;

/**
 * @method static \Phind\SemanticSearch\Data\SearchResult search(string $query, string $index, array $options = [])
 * @method static \Phind\SemanticSearch\SemanticSearchManager query(string $query)
 * @method static \Phind\SemanticSearch\SemanticSearchManager in(string $index)
 * @method static void index(string $id, array $document, string $index)
 * @method static void indexBatch(array $documents, string $index)
 * @method static void remove(string $id, string $index)
 * @method static void removeBatch(array $ids, string $index)
 * @method static void createIndex(string $index, array $config = [])
 * @method static void deleteIndex(string $index)
 * @method static array generateEmbedding(string $text)
 * @method static array getIndexStats(string $index)
 *
 * @see \Phind\SemanticSearch\SemanticSearchManager
 */
class SemanticSearch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'semantic-search';
    }
}