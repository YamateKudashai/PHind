<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Commands;

use Illuminate\Console\Command;
use Phind\SemanticSearch\Facades\SemanticSearch;

class OptimizeSearchCommand extends Command
{
    protected $signature = 'semantic-search:optimize 
                           {index? : Specific index to optimize}
                           {--clean-cache : Clear search caches}
                           {--rebuild : Rebuild search indexes}
                           {--analyze : Analyze search performance}';

    protected $description = 'Optimize semantic search performance and indexes';

    public function handle(): int
    {
        $index = $this->argument('index');
        $cleanCache = $this->option('clean-cache');
        $rebuild = $this->option('rebuild');
        $analyze = $this->option('analyze');

        $this->info('🚀 Optimizing Semantic Search');
        $this->newLine();

        if ($cleanCache) {
            $this->cleanCaches();
        }

        if ($analyze) {
            $this->analyzePerformance($index);
        }

        if ($rebuild) {
            $this->rebuildIndexes($index);
        }

        $this->info('✓ Optimization complete');
        return 0;
    }

    private function cleanCaches(): void
    {
        $this->info('🧹 Cleaning search caches...');

        try {
            // Clear Laravel cache
            $this->call('cache:clear');
            
            $this->info('✓ Search caches cleared');
        } catch (\Exception $e) {
            $this->error("Failed to clear caches: {$e->getMessage()}");
        }

        $this->newLine();
    }

    private function analyzePerformance(?string $index): void
    {
        $this->info('📊 Analyzing search performance...');

        try {
            // Test embedding generation speed
            $this->testEmbeddingSpeed();
            
            // Test search speed if index specified
            if ($index) {
                $this->testSearchSpeed($index);
            }

            // Display index statistics
            $this->displayIndexStatistics($index);
        } catch (\Exception $e) {
            $this->error("Performance analysis failed: {$e->getMessage()}");
        }

        $this->newLine();
    }

    private function rebuildIndexes(?string $index): void
    {
        if ($index) {
            $this->info("🔄 Rebuilding index: {$index}");
            $this->rebuildSingleIndex($index);
        } else {
            $this->info('🔄 Rebuilding all indexes...');
            $this->rebuildAllIndexes();
        }

        $this->newLine();
    }

    private function testEmbeddingSpeed(): void
    {
        $testTexts = [
            'Short text',
            'Medium length text that contains more words and should take a bit longer to process',
            'Very long text that simulates a real document with multiple sentences. This text is designed to test the performance of the embedding generation with larger inputs. It contains various words and phrases to ensure comprehensive testing of the embedding provider performance under different conditions.'
        ];

        $results = [];

        foreach ($testTexts as $i => $text) {
            $startTime = microtime(true);
            SemanticSearch::generateEmbedding($text);
            $endTime = microtime(true);

            $results[] = [
                'Test ' . ($i + 1),
                strlen($text) . ' chars',
                round(($endTime - $startTime) * 1000, 2) . ' ms'
            ];
        }

        $this->table(['Test', 'Input Size', 'Processing Time'], $results);
    }

    private function testSearchSpeed(string $index): void
    {
        $testQueries = [
            'test query',
            'semantic search performance',
            'machine learning artificial intelligence'
        ];

        $results = [];

        foreach ($testQueries as $i => $query) {
            try {
                $startTime = microtime(true);
                $result = SemanticSearch::query($query)->in($index)->limit(10)->search();
                $endTime = microtime(true);

                $results[] = [
                    'Query ' . ($i + 1),
                    $query,
                    $result->getTotal() . ' results',
                    round(($endTime - $startTime) * 1000, 2) . ' ms'
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'Query ' . ($i + 1),
                    $query,
                    'Error',
                    $e->getMessage()
                ];
            }
        }

        $this->table(['Test', 'Query', 'Results', 'Time'], $results);
    }

    private function displayIndexStatistics(?string $index): void
    {
        if (!$index) {
            return;
        }

        try {
            $stats = SemanticSearch::getIndexStats($index);

            $this->info("📈 Index Statistics: {$index}");
            
            if (isset($stats['keyword_stats'])) {
                $this->line('Keyword Index:');
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Total Documents', $stats['keyword_stats']['total_documents'] ?? 'N/A'],
                        ['Oldest Document', $stats['keyword_stats']['oldest_document'] ?? 'N/A'],
                        ['Newest Document', $stats['keyword_stats']['newest_document'] ?? 'N/A'],
                    ]
                );
            }

            if (isset($stats['vector_stats'])) {
                $this->line('Vector Index:');
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Total Vectors', $stats['vector_stats']['total_vectors'] ?? 'N/A'],
                        ['Oldest Vector', $stats['vector_stats']['oldest_vector'] ?? 'N/A'],
                        ['Newest Vector', $stats['vector_stats']['newest_vector'] ?? 'N/A'],
                    ]
                );
            }
        } catch (\Exception $e) {
            $this->error("Failed to get index statistics: {$e->getMessage()}");
        }
    }

    private function rebuildSingleIndex(string $index): void
    {
        try {
            // This would depend on your specific implementation
            // You might want to call model indexing commands
            $this->call('semantic-search:index', [
                '--force' => true,
                '--batch-size' => 100
            ]);

            $this->info("✓ Index {$index} rebuilt successfully");
        } catch (\Exception $e) {
            $this->error("Failed to rebuild index {$index}: {$e->getMessage()}");
        }
    }

    private function rebuildAllIndexes(): void
    {
        try {
            $this->call('semantic-search:index', [
                '--force' => true,
                '--batch-size' => 100
            ]);

            $this->info('✓ All indexes rebuilt successfully');
        } catch (\Exception $e) {
            $this->error("Failed to rebuild indexes: {$e->getMessage()}");
        }
    }
}