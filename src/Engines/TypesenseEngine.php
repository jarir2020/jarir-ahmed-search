<?php

namespace JarirAhmed\Search\Engines;

use JarirAhmed\Search\Hit;
use JarirAhmed\Search\Query;
use JarirAhmed\Search\SearchEngineInterface;
use JarirAhmed\Search\SearchResult;
use JarirAhmed\Search\Transport\TransportInterface;

/**
 * Typesense backend. The transport should send the X-TYPESENSE-API-KEY header.
 *
 * Typesense requires the searched fields to be named explicitly (query_by); pass them via
 * Query::fields() or set a default list in the constructor.
 */
class TypesenseEngine implements SearchEngineInterface
{
    /** @var TransportInterface */
    private $transport;
    /** @var string */
    private $collection;
    /** @var string[] Default query_by fields. */
    private $defaultQueryBy;

    /** @param string[] $defaultQueryBy */
    public function __construct(TransportInterface $transport, string $collection, array $defaultQueryBy = [])
    {
        $this->transport = $transport;
        $this->collection = $collection;
        $this->defaultQueryBy = $defaultQueryBy;
    }

    public function index(string $id, array $document): void
    {
        $document['id'] = $id;
        $this->transport->request('POST', "/collections/{$this->collection}/documents", $document);
    }

    public function bulk(array $documents): void
    {
        foreach ($documents as $id => $document) {
            $this->index((string) $id, $document);
        }
    }

    public function delete(string $id): bool
    {
        $this->transport->request('DELETE', "/collections/{$this->collection}/documents/" . rawurlencode($id));
        return true;
    }

    public function clear(): void
    {
        // Typesense has no "delete all documents" without a filter; drop the collection.
        $this->transport->request('DELETE', "/collections/{$this->collection}");
    }

    public function search(Query $query): SearchResult
    {
        $response = $this->transport->request('GET', $this->buildPath($query));
        return $this->parse($response);
    }

    public function buildPath(Query $query): string
    {
        $size = max(1, $query->getSize());
        $params = [
            'q' => $query->getTerm() === '' ? '*' : $query->getTerm(),
            'query_by' => implode(',', $this->queryBy($query)),
            'per_page' => $size,
            'page' => intdiv($query->getFrom(), $size) + 1,
        ];

        $filters = [];
        foreach ($query->getFilters() as $field => $value) {
            $filters[] = $field . ':=' . $value;
        }
        if ($filters !== []) {
            $params['filter_by'] = implode(' && ', $filters);
        }

        $sort = $query->getSort();
        if ($sort !== null) {
            $params['sort_by'] = $sort[0] . ':' . $sort[1];
        }

        return "/collections/{$this->collection}/documents/search?" . http_build_query($params);
    }

    /**
     * @return string[]
     */
    private function queryBy(Query $query): array
    {
        $fields = array_keys($query->getFields());
        if ($fields !== []) {
            return $fields;
        }
        if ($this->defaultQueryBy !== []) {
            return $this->defaultQueryBy;
        }
        throw new \InvalidArgumentException(
            'Typesense requires query_by fields. Set Query::fields() or a default in the constructor.'
        );
    }

    /** @param array<string,mixed> $response */
    private function parse(array $response): SearchResult
    {
        $total = (int) ($response['found'] ?? 0);
        $hits = [];
        foreach ($response['hits'] ?? [] as $hit) {
            $doc = $hit['document'] ?? [];
            $id = (string) ($doc['id'] ?? '');
            $score = (float) ($hit['text_match'] ?? 0);
            $hits[] = new Hit($id, $score, $doc);
        }
        return new SearchResult($hits, $total);
    }
}
