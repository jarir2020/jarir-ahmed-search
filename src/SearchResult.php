<?php

namespace JarirAhmed\Search;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The outcome of a search: the ranked hits for the current page plus the total number
 * of matches. Iterable and countable over the hits.
 *
 * @implements IteratorAggregate<int,Hit>
 */
class SearchResult implements IteratorAggregate, Countable
{
    /** @var Hit[] */
    private $hits;
    /** @var int Total matches across all pages. */
    private $total;

    /** @param Hit[] $hits */
    public function __construct(array $hits, int $total)
    {
        $this->hits = array_values($hits);
        $this->total = $total;
    }

    /** @return Hit[] */
    public function hits(): array
    {
        return $this->hits;
    }

    /** Total matches across all pages (not just the returned slice). */
    public function total(): int
    {
        return $this->total;
    }

    /** @return string[] Ids of the returned hits, in rank order. */
    public function ids(): array
    {
        return array_map(static function (Hit $h) {
            return $h->id;
        }, $this->hits);
    }

    /** @return array<int,array<string,mixed>> The source documents of the returned hits. */
    public function documents(): array
    {
        return array_map(static function (Hit $h) {
            return $h->source;
        }, $this->hits);
    }

    public function isEmpty(): bool
    {
        return $this->hits === [];
    }

    public function count(): int
    {
        return count($this->hits);
    }

    /** @return Traversable<int,Hit> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->hits);
    }
}
