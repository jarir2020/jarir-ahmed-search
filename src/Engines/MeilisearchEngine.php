<?php

namespace JarirAhmed\Search\Engines;

use JarirAhmed\Search\Hit;
use JarirAhmed\Search\Query;
use JarirAhmed\Search\SearchEngineInterface;
use JarirAhmed\Search\SearchResult;
use JarirAhmed\Search\Transport\TransportInterface;

/**
 * Meilisearch backend. The transport should send an Authorization: Bearer <key> header.
 *
 * Filtering/sorting require the corresponding attributes to be configured as
 * filterableAttributes / sortableAttributes on the index.
 */
class MeilisearchEngine implements SearchEngineInterface
{
    /** @var TransportInterface */
    private $transport;
    /** @var string */
    private $index;
    /** @var string */
    private $primaryKey;

    public function __construct(TransportInterface $transport, string $index, string $primaryKey = 'id')
    {
        $this->transport = $transport;
        $this->index = $index;
        $this->primaryKey = $primaryKey;
    }

    public function index(string $id, array $document): void
    {
        $document[$this->primaryKey] = $id;
        $this->transport->request('POST', "/indexes/{$this->index}/documents", [$document]);
    }

    public function bulk(array $documents): void
    {
        $docs = [];
        foreach ($documents as $id => $document) {
            $document[$this->primaryKey] = (string) $id;
            $docs[] = $document;
        }
        if ($docs !== []) {
            $this->transport->request('POST', "/indexes/{$this->index}/documents", $docs);
        }
    }

    public function delete(string $id): bool
    {
        $this->transport->request('DELETE', "/indexes/{$this->index}/documents/" . rawurlencode($id));
        return true;
    }

    public function clear(): void
    {
        $this->transport->request('DELETE', "/indexes/{$this->index}/documents");
    }

    public function search(Query $query): SearchResult
    {
        $response = $this->transport->request('POST', "/indexes/{$this->index}/search", $this->buildBody($query));
        return $this->parse($response);
    }

    /** @return array<string,mixed> Meilisearch search payload. */
    public function buildBody(Query $query): array
    {
        $body = [
            'q' => $query->getTerm(),
            'offset' => $query->getFrom(),
            'limit' => $query->getSize(),
        ];

        $fields = array_keys($query->getFields());
        if ($fields !== []) {
            $body['attributesToSearchOn'] = $fields;
        }

        $filters = [];
        foreach ($query->getFilters() as $field => $value) {
            $filters[] = $field . ' = ' . self::quote((string) $value);
        }
        if ($filters !== []) {
            $body['filter'] = $filters;
        }

        $sort = $query->getSort();
        if ($sort !== null) {
            $body['sort'] = [$sort[0] . ':' . $sort[1]];
        }

        return $body;
    }

    private static function quote(string $value): string
    {
        return '"' . str_replace('"', '\"', $value) . '"';
    }

    /** @param array<string,mixed> $response */
    private function parse(array $response): SearchResult
    {
        $total = (int) ($response['estimatedTotalHits'] ?? $response['totalHits'] ?? count($response['hits'] ?? []));
        $hits = [];
        $rank = count($response['hits'] ?? []);
        foreach ($response['hits'] ?? [] as $hit) {
            $id = (string) ($hit[$this->primaryKey] ?? '');
            $score = isset($hit['_rankingScore']) ? (float) $hit['_rankingScore'] : (float) $rank--;
            unset($hit['_rankingScore'], $hit['_formatted']);
            $hits[] = new Hit($id, $score, $hit);
        }
        return new SearchResult($hits, $total);
    }
}
