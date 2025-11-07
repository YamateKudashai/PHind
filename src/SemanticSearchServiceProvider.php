<?php

declare(strict_types=1);

namespace Phind\SemanticSearch;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Phind\SemanticSearch\Contracts\EmbeddingProvider;
use Phind\SemanticSearch\Contracts\VectorStore;
use Phind\SemanticSearch\Contracts\SearchEngine;
use Phind\SemanticSearch\Providers\Embedding\OpenAIEmbeddingProvider;
use Phind\SemanticSearch\Providers\Embedding\HuggingFaceEmbeddingProvider;
use Phind\SemanticSearch\Providers\Embedding\LocalEmbeddingProvider;
use Phind\SemanticSearch\Providers\VectorStore\MeilisearchVectorStore;
use Phind\SemanticSearch\Providers\VectorStore\QdrantVectorStore;
use Phind\SemanticSearch\Providers\VectorStore\PostgresVectorStore;
use Phind\SemanticSearch\Engine\HybridSearchEngine;
use Phind\SemanticSearch\Engine\KeywordSearchEngine;
use Phind\SemanticSearch\SemanticSearchManager;

class SemanticSearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/semantic-search.php', 'semantic-search');

        $this->registerEmbeddingProviders();
        $this->registerVectorStores();
        $this->registerSearchEngines();
        $this->registerManager();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishConfig();
            $this->registerCommands();
        }

        $this->registerMacros();
    }

    protected function registerEmbeddingProviders(): void
    {
        $this->app->bind('semantic-search.embedding.openai', function (Application $app) {
            $config = $app['config']['semantic-search.embeddings.openai'];
            
            return new OpenAIEmbeddingProvider(
                apiKey: $config['api_key'],
                model: $config['model'] ?? 'text-embedding-3-small'
            );
        });

        $this->app->bind('semantic-search.embedding.huggingface', function (Application $app) {
            $config = $app['config']['semantic-search.embeddings.huggingface'];
            
            return new HuggingFaceEmbeddingProvider(
                apiKey: $config['api_key'],
                model: $config['model'] ?? 'sentence-transformers/all-MiniLM-L6-v2'
            );
        });

        $this->app->bind('semantic-search.embedding.local', function (Application $app) {
            $config = $app['config']['semantic-search.embeddings.local'];
            
            return new LocalEmbeddingProvider(
                endpoint: $config['endpoint'],
                model: $config['model'] ?? 'all-MiniLM-L6-v2',
                dimension: $config['dimension'] ?? 384,
                maxInputLength: $config['max_input_length'] ?? 512
            );
        });

        $this->app->bind(EmbeddingProvider::class, function (Application $app) {
            $provider = $app['config']['semantic-search.default_embedding_provider'];
            
            return $app->make("semantic-search.embedding.{$provider}");
        });
    }

    protected function registerVectorStores(): void
    {
        $this->app->bind('semantic-search.vector.meilisearch', function (Application $app) {
            $config = $app['config']['semantic-search.vector_stores.meilisearch'];
            
            return new MeilisearchVectorStore(
                host: $config['host'] ?? 'http://localhost:7700',
                masterKey: $config['master_key'] ?? ''
            );
        });

        $this->app->bind('semantic-search.vector.qdrant', function (Application $app) {
            $config = $app['config']['semantic-search.vector_stores.qdrant'];
            
            return new QdrantVectorStore(
                host: $config['host'] ?? 'http://localhost:6333',
                apiKey: $config['api_key'] ?? null
            );
        });

        $this->app->bind('semantic-search.vector.postgresql', function (Application $app) {
            $config = $app['config']['semantic-search.vector_stores.postgresql'];
            
            return new PostgresVectorStore(
                connection: $app['db']->connection($config['connection'] ?? null),
                table: $config['table'] ?? 'vector_embeddings'
            );
        });

        $this->app->bind(VectorStore::class, function (Application $app) {
            $store = $app['config']['semantic-search.default_vector_store'];
            
            return $app->make("semantic-search.vector.{$store}");
        });
    }

    protected function registerSearchEngines(): void
    {
        $this->app->bind('semantic-search.engine.keyword', function (Application $app) {
            $config = $app['config']['semantic-search.keyword_search'];
            
            return new KeywordSearchEngine(
                connection: $app['db']->connection($config['connection'] ?? null),
                table: $config['table'] ?? 'search_index',
                config: $config
            );
        });

        $this->app->bind('semantic-search.engine.hybrid', function (Application $app) {
            return new HybridSearchEngine(
                vectorStore: $app->make(VectorStore::class),
                embeddingProvider: $app->make(EmbeddingProvider::class),
                keywordEngine: $app->make('semantic-search.engine.keyword'),
                config: $app['config']['semantic-search.hybrid_search'] ?? []
            );
        });

        $this->app->bind(SearchEngine::class, function (Application $app) {
            $engine = $app['config']['semantic-search.default_search_engine'];
            
            return $app->make("semantic-search.engine.{$engine}");
        });
    }

    protected function registerManager(): void
    {
        $this->app->singleton(SemanticSearchManager::class, function (Application $app) {
            return new SemanticSearchManager(
                searchEngine: $app->make(SearchEngine::class),
                embeddingProvider: $app->make(EmbeddingProvider::class),
                vectorStore: $app->make(VectorStore::class),
                config: $app['config']['semantic-search']
            );
        });

        $this->app->alias(SemanticSearchManager::class, 'semantic-search');
    }

    protected function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/../config/semantic-search.php' => config_path('semantic-search.php'),
        ], 'semantic-search-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'semantic-search-migrations');
    }

    protected function registerCommands(): void
    {
        $this->commands([
            \Phind\SemanticSearch\Commands\CreateSearchIndexCommand::class,
            \Phind\SemanticSearch\Commands\IndexDocumentsCommand::class,
            \Phind\SemanticSearch\Commands\OptimizeSearchCommand::class,
            \Phind\SemanticSearch\Commands\TestEmbeddingsCommand::class,
        ]);
    }

    protected function registerMacros(): void
    {
        // Add macros for query builder if needed
        // Builder::macro('semanticSearch', function (string $query) {
        //     // Implementation
        // });
    }

    public function provides(): array
    {
        return [
            SemanticSearchManager::class,
            'semantic-search',
            EmbeddingProvider::class,
            VectorStore::class,
            SearchEngine::class,
        ];
    }
}