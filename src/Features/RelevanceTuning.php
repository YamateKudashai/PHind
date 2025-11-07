<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Features;

use Phind\SemanticSearch\Data\SearchResult;
use Phind\SemanticSearch\Data\SearchHit;

class RelevanceTuning
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Apply relevance boosting to search results.
     */
    public function boostResults(SearchResult $result, array $boostConfig = []): SearchResult
    {
        $hits = $result->getHits();
        $boostedHits = [];

        foreach ($hits as $hit) {
            $boostedScore = $this->calculateBoostedScore($hit, $boostConfig);
            
            $boostedHits[] = new SearchHit(
                id: $hit->getId(),
                document: $hit->getDocument(),
                score: $boostedScore,
                highlights: $hit->getHighlights(),
                metadata: array_merge($hit->getMetadata(), [
                    'original_score' => $hit->getScore(),
                    'boost_applied' => $boostedScore !== $hit->getScore(),
                ]),
                source: $hit->getSource()
            );
        }

        // Re-sort by new scores
        usort($boostedHits, fn($a, $b) => $b->getScore() <=> $a->getScore());

        return new SearchResult(
            hits: $boostedHits,
            total: $result->getTotal(),
            offset: $result->getOffset(),
            limit: $result->getLimit(),
            processingTime: $result->getProcessingTime(),
            facets: $result->getFacets(),
            query: array_merge($result->getQuery(), ['relevance_tuning_applied' => true])
        );
    }

    /**
     * Apply field-specific boosting.
     */
    public function applyFieldBoosts(SearchHit $hit, array $fieldBoosts): float
    {
        $boost = 1.0;
        
        foreach ($fieldBoosts as $field => $boostValue) {
            if ($hit->has($field)) {
                $fieldValue = $hit->get($field);
                
                if (is_numeric($fieldValue)) {
                    $boost *= (1 + ($fieldValue * $boostValue));
                } elseif (!empty($fieldValue)) {
                    $boost *= $boostValue;
                }
            }
        }

        return $boost;
    }

    /**
     * Apply time-based decay to scores.
     */
    public function applyTimeDecay(SearchHit $hit, array $timeConfig): float
    {
        $timeField = $timeConfig['field'] ?? 'created_at';
        $decayRate = $timeConfig['decay_rate'] ?? 0.1;
        $maxAge = $timeConfig['max_age_days'] ?? 365;

        if (!$hit->has($timeField)) {
            return 1.0;
        }

        try {
            $timestamp = new \DateTime($hit->get($timeField));
            $now = new \DateTime();
            $ageDays = $now->diff($timestamp)->days;

            if ($ageDays > $maxAge) {
                return $timeConfig['min_score'] ?? 0.1;
            }

            // Exponential decay
            return max(
                $timeConfig['min_score'] ?? 0.1,
                exp(-$decayRate * ($ageDays / 30)) // Decay per month
            );
        } catch (\Exception) {
            return 1.0; // No decay if date parsing fails
        }
    }

    /**
     * Apply popularity-based boosting.
     */
    public function applyPopularityBoost(SearchHit $hit, array $popularityConfig): float
    {
        $fields = $popularityConfig['fields'] ?? ['views', 'likes', 'downloads'];
        $weights = $popularityConfig['weights'] ?? [];
        $boost = 1.0;

        foreach ($fields as $field) {
            if ($hit->has($field)) {
                $value = (float) $hit->get($field);
                $weight = $weights[$field] ?? 1.0;
                $normalizedValue = $this->normalizePopularityValue($value, $popularityConfig);
                
                $boost += $normalizedValue * $weight;
            }
        }

        return $boost;
    }

    /**
     * Apply category-based boosting.
     */
    public function applyCategoryBoost(SearchHit $hit, array $categoryBoosts): float
    {
        $categoryField = 'category';
        $boost = 1.0;

        if ($hit->has($categoryField)) {
            $category = $hit->get($categoryField);
            
            if (isset($categoryBoosts[$category])) {
                $boost *= $categoryBoosts[$category];
            }
        }

        return $boost;
    }

    /**
     * Apply user preference-based boosting.
     */
    public function applyUserPreferences(SearchHit $hit, array $userPreferences): float
    {
        $boost = 1.0;

        // Boost based on user's preferred categories
        if (!empty($userPreferences['categories'])) {
            $hitCategory = $hit->get('category', '');
            if (in_array($hitCategory, $userPreferences['categories'])) {
                $boost *= $userPreferences['category_boost'] ?? 1.5;
            }
        }

        // Boost based on user's preferred authors
        if (!empty($userPreferences['authors'])) {
            $hitAuthor = $hit->get('author', '');
            if (in_array($hitAuthor, $userPreferences['authors'])) {
                $boost *= $userPreferences['author_boost'] ?? 1.3;
            }
        }

        // Boost based on user's language preference
        if (!empty($userPreferences['language'])) {
            $hitLanguage = $hit->get('language', 'en');
            if ($hitLanguage === $userPreferences['language']) {
                $boost *= $userPreferences['language_boost'] ?? 1.2;
            }
        }

        return $boost;
    }

    /**
     * Apply geographic proximity boosting.
     */
    public function applyGeographicBoost(SearchHit $hit, array $geoConfig): float
    {
        if (empty($geoConfig['user_lat']) || empty($geoConfig['user_lon'])) {
            return 1.0;
        }

        $hitLat = $hit->get('latitude');
        $hitLon = $hit->get('longitude');

        if (!is_numeric($hitLat) || !is_numeric($hitLon)) {
            return 1.0;
        }

        $distance = $this->calculateDistance(
            $geoConfig['user_lat'],
            $geoConfig['user_lon'],
            (float) $hitLat,
            (float) $hitLon
        );

        $maxDistance = $geoConfig['max_distance_km'] ?? 1000;
        $boostStrength = $geoConfig['boost_strength'] ?? 0.5;

        if ($distance > $maxDistance) {
            return $geoConfig['min_boost'] ?? 0.5;
        }

        // Linear decay with distance
        $normalizedDistance = $distance / $maxDistance;
        return 1.0 + $boostStrength * (1 - $normalizedDistance);
    }

    /**
     * Apply query-specific field boosting.
     */
    public function applyQueryFieldBoost(SearchHit $hit, string $query, array $fieldConfig): float
    {
        $boost = 1.0;
        $queryTerms = $this->tokenizeQuery($query);

        foreach ($fieldConfig as $field => $fieldBoost) {
            if (!$hit->has($field)) continue;

            $fieldValue = strtolower($hit->get($field, ''));
            $matches = 0;

            foreach ($queryTerms as $term) {
                if (str_contains($fieldValue, $term)) {
                    $matches++;
                }
            }

            if ($matches > 0) {
                $matchRatio = $matches / count($queryTerms);
                $boost += $fieldBoost * $matchRatio;
            }
        }

        return $boost;
    }

    private function calculateBoostedScore(SearchHit $hit, array $boostConfig): float
    {
        $score = $hit->getScore();
        $totalBoost = 1.0;

        // Apply field boosts
        if (!empty($boostConfig['field_boosts'])) {
            $totalBoost *= $this->applyFieldBoosts($hit, $boostConfig['field_boosts']);
        }

        // Apply time decay
        if (!empty($boostConfig['time_decay'])) {
            $totalBoost *= $this->applyTimeDecay($hit, $boostConfig['time_decay']);
        }

        // Apply popularity boost
        if (!empty($boostConfig['popularity'])) {
            $totalBoost *= $this->applyPopularityBoost($hit, $boostConfig['popularity']);
        }

        // Apply category boost
        if (!empty($boostConfig['category_boosts'])) {
            $totalBoost *= $this->applyCategoryBoost($hit, $boostConfig['category_boosts']);
        }

        // Apply user preferences
        if (!empty($boostConfig['user_preferences'])) {
            $totalBoost *= $this->applyUserPreferences($hit, $boostConfig['user_preferences']);
        }

        // Apply geographic boost
        if (!empty($boostConfig['geographic'])) {
            $totalBoost *= $this->applyGeographicBoost($hit, $boostConfig['geographic']);
        }

        // Apply query field boost
        if (!empty($boostConfig['query']) && !empty($boostConfig['query_field_boosts'])) {
            $totalBoost *= $this->applyQueryFieldBoost(
                $hit,
                $boostConfig['query'],
                $boostConfig['query_field_boosts']
            );
        }

        return $score * $totalBoost;
    }

    private function normalizePopularityValue(float $value, array $config): float
    {
        $maxValue = $config['max_popularity'] ?? 1000;
        $logScale = $config['log_scale'] ?? true;

        if ($logScale && $value > 0) {
            return log($value + 1) / log($maxValue + 1);
        }

        return min($value / $maxValue, 1.0);
    }

    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // km

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function tokenizeQuery(string $query): array
    {
        return array_filter(
            array_map('trim', explode(' ', strtolower($query))),
            fn($term) => strlen($term) > 1
        );
    }

    private function getDefaultConfig(): array
    {
        return [
            'max_boost' => 5.0,
            'min_boost' => 0.1,
            'default_time_decay' => 0.1,
            'default_popularity_weight' => 0.3,
        ];
    }
}