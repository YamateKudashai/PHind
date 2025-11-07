# 🔍 Phind Semantic Search

A powerful semantic search package for Laravel applications that combines traditional keyword search with AI-powered vector similarity for better search results and user experience.

## Features

- **Semantic Understanding**: AI-powered embeddings that understand context and meaning
- **Hybrid Search**: Combines keyword matching with semantic similarity
- **Multiple Vector Stores**: Support for Meilisearch, Qdrant, and PostgreSQL pgvector
- **Typo Tolerance**: Smart handling of misspelled queries
- **Faceted Search**: Advanced filtering capabilities
- **Relevance Tuning**: Configurable scoring algorithms
- **Auto Embeddings**: Automatic generation and updating of embeddings
- **Laravel Integration**: Eloquent traits, facades, and service provider

## Installation

```bash
composer require phind/semantic-search
```

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Phind\SemanticSearch\SemanticSearchServiceProvider"
```

## Quick Start

```php
use Phind\SemanticSearch\Facades\SemanticSearch;

// Add searchable trait to your model
class Product extends Model
{
    use \Phind\SemanticSearch\Traits\Searchable;
    
    protected $searchableFields = ['title', 'description'];
}

// Perform semantic search
$results = SemanticSearch::query('comfortable running shoes')
    ->in(Product::class)
    ->withKeywords()
    ->limit(10)
    ->search();
```

## Configuration

The package supports multiple embedding providers and vector databases:

- **Embedding Providers**: OpenAI, Hugging Face, local models
- **Vector Stores**: Meilisearch, Qdrant, PostgreSQL pgvector
- **Caching**: Redis, file, database

## Documentation

- [Installation Guide](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Usage Examples](docs/usage.md)
- [API Reference](docs/api.md)

## Requirements

- PHP 8.1+
- Laravel 10.0+
- One of the supported vector databases

## License

MIT License. See [LICENSE](LICENSE) for details.