<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Commands;

use Illuminate\Console\Command;
use Phind\SemanticSearch\Facades\SemanticSearch;

class TestEmbeddingsCommand extends Command
{
    protected $signature = 'semantic-search:test-embeddings 
                           {--text="Hello world, this is a test" : Text to generate embeddings for}
                           {--provider= : Specific embedding provider to test}';

    protected $description = 'Test embedding generation and vector store connectivity';

    public function handle(): int
    {
        $this->info('Testing Semantic Search Components');
        $this->newLine();

        // Test embedding provider
        $this->testEmbeddingProvider();
        
        // Test vector store
        $this->testVectorStore();

        // Test end-to-end embedding generation
        $this->testEmbeddingGeneration();

        return 0;
    }

    private function testEmbeddingProvider(): void
    {
        $this->info('🧠 Testing Embedding Provider...');
        
        try {
            $isAvailable = SemanticSearch::testEmbeddingProvider();
            
            if ($isAvailable) {
                $this->info('✓ Embedding provider is available');
                
                $providers = SemanticSearch::getEmbeddingProviders();
                $this->line('Available providers: ' . implode(', ', $providers));
            } else {
                $this->error('✗ Embedding provider is not available');
            }
        } catch (\Exception $e) {
            $this->error("✗ Embedding provider test failed: {$e->getMessage()}");
        }

        $this->newLine();
    }

    private function testVectorStore(): void
    {
        $this->info('🗄️  Testing Vector Store...');
        
        try {
            $isAvailable = SemanticSearch::testVectorStore();
            
            if ($isAvailable) {
                $this->info('✓ Vector store is available');
                
                $stores = SemanticSearch::getVectorStores();
                $this->line('Available stores: ' . implode(', ', $stores));
            } else {
                $this->error('✗ Vector store is not available');
            }
        } catch (\Exception $e) {
            $this->error("✗ Vector store test failed: {$e->getMessage()}");
        }

        $this->newLine();
    }

    private function testEmbeddingGeneration(): void
    {
        $text = $this->option('text');
        
        $this->info("🔄 Testing embedding generation for: \"{$text}\"");
        
        try {
            $startTime = microtime(true);
            $embedding = SemanticSearch::generateEmbedding($text);
            $processingTime = (microtime(true) - $startTime) * 1000;

            $this->info('✓ Successfully generated embedding');
            
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Text Length', strlen($text) . ' characters'],
                    ['Embedding Dimension', count($embedding)],
                    ['Processing Time', round($processingTime, 2) . ' ms'],
                    ['First 5 Values', implode(', ', array_map(fn($x) => round($x, 4), array_slice($embedding, 0, 5)))],
                    ['Vector Magnitude', round(sqrt(array_sum(array_map(fn($x) => $x * $x, $embedding))), 4)],
                ]
            );

        } catch (\Exception $e) {
            $this->error("✗ Embedding generation failed: {$e->getMessage()}");
        }

        $this->newLine();
    }
}