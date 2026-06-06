<?php

namespace JarirAhmed\Search\Engines;

use JarirAhmed\Search\Hit;
use JarirAhmed\Search\Query;
use JarirAhmed\Search\SearchEngineInterface;
use JarirAhmed\Search\SearchResult;

/**
 * Dependency-free, in-process search engine. Tokenizes text, scores by term frequency
 * with optional field boosts, supports fuzzy (Levenshtein) and substring matching,
 * exact filters, sorting and pagination. Ideal for tests, small datasets and offline use.
 */
class InMemoryEngine implements SearchEngineInterface
{
    /** @var array<string,array<string,mixed>> id => document */
    private $documents = [];

    public function index(string $id, array $document): void
    {
        $this->documents[$id] = $document;
    }

    public function bulk(array $documents): void
    {
        foreach ($documents as $id => $document) {
            $this->index((string) $id, $document);
        }
    }

    public function delete(string $id): bool
    {
        if (array_key_exists($id, $this->documents)) {
            unset($this->documents[$id]);
            return true;
        }
        return false;
    }

    public function clear(): void
    {
        $this->documents = [];
    }

    public function search(Query $query): SearchResult
    {
        $tokens = self::tokenize($query->getTerm());
        $matchAll = ($tokens === []);
        $candidates = [];

        foreach ($this->documents as $id => $doc) {
            if (!$this->passesFilters($doc, $query->getFilters())) {
                continue;
            }

            $score = $matchAll ? 1.0 : $this->score($doc, $tokens, $query);
            if ($score > 0) {
                $candidates[] = ['id' => (string) $id, 'score' => $score, 'source' => $doc];
            }
        }

        $candidates = $this->order($candidates, $query);
        $total = count($candidates);

        $page = array_slice($candidates, $query->getFrom(), $query->getSize());
        $hits = array_map(static function ($c) {
            return new Hit($c['id'], (float) $c['score'], $c['source']);
        }, $page);

        return new SearchResult($hits, $total);
    }

    // --- scoring ------------------------------------------------------------

    /**
     * @param array<string,mixed> $doc
     * @param string[]            $queryTokens
     */
    private function score(array $doc, array $queryTokens, Query $query): float
    {
        $fields = $query->getFields();
        if ($fields === []) {
            // Default: every string-ish field, unboosted.
            foreach ($doc as $field => $value) {
                if ($this->stringify($value) !== null) {
                    $fields[$field] = 1.0;
                }
            }
        }

        $fuzziness = $query->getFuzziness();
        $score = 0.0;

        foreach ($fields as $field => $boost) {
            $text = $this->stringify($doc[$field] ?? null);
            if ($text === null) {
                continue;
            }
            $haystackLower = mb_strtolower($text);
            $fieldTokens = self::tokenize($text);

            foreach ($queryTokens as $qToken) {
                $exact = self::countOccurrences($fieldTokens, $qToken);
                if ($exact > 0) {
                    $score += $boost * $exact;            // term frequency
                    continue;
                }
                if ($fuzziness > 0 && $this->fuzzyMatches($qToken, $fieldTokens, $fuzziness)) {
                    $score += $boost * 0.5;               // approximate match
                    continue;
                }
                if (mb_strlen($qToken) >= 2 && mb_strpos($haystackLower, $qToken) !== false) {
                    $score += $boost * 0.25;              // substring / partial
                }
            }
        }

        return $score;
    }

    /**
     * @param string[] $fieldTokens
     */
    private function fuzzyMatches(string $qToken, array $fieldTokens, int $maxDistance): bool
    {
        foreach ($fieldTokens as $token) {
            // Skip pairs whose length gap alone exceeds the budget — cheap prune.
            if (abs(strlen($token) - strlen($qToken)) > $maxDistance) {
                continue;
            }
            if (levenshtein($qToken, $token) <= $maxDistance) {
                return true;
            }
        }
        return false;
    }

    // --- filtering / ordering ----------------------------------------------

    /**
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $filters
     */
    private function passesFilters(array $doc, array $filters): bool
    {
        foreach ($filters as $field => $expected) {
            if (!array_key_exists($field, $doc)) {
                return false;
            }
            $actual = $doc[$field];
            if (is_array($actual)) {
                if (!in_array($expected, $actual, false)) {
                    return false;
                }
            } elseif ($actual != $expected) { // loose compare on purpose ("1" == 1)
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<int,array{id:string,score:float,source:array<string,mixed>}> $candidates
     * @return array<int,array{id:string,score:float,source:array<string,mixed>}>
     */
    private function order(array $candidates, Query $query): array
    {
        $sort = $query->getSort();
        if ($sort === null) {
            usort($candidates, static function ($a, $b) {
                return $b['score'] <=> $a['score'] ?: strcmp($a['id'], $b['id']);
            });
            return $candidates;
        }

        [$field, $direction] = $sort;
        usort($candidates, static function ($a, $b) use ($field, $direction) {
            $av = $a['source'][$field] ?? null;
            $bv = $b['source'][$field] ?? null;
            $cmp = $av <=> $bv;
            return $direction === 'desc' ? -$cmp : $cmp;
        });
        return $candidates;
    }

    // --- helpers ------------------------------------------------------------

    /** @return string[] */
    private static function tokenize(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        return $parts === false ? [] : $parts;
    }

    /** @param string[] $tokens */
    private static function countOccurrences(array $tokens, string $needle): int
    {
        $count = 0;
        foreach ($tokens as $token) {
            if ($token === $needle) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Turn a scalar (or array of scalars) field value into searchable text, or null.
     *
     * @param mixed $value
     */
    private function stringify($value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            $scalars = array_filter($value, static function ($v) {
                return is_scalar($v);
            });
            return $scalars === [] ? null : implode(' ', array_map('strval', $scalars));
        }
        return null;
    }
}
