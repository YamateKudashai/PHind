<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Phind\SemanticSearch\Contracts\Searchable;

class IndexDocumentsCommand extends Command
{
    protected $signature = 'semantic-search:index 
                           {model? : The model class to index}
                           {--batch-size=100 : Number of documents to process in each batch}
                           {--force : Force re-indexing of all documents}';

    protected $description = 'Index documents for semantic search';

    public function handle(): int
    {
        $modelClass = $this->argument('model');
        $batchSize = (int) $this->option('batch-size');
        $force = $this->option('force');

        if ($modelClass) {
            return $this->indexModel($modelClass, $batchSize, $force);
        }

        return $this->indexAllModels($batchSize, $force);
    }

    private function indexModel(string $modelClass, int $batchSize, bool $force): int
    {
        if (!class_exists($modelClass)) {
            $this->error("Model class {$modelClass} does not exist");
            return 1;
        }

        $model = new $modelClass;

        if (!$model instanceof Model) {
            $this->error("Class {$modelClass} is not an Eloquent model");
            return 1;
        }

        if (!method_exists($model, 'makeAllSearchable')) {
            $this->error("Model {$modelClass} does not use the Searchable trait");
            return 1;
        }

        $this->info("Indexing {$modelClass}...");

        try {
            // Create index if it doesn't exist or if forcing
            if ($force) {
                $this->line('Creating search index...');
                $model::createSearchIndex();
            }

            $total = $model::count();
            $this->info("Found {$total} records to index");

            if ($total === 0) {
                $this->warn('No records to index');
                return 0;
            }

            $bar = $this->output->createProgressBar($total);
            $bar->start();

            // Index in batches
            $processed = 0;
            $model::chunk($batchSize, function ($models) use ($bar, &$processed) {
                foreach ($models as $instance) {
                    if ($instance instanceof Searchable && $instance->shouldBeSearchable()) {
                        $instance->searchableIndex();
                    }
                    $bar->advance();
                    $processed++;
                }
            });

            $bar->finish();
            $this->newLine();

            $this->info("✓ Successfully indexed {$processed} records from {$modelClass}");

            // Show statistics
            $stats = $modelClass::searchStats();
            $this->displayStats($stats);

            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to index {$modelClass}: {$e->getMessage()}");
            return 1;
        }
    }

    private function indexAllModels(int $batchSize, bool $force): int
    {
        $this->info('Discovering searchable models...');

        $searchableModels = $this->discoverSearchableModels();

        if (empty($searchableModels)) {
            $this->warn('No searchable models found');
            return 0;
        }

        $this->info('Found ' . count($searchableModels) . ' searchable models');

        foreach ($searchableModels as $modelClass) {
            $this->newLine();
            $result = $this->indexModel($modelClass, $batchSize, $force);
            
            if ($result !== 0) {
                return $result;
            }
        }

        $this->newLine();
        $this->info('✓ All models indexed successfully');

        return 0;
    }

    private function discoverSearchableModels(): array
    {
        $models = [];
        
        // This is a simplified discovery - in a real implementation,
        // you might scan the app/Models directory or maintain a registry
        $modelDirectories = [
            app_path('Models'),
            app_path(),
        ];

        foreach ($modelDirectories as $directory) {
            if (!is_dir($directory)) continue;

            $files = glob("{$directory}/*.php");
            
            foreach ($files as $file) {
                $className = $this->getClassFromFile($file);
                
                if ($className && $this->isSearchableModel($className)) {
                    $models[] = $className;
                }
            }
        }

        return $models;
    }

    private function getClassFromFile(string $file): ?string
    {
        $content = file_get_contents($file);
        
        if (preg_match('/namespace\s+(.+?);/', $content, $namespaceMatches) &&
            preg_match('/class\s+(\w+)/', $content, $classMatches)) {
            return $namespaceMatches[1] . '\\' . $classMatches[1];
        }

        return null;
    }

    private function isSearchableModel(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        try {
            $reflection = new \ReflectionClass($className);
            
            if (!$reflection->isSubclassOf(Model::class)) {
                return false;
            }

            return $reflection->hasMethod('makeAllSearchable');
        } catch (\Exception) {
            return false;
        }
    }

    private function displayStats(array $stats): void
    {
        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Documents', $stats['total_documents'] ?? 'N/A'],
                ['Index Name', $stats['index_name'] ?? 'N/A'],
                ['Oldest Document', $stats['oldest_document'] ?? 'N/A'],
                ['Newest Document', $stats['newest_document'] ?? 'N/A'],
            ]
        );
    }
}