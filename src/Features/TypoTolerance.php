<?php

declare(strict_types=1);

namespace Phind\SemanticSearch\Features;

class TypoTolerance
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Correct typos in search query using multiple algorithms.
     */
    public function correctQuery(string $query): string
    {
        $words = $this->tokenize($query);
        $correctedWords = [];

        foreach ($words as $word) {
            if (strlen($word) < $this->config['min_word_length']) {
                $correctedWords[] = $word;
                continue;
            }

            $correction = $this->findBestCorrection($word);
            $correctedWords[] = $correction ?? $word;
        }

        return implode(' ', $correctedWords);
    }

    /**
     * Generate alternative spellings for fuzzy matching.
     */
    public function generateAlternatives(string $word): array
    {
        if (strlen($word) < $this->config['min_word_length']) {
            return [$word];
        }

        $alternatives = [$word];
        
        // Generate phonetic alternatives
        $alternatives[] = soundex($word);
        $alternatives[] = metaphone($word);
        
        // Generate edit distance alternatives
        $alternatives = array_merge($alternatives, $this->generateEditDistanceAlternatives($word));
        
        // Generate keyboard layout alternatives
        $alternatives = array_merge($alternatives, $this->generateKeyboardAlternatives($word));
        
        return array_unique(array_filter($alternatives));
    }

    /**
     * Calculate similarity score between two words.
     */
    public function calculateSimilarity(string $word1, string $word2): float
    {
        if ($word1 === $word2) {
            return 1.0;
        }

        // Combine multiple similarity metrics
        $levenshtein = 1 - (levenshtein($word1, $word2) / max(strlen($word1), strlen($word2)));
        $jaroWinkler = $this->jaroWinkler($word1, $word2);
        $phonetic = (soundex($word1) === soundex($word2)) ? 1.0 : 0.0;

        return ($levenshtein * 0.4 + $jaroWinkler * 0.4 + $phonetic * 0.2);
    }

    /**
     * Check if two words are phonetically similar.
     */
    public function isPhoneticallyClose(string $word1, string $word2): bool
    {
        return soundex($word1) === soundex($word2) || 
               metaphone($word1) === metaphone($word2);
    }

    private function findBestCorrection(string $word): ?string
    {
        // This would typically use a dictionary or frequency-based word list
        // For now, we'll use a simple approach
        $alternatives = $this->generateAlternatives($word);
        
        // In a real implementation, you'd check against a dictionary
        // and return the most likely correction based on frequency
        return null;
    }

    private function generateEditDistanceAlternatives(string $word): array
    {
        $alternatives = [];
        $maxDistance = $this->config['max_edit_distance'];

        // Single character insertions
        for ($i = 0; $i <= strlen($word); $i++) {
            for ($c = 'a'; $c <= 'z'; $c++) {
                $alternatives[] = substr($word, 0, $i) . $c . substr($word, $i);
            }
        }

        // Single character deletions
        for ($i = 0; $i < strlen($word); $i++) {
            $alternatives[] = substr($word, 0, $i) . substr($word, $i + 1);
        }

        // Single character substitutions
        for ($i = 0; $i < strlen($word); $i++) {
            for ($c = 'a'; $c <= 'z'; $c++) {
                if ($c !== $word[$i]) {
                    $alternatives[] = substr($word, 0, $i) . $c . substr($word, $i + 1);
                }
            }
        }

        // Character transpositions
        for ($i = 0; $i < strlen($word) - 1; $i++) {
            $alternatives[] = substr($word, 0, $i) . 
                             $word[$i + 1] . 
                             $word[$i] . 
                             substr($word, $i + 2);
        }

        return array_slice($alternatives, 0, $this->config['max_alternatives']);
    }

    private function generateKeyboardAlternatives(string $word): array
    {
        $keyboard = [
            'q' => ['w', 'a', 's'],
            'w' => ['q', 'e', 'a', 's', 'd'],
            'e' => ['w', 'r', 's', 'd', 'f'],
            'r' => ['e', 't', 'd', 'f', 'g'],
            't' => ['r', 'y', 'f', 'g', 'h'],
            'y' => ['t', 'u', 'g', 'h', 'j'],
            'u' => ['y', 'i', 'h', 'j', 'k'],
            'i' => ['u', 'o', 'j', 'k', 'l'],
            'o' => ['i', 'p', 'k', 'l'],
            'p' => ['o', 'l'],
            'a' => ['q', 'w', 's', 'z', 'x'],
            's' => ['a', 'w', 'e', 'd', 'z', 'x', 'c'],
            'd' => ['s', 'e', 'r', 'f', 'x', 'c', 'v'],
            'f' => ['d', 'r', 't', 'g', 'c', 'v', 'b'],
            'g' => ['f', 't', 'y', 'h', 'v', 'b', 'n'],
            'h' => ['g', 'y', 'u', 'j', 'b', 'n', 'm'],
            'j' => ['h', 'u', 'i', 'k', 'n', 'm'],
            'k' => ['j', 'i', 'o', 'l', 'm'],
            'l' => ['k', 'o', 'p'],
            'z' => ['a', 's', 'x'],
            'x' => ['z', 'a', 's', 'd', 'c'],
            'c' => ['x', 's', 'd', 'f', 'v'],
            'v' => ['c', 'd', 'f', 'g', 'b'],
            'b' => ['v', 'f', 'g', 'h', 'n'],
            'n' => ['b', 'g', 'h', 'j', 'm'],
            'm' => ['n', 'h', 'j', 'k'],
        ];

        $alternatives = [];
        
        for ($i = 0; $i < strlen($word); $i++) {
            $char = strtolower($word[$i]);
            if (isset($keyboard[$char])) {
                foreach ($keyboard[$char] as $neighbor) {
                    $alternatives[] = substr($word, 0, $i) . $neighbor . substr($word, $i + 1);
                }
            }
        }

        return array_slice($alternatives, 0, $this->config['max_alternatives']);
    }

    private function jaroWinkler(string $str1, string $str2): float
    {
        $len1 = strlen($str1);
        $len2 = strlen($str2);

        if ($len1 === 0) return $len2 === 0 ? 1.0 : 0.0;
        if ($len2 === 0) return 0.0;

        $match_distance = intval(max($len1, $len2) / 2) - 1;
        $match_distance = max(0, $match_distance);

        $str1_matches = array_fill(0, $len1, false);
        $str2_matches = array_fill(0, $len2, false);

        $matches = 0;
        $transpositions = 0;

        // Identify matches
        for ($i = 0; $i < $len1; $i++) {
            $start = max(0, $i - $match_distance);
            $end = min($i + $match_distance + 1, $len2);

            for ($j = $start; $j < $end; $j++) {
                if ($str2_matches[$j] || $str1[$i] !== $str2[$j]) {
                    continue;
                }

                $str1_matches[$i] = true;
                $str2_matches[$j] = true;
                $matches++;
                break;
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        // Count transpositions
        $k = 0;
        for ($i = 0; $i < $len1; $i++) {
            if (!$str1_matches[$i]) continue;

            while (!$str2_matches[$k]) {
                $k++;
            }

            if ($str1[$i] !== $str2[$k]) {
                $transpositions++;
            }

            $k++;
        }

        $jaro = ($matches / $len1 + $matches / $len2 + 
                ($matches - $transpositions / 2) / $matches) / 3;

        // Calculate Jaro-Winkler
        $prefix = 0;
        for ($i = 0; $i < min($len1, $len2, 4); $i++) {
            if ($str1[$i] === $str2[$i]) {
                $prefix++;
            } else {
                break;
            }
        }

        return $jaro + (0.1 * $prefix * (1 - $jaro));
    }

    private function tokenize(string $text): array
    {
        return array_filter(
            preg_split('/\s+/', strtolower(trim($text))),
            fn($word) => !empty($word)
        );
    }

    private function getDefaultConfig(): array
    {
        return [
            'min_word_length' => 3,
            'max_edit_distance' => 2,
            'max_alternatives' => 10,
            'similarity_threshold' => 0.7,
        ];
    }
}