<?php

namespace JarirAhmed\Search;

/**
 * Immutable-ish fluent query description, shared by every engine.
 *
 *   $q = Query::create('blue shoes')
 *       ->fields(['title' => 2.0, 'description' => 1.0])
 *       ->filter('category', 'footwear')
 *       ->fuzziness(1)
 *       ->size(20)
 *       ->from(0)
 *       ->sortBy('price', 'asc');
 */
class Query
{
    /** @var string */
    private $term;
    /** @var array<string,float> field => boost. Empty means "all string fields". */
    private $fields = [];
    /** @var array<string,mixed> field => required exact value (ANDed). */
    private $filters = [];
    /** @var int Max edit distance for a token to fuzzy-match (0 = exact). */
    private $fuzziness = 0;
    /** @var int */
    private $size = 10;
    /** @var int */
    private $from = 0;
    /** @var array{0:string,1:string}|null [field, 'asc'|'desc']; null = by relevance score. */
    private $sort = null;
    /** @var float[]|null Query embedding for semantic/vector search. */
    private $vector = null;

    public function __construct(string $term = '')
    {
        $this->term = $term;
    }

    public static function create(string $term = ''): self
    {
        return new self($term);
    }

    public function term(string $term): self
    {
        $this->term = $term;
        return $this;
    }

    /**
     * Limit the searched fields and optionally boost them.
     *
     * @param array<int,string>|array<string,float> $fields ['title','body'] or ['title'=>2.0]
     */
    public function fields(array $fields): self
    {
        $normalized = [];
        foreach ($fields as $key => $value) {
            if (is_int($key)) {
                $normalized[(string) $value] = 1.0;
            } else {
                $normalized[$key] = (float) $value;
            }
        }
        $this->fields = $normalized;
        return $this;
    }

    /** @param mixed $value */
    public function filter(string $field, $value): self
    {
        $this->filters[$field] = $value;
        return $this;
    }

    public function fuzziness(int $distance): self
    {
        $this->fuzziness = max(0, $distance);
        return $this;
    }

    public function size(int $size): self
    {
        $this->size = max(0, $size);
        return $this;
    }

    public function from(int $from): self
    {
        $this->from = max(0, $from);
        return $this;
    }

    public function sortBy(string $field, string $direction = 'asc'): self
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $this->sort = [$field, $direction];
        return $this;
    }

    /**
     * Attach a query embedding for semantic / vector search.
     *
     * @param float[] $vector
     */
    public function vector(array $vector): self
    {
        $this->vector = array_map('floatval', array_values($vector));
        return $this;
    }

    public function getTerm(): string
    {
        return $this->term;
    }

    /** @return array<string,float> */
    public function getFields(): array
    {
        return $this->fields;
    }

    /** @return array<string,mixed> */
    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getFuzziness(): int
    {
        return $this->fuzziness;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getFrom(): int
    {
        return $this->from;
    }

    /** @return array{0:string,1:string}|null */
    public function getSort(): ?array
    {
        return $this->sort;
    }

    /** @return float[]|null */
    public function getVector(): ?array
    {
        return $this->vector;
    }
}
