<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Traits;

use Phind\SemanticSearch\Contracts\Searchable as SearchableContract;
use Phind\SemanticSearch\Facades\SemanticSearch;
use Illuminate\Database\Eloquent\Model;

trait Searchable
{
    /**
     * Boot the searchable trait.
     */
    protected static function bootSearchable(): void
    {
        if (config('semantic-search.indexing.auto_index', true)) {
            static::created(function (Model $model) {
                if ($model instanceof SearchableContract && $model->shouldBeSearchable()) {
                    $model->searchableIndex();
                }
            });

            static::updated(function (Model $model) {
                if ($model instanceof SearchableContract && $model->shouldBeSearchable()) {
                    $model->searchableIndex();
                } else {
                    $model->searchableRemove();
                }
            });

            static::deleted(function (Model $model) {
                if ($model instanceof SearchableContract) {
                    $model->searchableRemove();
                }
            });
        }
    }

    /**
     * Get the fields that should be indexed for search.
     */
    public function getSearchableFields(): array
    {
        return $this->searchableFields ?? ['title', 'content', 'description'];
    }

    /**
     * Get the content to be embedded and indexed.
     */
    public function getSearchableContent(): string
    {
        $content = [];
        
        foreach ($this->getSearchableFields() as $field) {
            if (isset($this->{$field}) && !empty($this->{$field})) {
                $content[] = $this->{$field};
            }
        }

        return implode(' ', $content);
    }

    /**
     * Get additional metadata for the search index.
     */
    public function getSearchableMetadata(): array
    {
        $metadata = $this->toArray();
        
        // Add model class information
        $metadata['_model_class'] = get_class($this);
        $metadata['_model_key'] = $this->getKey();
        
        // Add timestamps
        if ($this->usesTimestamps()) {
            $metadata['created_at'] = $this->created_at?->toISOString();
            $metadata['updated_at'] = $this->updated_at?->toISOString();
        }

        return $metadata;
    }

    /**
     * Get the unique identifier for this searchable item.
     */
    public function getSearchableId(): string
    {
        return get_class($this) . ':' . $this->getKey();
    }

    /**
     * Get the search index name for this model.
     */
    public function getSearchableIndex(): string
    {
        return $this->searchableIndex ?? 
               strtolower(class_basename(get_class($this))) . 's';
    }

    /**
     * Determine if the model should be indexed.
     */
    public function shouldBeSearchable(): bool
    {
        if (method_exists($this, 'isSearchable')) {
            return $this->isSearchable();
        }

        return !empty($this->getSearchableContent());
    }

    /**
     * Index this model for search.
     */
    public function searchableIndex(): void
    {
        if (config('semantic-search.indexing.queue_indexing', false)) {
            dispatch(function () {
                $this->performSearchableIndex();
            })->onConnection(config('semantic-search.indexing.queue_connection', 'default'))
              ->onQueue(config('semantic-search.indexing.queue_name', 'default'));
        } else {
            $this->performSearchableIndex();
        }
    }

    /**
     * Remove this model from search index.
     */
    public function searchableRemove(): void
    {
        SemanticSearch::remove(
            $this->getSearchableId(),
            $this->getSearchableIndex()
        );
    }

    /**
     * Search this model type.
     */
    public static function semanticSearch(string $query): \Phind\SemanticSearch\SemanticSearchManager
    {
        $model = new static;
        
        return SemanticSearch::query($query)
                           ->in($model->getSearchableIndex())
                           ->where('_model_class', get_class($model));
    }

    /**
     * Make all instances of this model searchable.
     */
    public static function makeAllSearchable(int $batchSize = null): void
    {
        $batchSize = $batchSize ?? config('semantic-search.indexing.batch_size', 100);
        $model = new static;
        $index = $model->getSearchableIndex();

        static::chunk($batchSize, function ($models) use ($index) {
            $documents = [];

            foreach ($models as $model) {
                if ($model->shouldBeSearchable()) {
                    $documents[] = [
                        $model->getSearchableId(),
                        $model->getSearchableMetadata()
                    ];
                }
            }

            if (!empty($documents)) {
                SemanticSearch::indexBatch($documents, $index);
            }
        });
    }

    /**
     * Remove all instances of this model from search index.
     */
    public static function removeAllFromSearch(): void
    {
        $model = new static;
        SemanticSearch::deleteIndex($model->getSearchableIndex());
    }

    /**
     * Get search statistics for this model.
     */
    public static function searchStats(): array
    {
        $model = new static;
        return SemanticSearch::getIndexStats($model->getSearchableIndex());
    }

    /**
     * Create search index for this model.
     */
    public static function createSearchIndex(array $config = []): void
    {
        $model = new static;
        SemanticSearch::createIndex($model->getSearchableIndex(), $config);
    }

    /**
     * Get searchable models from search results.
     */
    public function newFromSearchHit(array $hit): ?Model
    {
        $modelClass = $hit['_model_class'] ?? get_class($this);
        $modelKey = $hit['_model_key'] ?? null;

        if (!$modelKey || !class_exists($modelClass)) {
            return null;
        }

        try {
            return $modelClass::find($modelKey);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Convert search results to model collection.
     */
    public static function hydrate(\Phind\SemanticSearch\Data\SearchResult $searchResult): \Illuminate\Database\Eloquent\Collection
    {
        $model = new static;
        $models = new \Illuminate\Database\Eloquent\Collection();

        foreach ($searchResult->getHits() as $hit) {
            $instance = $model->newFromSearchHit($hit->getDocument());
            if ($instance) {
                // Add search metadata to model
                $instance->setAttribute('_search_score', $hit->getScore());
                $instance->setAttribute('_search_highlights', $hit->getHighlights());
                $instance->setAttribute('_search_source', $hit->getSource());
                
                $models->push($instance);
            }
        }

        return $models;
    }

    /**
     * Perform the actual indexing.
     */
    protected function performSearchableIndex(): void
    {
        SemanticSearch::index(
            $this->getSearchableId(),
            $this->getSearchableMetadata(),
            $this->getSearchableIndex()
        );
    }

    /**
     * Get searchable configuration for this model.
     */
    protected function getSearchableConfig(): array
    {
        return [
            'fields' => $this->getSearchableFields(),
            'index' => $this->getSearchableIndex(),
            'auto_index' => $this->searchableAutoIndex ?? true,
            'queue_indexing' => $this->searchableQueueIndexing ?? false,
        ];
    }
}