<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Commands;

use Illuminate\Console\Command;
use Phind\SemanticSearch\Facades\SemanticSearch;

class CreateSearchIndexCommand extends Command
{
    protected $signature = 'semantic-search:create-index 
                           {index : The name of the search index to create}
                           {--dimension=384 : Vector dimension for embeddings}
                           {--distance=cosine : Distance metric for vector similarity}';

    protected $description = 'Create a new semantic search index';

    public function handle(): int
    {
        $index = $this->argument('index');
        $dimension = (int) $this->option('dimension');
        $distance = $this->option('distance');

        $this->info("Creating search index: {$index}");

        try {
            $config = [
                'dimension' => $dimension,
                'distance' => $distance,
            ];

            SemanticSearch::createIndex($index, $config);
            
            $this->info("✓ Successfully created search index: {$index}");
            
            // Display index statistics
            $stats = SemanticSearch::getIndexStats($index);
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Index Name', $index],
                    ['Vector Dimension', $dimension],
                    ['Distance Metric', $distance],
                    ['Total Documents', $stats['total_documents'] ?? 0],
                ]
            );

            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to create search index: {$e->getMessage()}");
            return 1;
        }
    }
}