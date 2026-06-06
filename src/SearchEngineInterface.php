<?php

namespace JarirAhmed\Search;

/**
 * Contract every search backend implements. Documents are plain associative arrays
 * identified by a string id; queries are expressed with {@see Query}.
 */
interface SearchEngineInterface
{
    /**
     * Index (create or replace) a single document.
     *
     * @param array<string,mixed> $document
     */
    public function index(string $id, array $document): void;

    /**
     * Index many documents at once.
     *
     * @param array<string,array<string,mixed>> $documents id => document
     */
    public function bulk(array $documents): void;

    /** Remove a document by id. Returns true if something was removed. */
    public function delete(string $id): bool;

    /** Remove every document from the index. */
    public function clear(): void;

    /** Run a query and return ranked results. */
    public function search(Query $query): SearchResult;
}
