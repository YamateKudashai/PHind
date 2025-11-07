<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Features;

use Phind\SemanticSearch\Data\SearchQuery;
use Phind\SemanticSearch\Data\SearchResult;

class FacetedSearch
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Process facets from search results.
     */
    public function processFacets(SearchResult $result, array $facetFields): array
    {
        $facets = [];

        foreach ($facetFields as $field => $config) {
            $fieldName = is_numeric($field) ? $config : $field;
            $fieldConfig = is_array($config) ? $config : [];

            $facets[$fieldName] = $this->calculateFieldFacets(
                $result->getHits(),
                $fieldName,
                $fieldConfig
            );
        }

        return $facets;
    }

    /**
     * Apply facet filters to a search query.
     */
    public function applyFacetFilters(SearchQuery $query, array $facetFilters): SearchQuery
    {
        $filters = $query->filters;

        foreach ($facetFilters as $field => $values) {
            if (is_array($values) && !empty($values)) {
                $filters[$field] = $values;
            } elseif (!empty($values)) {
                $filters[$field] = [$values];
            }
        }

        return $query->withFilters($filters);
    }

    /**
     * Generate facet suggestions based on query and existing facets.
     */
    public function generateFacetSuggestions(string $query, array $existingFacets): array
    {
        $suggestions = [];

        foreach ($this->config['auto_suggest_fields'] as $field) {
            if (!isset($existingFacets[$field])) continue;

            $fieldSuggestions = $this->generateFieldSuggestions(
                $query,
                $existingFacets[$field],
                $field
            );

            if (!empty($fieldSuggestions)) {
                $suggestions[$field] = $fieldSuggestions;
            }
        }

        return $suggestions;
    }

    /**
     * Create range facets for numeric fields.
     */
    public function createRangeFacets(array $hits, string $field, array $ranges): array
    {
        $rangeCounts = array_fill_keys(array_keys($ranges), 0);

        foreach ($hits as $hit) {
            $value = $hit->get($field);
            if (!is_numeric($value)) continue;

            foreach ($ranges as $rangeName => $range) {
                if ($this->valueInRange($value, $range)) {
                    $rangeCounts[$rangeName]++;
                }
            }
        }

        return array_filter($rangeCounts, fn($count) => $count > 0);
    }

    /**
     * Create hierarchical facets for nested categorization.
     */
    public function createHierarchicalFacets(array $hits, string $field, string $separator = '/'): array
    {
        $hierarchy = [];

        foreach ($hits as $hit) {
            $value = $hit->get($field);
            if (empty($value)) continue;

            $parts = explode($separator, $value);
            $this->buildHierarchy($hierarchy, $parts);
        }

        return $this->formatHierarchy($hierarchy);
    }

    /**
     * Calculate relevance-based facet ordering.
     */
    public function orderFacetsByRelevance(array $facets, string $query): array
    {
        foreach ($facets as $field => $values) {
            if (!is_array($values)) continue;

            uasort($values, function ($a, $b) use ($query) {
                // Order by combination of count and relevance to query
                $relevanceA = $this->calculateRelevance($query, $a['value'] ?? '');
                $relevanceB = $this->calculateRelevance($query, $b['value'] ?? '');
                
                $scoreA = ($a['count'] ?? 0) * 0.7 + $relevanceA * 0.3;
                $scoreB = ($b['count'] ?? 0) * 0.7 + $relevanceB * 0.3;

                return $scoreB <=> $scoreA;
            });

            $facets[$field] = $values;
        }

        return $facets;
    }

    /**
     * Create date/time facets with automatic binning.
     */
    public function createDateFacets(array $hits, string $field, string $interval = 'month'): array
    {
        $dateFacets = [];

        foreach ($hits as $hit) {
            $value = $hit->get($field);
            if (empty($value)) continue;

            try {
                $date = new \DateTime($value);
                $bucket = $this->getDateBucket($date, $interval);
                
                if (!isset($dateFacets[$bucket])) {
                    $dateFacets[$bucket] = ['value' => $bucket, 'count' => 0];
                }
                
                $dateFacets[$bucket]['count']++;
            } catch (\Exception $e) {
                // Skip invalid dates
                continue;
            }
        }

        // Sort by date
        ksort($dateFacets);

        return $dateFacets;
    }

    private function calculateFieldFacets(array $hits, string $field, array $config): array
    {
        $facets = [];
        $maxFacets = $config['max_count'] ?? $this->config['max_facets_per_field'];

        foreach ($hits as $hit) {
            $value = $hit->get($field);
            if ($value === null) continue;

            // Handle array values
            $values = is_array($value) ? $value : [$value];

            foreach ($values as $val) {
                $key = $this->normalizeFacetValue($val);
                
                if (!isset($facets[$key])) {
                    $facets[$key] = [
                        'value' => $val,
                        'count' => 0,
                        'score' => 0.0
                    ];
                }

                $facets[$key]['count']++;
                $facets[$key]['score'] += $hit->getScore();
            }
        }

        // Sort by count (descending) and limit
        uasort($facets, fn($a, $b) => $b['count'] <=> $a['count']);

        return array_slice($facets, 0, $maxFacets, true);
    }

    private function generateFieldSuggestions(string $query, array $facets, string $field): array
    {
        $suggestions = [];
        $queryLower = strtolower($query);

        foreach ($facets as $facet) {
            $value = strtolower($facet['value'] ?? '');
            
            if (str_contains($value, $queryLower) && $facet['count'] > 0) {
                $suggestions[] = [
                    'value' => $facet['value'],
                    'count' => $facet['count'],
                    'field' => $field,
                    'relevance' => $this->calculateRelevance($query, $facet['value']),
                ];
            }
        }

        // Sort by relevance and count
        usort($suggestions, function ($a, $b) {
            $scoreA = $a['relevance'] * 0.6 + ($a['count'] / 100) * 0.4;
            $scoreB = $b['relevance'] * 0.6 + ($b['count'] / 100) * 0.4;
            return $scoreB <=> $scoreA;
        });

        return array_slice($suggestions, 0, $this->config['max_suggestions']);
    }

    private function valueInRange($value, array $range): bool
    {
        $min = $range['min'] ?? -INF;
        $max = $range['max'] ?? INF;
        
        return $value >= $min && $value <= $max;
    }

    private function buildHierarchy(array &$hierarchy, array $parts, int $level = 0): void
    {
        if (empty($parts)) return;

        $current = array_shift($parts);
        
        if (!isset($hierarchy[$current])) {
            $hierarchy[$current] = [
                'value' => $current,
                'count' => 0,
                'level' => $level,
                'children' => []
            ];
        }

        $hierarchy[$current]['count']++;

        if (!empty($parts)) {
            $this->buildHierarchy($hierarchy[$current]['children'], $parts, $level + 1);
        }
    }

    private function formatHierarchy(array $hierarchy): array
    {
        $formatted = [];

        foreach ($hierarchy as $key => $data) {
            $formatted[$key] = [
                'value' => $data['value'],
                'count' => $data['count'],
                'level' => $data['level'],
                'children' => !empty($data['children']) ? $this->formatHierarchy($data['children']) : []
            ];
        }

        return $formatted;
    }

    private function getDateBucket(\DateTime $date, string $interval): string
    {
        return match ($interval) {
            'day' => $date->format('Y-m-d'),
            'week' => $date->format('Y-W'),
            'month' => $date->format('Y-m'),
            'quarter' => $date->format('Y') . '-Q' . ceil($date->format('n') / 3),
            'year' => $date->format('Y'),
            default => $date->format('Y-m'),
        };
    }

    private function calculateRelevance(string $query, string $value): float
    {
        if (empty($query) || empty($value)) return 0.0;

        $queryLower = strtolower($query);
        $valueLower = strtolower($value);

        // Exact match
        if ($queryLower === $valueLower) return 1.0;

        // Starts with query
        if (str_starts_with($valueLower, $queryLower)) return 0.8;

        // Contains query
        if (str_contains($valueLower, $queryLower)) return 0.6;

        // Fuzzy match using similar_text
        $percent = 0;
        similar_text($queryLower, $valueLower, $percent);
        return $percent / 100 * 0.4;
    }

    private function normalizeFacetValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        
        if (is_numeric($value)) {
            return (string) $value;
        }
        
        return trim((string) $value);
    }

    private function getDefaultConfig(): array
    {
        return [
            'max_facets_per_field' => 10,
            'max_suggestions' => 5,
            'auto_suggest_fields' => ['category', 'tags', 'author', 'type'],
            'min_facet_count' => 1,
        ];
    }
}