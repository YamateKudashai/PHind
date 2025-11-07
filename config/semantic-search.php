<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Search Engine
    |--------------------------------------------------------------------------
    |
    | This option controls which search engine is used by default.
    | Options: "keyword", "hybrid"
    |
    */
    'default_search_engine' => env('SEMANTIC_SEARCH_ENGINE', 'hybrid'),

    /*
    |--------------------------------------------------------------------------
    | Default Embedding Provider
    |--------------------------------------------------------------------------
    |
    | This option controls which embedding provider is used by default.
    | Options: "openai", "huggingface", "local"
    |
    */
    'default_embedding_provider' => env('SEMANTIC_SEARCH_EMBEDDING_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Default Vector Store
    |--------------------------------------------------------------------------
    |
    | This option controls which vector store is used by default.
    | Options: "meilisearch", "qdrant", "postgresql"
    |
    */
    'default_vector_store' => env('SEMANTIC_SEARCH_VECTOR_STORE', 'postgresql'),

    /*
    |--------------------------------------------------------------------------
    | Embedding Providers
    |--------------------------------------------------------------------------
    |
    | Configuration for different embedding providers.
    |
    */
    'embeddings' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        ],

        'huggingface' => [
            'api_key' => env('HUGGINGFACE_API_KEY'),
            'model' => env('HUGGINGFACE_EMBEDDING_MODEL', 'sentence-transformers/all-MiniLM-L6-v2'),
        ],

        'local' => [
            'endpoint' => env('LOCAL_EMBEDDING_ENDPOINT', 'http://localhost:8080'),
            'model' => env('LOCAL_EMBEDDING_MODEL', 'all-MiniLM-L6-v2'),
            'dimension' => env('LOCAL_EMBEDDING_DIMENSION', 384),
            'max_input_length' => env('LOCAL_EMBEDDING_MAX_INPUT', 512),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vector Stores
    |--------------------------------------------------------------------------
    |
    | Configuration for different vector stores.
    |
    */
    'vector_stores' => [
        'meilisearch' => [
            'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
            'master_key' => env('MEILISEARCH_MASTER_KEY', ''),
        ],

        'qdrant' => [
            'host' => env('QDRANT_HOST', 'http://localhost:6333'),
            'api_key' => env('QDRANT_API_KEY'),
        ],

        'postgresql' => [
            'connection' => env('SEMANTIC_SEARCH_DB_CONNECTION'),
            'table' => env('SEMANTIC_SEARCH_VECTOR_TABLE', 'vector_embeddings'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Keyword Search Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for keyword-based search.
    |
    */
    'keyword_search' => [
        'connection' => env('SEMANTIC_SEARCH_DB_CONNECTION'),
        'table' => env('SEMANTIC_SEARCH_KEYWORD_TABLE', 'search_index'),
        'searchable_fields' => ['title', 'content', 'description'],
        'enable_full_text' => true,
        'enable_typo_tolerance' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Hybrid Search Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for hybrid semantic + keyword search.
    |
    */
    'hybrid_search' => [
        'semantic_weight' => 0.7,
        'keyword_weight' => 0.3,
        'searchable_fields' => ['title', 'content', 'description'],
        'cache_embeddings' => true,
        'cache_ttl' => 3600, // 1 hour
    ],

    /*
    |--------------------------------------------------------------------------
    | Typo Tolerance Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for typo tolerance features.
    |
    */
    'typo_tolerance' => [
        'enabled' => env('SEMANTIC_SEARCH_TYPO_TOLERANCE', true),
        'min_word_length' => 3,
        'max_edit_distance' => 2,
        'max_alternatives' => 10,
        'similarity_threshold' => 0.7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Faceted Search Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for faceted search features.
    |
    */
    'faceted_search' => [
        'enabled' => env('SEMANTIC_SEARCH_FACETED', true),
        'max_facets_per_field' => 10,
        'max_suggestions' => 5,
        'auto_suggest_fields' => ['category', 'tags', 'author', 'type'],
        'min_facet_count' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Relevance Tuning Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for relevance boosting and tuning.
    |
    */
    'relevance_tuning' => [
        'enabled' => env('SEMANTIC_SEARCH_RELEVANCE_TUNING', true),
        'max_boost' => 5.0,
        'min_boost' => 0.1,
        'default_time_decay' => 0.1,
        'default_popularity_weight' => 0.3,
        
        'field_boosts' => [
            'title' => 2.0,
            'description' => 1.5,
            'content' => 1.0,
        ],

        'category_boosts' => [
            // 'featured' => 2.0,
            // 'premium' => 1.5,
        ],

        'time_decay' => [
            'enabled' => true,
            'field' => 'created_at',
            'decay_rate' => 0.1,
            'max_age_days' => 365,
            'min_score' => 0.1,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for search result and embedding caching.
    |
    */
    'caching' => [
        'enabled' => env('SEMANTIC_SEARCH_CACHING', true),
        'driver' => env('SEMANTIC_SEARCH_CACHE_DRIVER', 'redis'),
        'ttl' => env('SEMANTIC_SEARCH_CACHE_TTL', 3600),
        'prefix' => env('SEMANTIC_SEARCH_CACHE_PREFIX', 'semantic_search'),
        
        'cache_embeddings' => true,
        'cache_results' => true,
        'cache_facets' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Indexing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for automatic indexing and batch processing.
    |
    */
    'indexing' => [
        'auto_index' => env('SEMANTIC_SEARCH_AUTO_INDEX', true),
        'batch_size' => env('SEMANTIC_SEARCH_BATCH_SIZE', 100),
        'queue_indexing' => env('SEMANTIC_SEARCH_QUEUE_INDEXING', false),
        'queue_connection' => env('SEMANTIC_SEARCH_QUEUE_CONNECTION', 'default'),
        'queue_name' => env('SEMANTIC_SEARCH_QUEUE_NAME', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Limits
    |--------------------------------------------------------------------------
    |
    | Configuration for search limits and pagination.
    |
    */
    'limits' => [
        'max_results' => env('SEMANTIC_SEARCH_MAX_RESULTS', 1000),
        'default_limit' => env('SEMANTIC_SEARCH_DEFAULT_LIMIT', 20),
        'max_query_length' => env('SEMANTIC_SEARCH_MAX_QUERY_LENGTH', 1000),
        'min_query_length' => env('SEMANTIC_SEARCH_MIN_QUERY_LENGTH', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug and Logging
    |--------------------------------------------------------------------------
    |
    | Configuration for debugging and performance monitoring.
    |
    */
    'debug' => [
        'enabled' => env('SEMANTIC_SEARCH_DEBUG', false),
        'log_queries' => env('SEMANTIC_SEARCH_LOG_QUERIES', false),
        'log_slow_queries' => env('SEMANTIC_SEARCH_LOG_SLOW_QUERIES', true),
        'slow_query_threshold' => env('SEMANTIC_SEARCH_SLOW_QUERY_THRESHOLD', 1000), // ms
    ],
];