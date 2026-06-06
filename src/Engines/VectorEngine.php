<?php

namespace JarirAhmed\Search\Engines;

use JarirAhmed\Search\Hit;
use JarirAhmed\Search\Query;
use JarirAhmed\Search\SearchEngineInterface;
use JarirAhmed\Search\SearchResult;

/**
 * In-memory semantic / vector search by cosine similarity.
 *
 * Each document stores an embedding (a float[] under the configured field). Searches
 * supply a query embedding via Query::vector(); results are ranked by cosine similarity.
 * Embeddings come from whatever model you like — this engine has no external dependency.
 */
class VectorEngine implements SearchEngineInterface
{
    /** @var array<string,array<string,mixed>> id => document */
    private $documents = [];
    /** @var string Field holding the embedding vector. */
    private $vectorField;

    public function __construct(string $vectorField = 'embedding')
    {
        $this->vectorField = $vectorField;
    }

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
        $queryVector = $query->getVector();
        if ($queryVector === null || $queryVector === []) {
            throw new \InvalidArgumentException('VectorEngine requires a query embedding via Query::vector().');
        }

        $candidates = [];
        foreach ($this->documents as $id => $doc) {
            if (!$this->passesFilters($doc, $query->getFilters())) {
                continue;
            }
            $vector = $doc[$this->vectorField] ?? null;
            if (!is_array($vector) || count($vector) !== count($queryVector)) {
                continue; // missing or dimension-mismatched embedding
            }
            $candidates[] = [
                'id' => (string) $id,
                'score' => self::cosine($queryVector, $vector),
                'source' => $doc,
            ];
        }

        usort($candidates, static function ($a, $b) {
            return $b['score'] <=> $a['score'] ?: strcmp($a['id'], $b['id']);
        });

        $total = count($candidates);
        $page = array_slice($candidates, $query->getFrom(), $query->getSize());
        $hits = array_map(static function ($c) {
            return new Hit($c['id'], (float) $c['score'], $c['source']);
        }, $page);

        return new SearchResult($hits, $total);
    }

    /**
     * @param float[] $a
     * @param array<int,mixed> $b
     */
    private static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($a as $i => $av) {
            $bv = (float) $b[$i];
            $dot += $av * $bv;
            $normA += $av * $av;
            $normB += $bv * $bv;
        }
        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($normA) * sqrt($normB));
    }

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
            } elseif ($actual != $expected) {
                return false;
            }
        }
        return true;
    }
}
