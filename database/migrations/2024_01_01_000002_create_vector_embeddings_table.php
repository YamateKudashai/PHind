<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Enable pgvector extension (requires superuser privileges)
        Schema::getConnection()->statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('vector_embeddings', function (Blueprint $table) {
            $table->string('id');
            $table->string('collection');
            $table->json('metadata');
            $table->timestamps();
            
            $table->primary(['id', 'collection']);
            $table->index('collection');
        });

        // Add vector column after table creation
        // Default dimension is 1536 for OpenAI embeddings, adjust as needed
        Schema::table('vector_embeddings', function (Blueprint $table) {
            $table->getConnection()->statement('ALTER TABLE vector_embeddings ADD COLUMN vector vector(1536)');
        });

        // Create HNSW index for efficient vector similarity search
        Schema::getConnection()->statement('CREATE INDEX ON vector_embeddings USING hnsw (vector vector_cosine_ops)');
    }

    public function down()
    {
        Schema::dropIfExists('vector_embeddings');
    }
};