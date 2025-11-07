# Example Usage

This document provides practical examples of using the Phind Semantic Search package.

## Basic Setup

### 1. Install and Configure

```bash
composer require phind/semantic-search
php artisan vendor:publish --provider="Phind\SemanticSearch\SemanticSearchServiceProvider"
```

### 2. Environment Configuration

```bash
# .env file
SEMANTIC_SEARCH_ENGINE=hybrid
SEMANTIC_SEARCH_EMBEDDING_PROVIDER=openai
SEMANTIC_SEARCH_VECTOR_STORE=postgresql

# OpenAI Configuration
OPENAI_API_KEY=your_openai_api_key
OPENAI_EMBEDDING_MODEL=text-embedding-3-small

# Database Configuration
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### 3. Run Migrations

```bash
php artisan migrate
```

## Making Models Searchable

### 1. Add the Searchable Trait

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Phind\SemanticSearch\Traits\Searchable;
use Phind\SemanticSearch\Contracts\Searchable as SearchableContract;

class Article extends Model implements SearchableContract
{
    use Searchable;

    protected $fillable = ['title', 'content', 'category', 'author_id', 'published_at'];

    // Define which fields should be searchable
    protected $searchableFields = ['title', 'content', 'summary'];

    // Optional: Define custom search index name
    protected $searchableIndex = 'articles';

    // Optional: Control when to index
    public function shouldBeSearchable(): bool
    {
        return $this->published_at !== null && $this->status === 'published';
    }

    // Optional: Add custom metadata
    public function getSearchableMetadata(): array
    {
        return array_merge(parent::getSearchableMetadata(), [
            'author_name' => $this->author->name ?? '',
            'category_name' => $this->category->name ?? '',
            'tags' => $this->tags->pluck('name')->toArray(),
        ]);
    }
}
```

### 2. Create Search Index

```bash
php artisan semantic-search:create-index articles --dimension=1536
```

### 3. Index Existing Data

```bash
# Index all articles
php artisan semantic-search:index "App\Models\Article"

# Or index all searchable models
php artisan semantic-search:index
```

## Performing Searches

### 1. Basic Search

```php
use Phind\SemanticSearch\Facades\SemanticSearch;
use App\Models\Article;

// Simple search
$results = SemanticSearch::query('machine learning algorithms')
    ->in('articles')
    ->limit(10)
    ->search();

// Using model method
$results = Article::semanticSearch('machine learning algorithms')->search();

// Get the actual models
$articles = Article::hydrate($results);
```

### 2. Advanced Search Options

```php
// Hybrid search with custom weights
$results = SemanticSearch::query('artificial intelligence')
    ->in('articles')
    ->withWeights(0.8, 0.2) // 80% semantic, 20% keyword
    ->limit(20)
    ->search();

// Semantic-only search
$results = SemanticSearch::query('neural networks')
    ->in('articles')
    ->onlySemantic()
    ->search();

// Keyword-only search
$results = SemanticSearch::query('deep learning')
    ->in('articles')
    ->onlyKeywords()
    ->search();
```

### 3. Filtering and Facets

```php
// Add filters
$results = SemanticSearch::query('machine learning')
    ->in('articles')
    ->where('category', 'Technology')
    ->whereIn('author_id', [1, 2, 3])
    ->search();

// With facets
$results = SemanticSearch::query('data science')
    ->in('articles')
    ->withFacets([
        'category' => ['max_count' => 10],
        'author_name' => ['max_count' => 5],
        'published_year' => ['type' => 'date', 'interval' => 'year']
    ])
    ->search();

// Access facets
$facets = $results->getFacets();
foreach ($facets['category'] as $categoryFacet) {
    echo $categoryFacet['value'] . ': ' . $categoryFacet['count'] . ' articles\n';
}
```

### 4. Pagination

```php
// Paginated search
$page = 2;
$perPage = 20;

$results = SemanticSearch::query('artificial intelligence')
    ->in('articles')
    ->limit($perPage)
    ->offset(($page - 1) * $perPage)
    ->search();

echo "Showing results " . ($results->getOffset() + 1) . "-" . 
     min($results->getOffset() + $results->getLimit(), $results->getTotal()) . 
     " of " . $results->getTotal();
```

## Working with Search Results

### 1. Accessing Results

```php
$results = SemanticSearch::query('quantum computing')->in('articles')->search();

// Basic information
echo "Found {$results->getTotal()} results in {$results->getProcessingTime()}s\n";

// Iterate through hits
foreach ($results->getHits() as $hit) {
    echo "Score: {$hit->getScore()}\n";
    echo "Title: {$hit->get('title')}\n";
    echo "Source: {$hit->getSource()}\n"; // 'keyword', 'semantic', or 'hybrid'
    
    // Access highlights
    foreach ($hit->getHighlights() as $highlight) {
        echo "Highlight: {$highlight}\n";
    }
}

// Get first result
$firstHit = $results->getFirstHit();
if ($firstHit) {
    $article = Article::find($firstHit->get('_model_key'));
}
```

