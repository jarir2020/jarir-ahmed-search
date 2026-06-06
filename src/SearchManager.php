<?php

namespace JarirAhmed\Search;

/**
 * Thin convenience wrapper around any {@see SearchEngineInterface}. Lets callers search
 * with a plain string or a {@see Query}, and swap engines (in-memory, Elasticsearch, ...)
 * without changing call sites.
 */
class SearchManager
{
    /** @var SearchEngineInterface */
    private $engine;

    public function __construct(SearchEngineInterface $engine)
    {
        $this->engine = $engine;
    }

    public function engine(): SearchEngineInterface
    {
        return $this->engine;
    }

    /** @param array<string,mixed> $document */
    public function index(string $id, array $document): self
    {
        $this->engine->index($id, $document);
        return $this;
    }

    /** @param array<string,array<string,mixed>> $documents */
    public function bulk(array $documents): self
    {
        $this->engine->bulk($documents);
        return $this;
    }

    public function delete(string $id): bool
    {
        return $this->engine->delete($id);
    }

    public function clear(): self
    {
        $this->engine->clear();
        return $this;
    }

    /**
     * Search with either a query string or a fully-built Query.
     *
     * @param string|Query $query
     */
    public function search($query): SearchResult
    {
        if (is_string($query)) {
            $query = Query::create($query);
        }
        if (!$query instanceof Query) {
            throw new \InvalidArgumentException('search() expects a string or a Query instance.');
        }
        return $this->engine->search($query);
    }
}
