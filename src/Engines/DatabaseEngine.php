<?php

namespace JarirAhmed\Search\Engines;

use JarirAhmed\Search\Hit;
use JarirAhmed\Search\Query;
use JarirAhmed\Search\SearchEngineInterface;
use JarirAhmed\Search\SearchResult;
use PDO;

/**
 * Database-native search over a PDO connection. Documents are stored in a table with a
 * lowercased searchable "content" column; matching is pushed to the database with LIKE
 * (portable across SQLite, MySQL and Postgres), then filters/scoring/sorting/paging are
 * refined in PHP. Self-creates its table.
 */
class DatabaseEngine implements SearchEngineInterface
{
    /** @var PDO */
    private $pdo;
    /** @var string */
    private $table;

    public function __construct(PDO $pdo, string $table = 'search_index')
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Restrict to a safe identifier — the table name is interpolated into SQL.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        }
        $this->table = $table;
        $this->createTable();
    }

    public function index(string $id, array $document): void
    {
        $sql = "REPLACE INTO {$this->table} (id, content, source) VALUES (?, ?, ?)";
        $this->pdo->prepare($sql)->execute([
            $id,
            $this->buildContent($document),
            json_encode($document),
        ]);
    }

    public function bulk(array $documents): void
    {
        $this->pdo->beginTransaction();
        try {
            foreach ($documents as $id => $document) {
                $this->index((string) $id, $document);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function delete(string $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function clear(): void
    {
        $this->pdo->exec("DELETE FROM {$this->table}");
    }

    public function search(Query $query): SearchResult
    {
        $tokens = self::tokenize($query->getTerm());

        $sql = "SELECT id, content, source FROM {$this->table}";
        $params = [];
        if ($tokens !== []) {
            $clauses = [];
            foreach ($tokens as $token) {
                $clauses[] = 'content LIKE ?';
                $params[] = '%' . $token . '%';
            }
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $candidates = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $source = json_decode($row['source'], true);
            if (!is_array($source) || !$this->passesFilters($source, $query->getFilters())) {
                continue;
            }
            $candidates[] = [
                'id' => (string) $row['id'],
                'score' => $tokens === [] ? 1.0 : $this->score($row['content'], $tokens),
                'source' => $source,
            ];
        }

        $candidates = $this->order($candidates, $query);
        $total = count($candidates);
        $page = array_slice($candidates, $query->getFrom(), $query->getSize());
        $hits = array_map(static function ($c) {
            return new Hit($c['id'], (float) $c['score'], $c['source']);
        }, $page);

        return new SearchResult($hits, $total);
    }

    // --- internals ----------------------------------------------------------

    private function createTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (" .
            'id VARCHAR(255) PRIMARY KEY, ' .
            'content TEXT, ' .
            'source TEXT' .
            ')'
        );
    }

    /** @param array<string,mixed> $document */
    private function buildContent(array $document): string
    {
        $parts = [];
        array_walk_recursive($document, static function ($value) use (&$parts) {
            if (is_scalar($value)) {
                $parts[] = (string) $value;
            }
        });
        return mb_strtolower(implode(' ', $parts));
    }

    /** @param string[] $tokens */
    private function score(string $content, array $tokens): float
    {
        $score = 0.0;
        foreach ($tokens as $token) {
            $score += substr_count($content, $token);
        }
        return $score;
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
            $cmp = ($a['source'][$field] ?? null) <=> ($b['source'][$field] ?? null);
            return $direction === 'desc' ? -$cmp : $cmp;
        });
        return $candidates;
    }

    /** @return string[] */
    private static function tokenize(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        return $parts === false ? [] : $parts;
    }
}
