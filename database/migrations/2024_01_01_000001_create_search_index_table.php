<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('search_index', function (Blueprint $table) {
            $table->string('document_id');
            $table->string('index_name');
            $table->text('title')->nullable();
            $table->text('content');
            $table->json('metadata');
            $table->timestamps();
            
            $table->primary(['document_id', 'index_name']);
            $table->index('index_name');
        });

        // Add full-text search column after table creation
        // This requires PostgreSQL with full-text search support
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::table('search_index', function (Blueprint $table) {
                $table->getConnection()->statement('ALTER TABLE search_index ADD COLUMN content_vector tsvector');
                $table->getConnection()->statement('CREATE INDEX search_index_content_vector_idx ON search_index USING GIN(content_vector)');
                
                // Create trigger to automatically update content_vector
                $table->getConnection()->statement("
                    CREATE OR REPLACE FUNCTION search_index_update_vector() RETURNS trigger AS $$
                    BEGIN
                        NEW.content_vector := to_tsvector('english', COALESCE(NEW.title, '') || ' ' || COALESCE(NEW.content, ''));
                        RETURN NEW;
                    END;
                    $$ LANGUAGE plpgsql;
                ");
                
                $table->getConnection()->statement("
                    CREATE TRIGGER search_index_vector_trigger 
                    BEFORE INSERT OR UPDATE ON search_index
                    FOR EACH ROW EXECUTE FUNCTION search_index_update_vector();
                ");
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('search_index');
    }
};