### 2. Converting to Models

```php
$results = Article::semanticSearch('machine learning')->search();
$articles = Article::hydrate($results);

foreach ($articles as $article) {
    echo $article->title . " (Score: {$article->_search_score})\n";
    
    // Access search metadata
    echo "Search source: {$article->_search_source}\n";
    print_r($article->_search_highlights);
}
```

## Advanced Features

### 1. Custom Relevance Tuning

```php
// Configure in config/semantic-search.php
'relevance_tuning' => [
    'field_boosts' => [
        'title' => 3.0,        // Boost title matches
        'summary' => 2.0,      // Boost summary matches
        'content' => 1.0,      // Normal content weight
    ],
    
    'category_boosts' => [
        'featured' => 2.5,     // Boost featured articles
        'trending' => 1.8,     // Boost trending articles
    ],
    
    'time_decay' => [
        'enabled' => true,
        'field' => 'published_at',
        'decay_rate' => 0.1,   // Decay rate per month
        'max_age_days' => 365,
    ],
],
```

### 2. Custom Search Pipeline

```php
use Phind\SemanticSearch\Data\SearchQuery;

class CustomSearchService
{
    public function searchArticles(string $query, array $filters = []): Collection
    {
        // Pre-process query
        $processedQuery = $this->preprocessQuery($query);
        
        // Perform search
        $searchQuery = new SearchQuery(
            query: $processedQuery,
            index: 'articles',
            limit: 50,
            filters: $filters,
            semanticWeight: 0.7,
            keywordWeight: 0.3
        );
        
        $results = app(SearchEngine::class)->search($searchQuery);
        
        // Post-process results
        return $this->postProcessResults($results);
    }
    
    private function preprocessQuery(string $query): string
    {
        // Add custom query preprocessing
        return trim(strtolower($query));
    }
    
    private function postProcessResults(SearchResult $results): Collection
    {
        // Convert to models and add custom logic
        return Article::hydrate($results)->filter(function ($article) {
            return $article->isVisible();
        });
    }
}
```

### 3. Real-time Indexing

```php
class Article extends Model implements SearchableContract
{
    use Searchable;
    
    // Auto-indexing is enabled by default via the trait
    // You can disable it per model:
    protected $searchableAutoIndex = false;
    
    // Or queue indexing for better performance
    protected $searchableQueueIndexing = true;
    
    // Manual indexing
    public function updateSearchIndex()
    {
        if ($this->shouldBeSearchable()) {
            $this->searchableIndex();
        } else {
            $this->searchableRemove();
        }
    }
}

// Trigger manual re-indexing
$article->updateSearchIndex();
```

### 4. Multiple Embedding Providers

```php
// Switch providers at runtime
config(['semantic-search.default_embedding_provider' => 'huggingface']);

// Use specific provider
$openaiProvider = app('semantic-search.embedding.openai');
$embedding = $openaiProvider->embed('test text');

$localProvider = app('semantic-search.embedding.local');
$localEmbedding = $localProvider->embed('test text');
```

## Performance Optimization

### 1. Caching Configuration

```php
// config/semantic-search.php
'caching' => [
    'enabled' => true,
    'driver' => 'redis',
    'ttl' => 3600,
    'cache_embeddings' => true,
    'cache_results' => true,
    'cache_facets' => true,
],
```

### 2. Batch Operations

```php
// Index multiple documents efficiently
$documents = [
    ['article_1', ['title' => 'Article 1', 'content' => '...']],
    ['article_2', ['title' => 'Article 2', 'content' => '...']],
    // ... more documents
];

SemanticSearch::indexBatch($documents, 'articles');
```

### 3. Optimization Commands

```bash
# Test embedding performance
php artisan semantic-search:test-embeddings

# Optimize search indexes
php artisan semantic-search:optimize articles --clean-cache --analyze

# Rebuild all indexes
php artisan semantic-search:optimize --rebuild
```

## Monitoring and Debugging

### 1. Enable Debug Mode

```php
// config/semantic-search.php
'debug' => [
    'enabled' => true,
    'log_queries' => true,
    'log_slow_queries' => true,
    'slow_query_threshold' => 1000, // ms
],
```

### 2. Performance Monitoring

```php
$results = SemanticSearch::query('test query')
    ->in('articles')
    ->search();

echo "Processing time: {$results->getProcessingTime()}s\n";
echo "Total results: {$results->getTotal()}\n";

// Access detailed query information
$queryInfo = $results->getQuery();
print_r($queryInfo);
```

This comprehensive example should give you everything you need to implement and use semantic search effectively in your Laravel application!