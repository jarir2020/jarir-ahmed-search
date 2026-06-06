<?php

namespace JarirAhmed\Search\Engines;

use JarirAhmed\Search\Hit;
use JarirAhmed\Search\Query;
use JarirAhmed\Search\SearchEngineInterface;
use JarirAhmed\Search\SearchResult;
use JarirAhmed\Search\Transport\TransportInterface;

/**
 * Algolia backend. The transport must be configured with the Algolia host and the
 * X-Algolia-Application-Id / X-Algolia-API-Key headers.
 *
 * Note: Algolia ranks with its own tie-breaking algorithm and does not return a numeric
 * relevance score, so Hit::$score is the (descending) result position.
 */
class AlgoliaEngine implements SearchEngineInterface
{
    /** @var TransportInterface */
    private $transport;
    /** @var string */
    private $index;

    public function __construct(TransportInterface $transport, string $index)
    {
        $this->transport = $transport;
        $this->index = $index;
    }

    public function index(string $id, array $document): void
    {
        $this->transport->request('PUT', "/1/indexes/{$this->index}/" . rawurlencode($id), $document);
    }

    public function bulk(array $documents): void
    {
        $requests = [];
        foreach ($documents as $id => $document) {
            $requests[] = [
                'action' => 'updateObject',
                'body' => array_merge(['objectID' => (string) $id], $document),
            ];
        }
        if ($requests !== []) {
            $this->transport->request('POST', "/1/indexes/{$this->index}/batch", ['requests' => $requests]);
        }
    }

    public function delete(string $id): bool
    {
        $this->transport->request('DELETE', "/1/indexes/{$this->index}/" . rawurlencode($id));
        return true;
    }

    public function clear(): void
    {
        $this->transport->request('POST', "/1/indexes/{$this->index}/clear");
    }

    public function search(Query $query): SearchResult
    {
        $response = $this->transport->request('POST', "/1/indexes/{$this->index}/query", $this->buildBody($query));
        return $this->parse($response);
    }

    /** @return array<string,mixed> Algolia query payload. */
    public function buildBody(Query $query): array
    {
        $size = max(1, $query->getSize());
        $body = [
            'query' => $query->getTerm(),
            'hitsPerPage' => $size,
            'page' => intdiv($query->getFrom(), $size),
        ];

        $fields = array_keys($query->getFields());
        if ($fields !== []) {
            $body['restrictSearchableAttributes'] = $fields;
        }

        $filters = [];
        foreach ($query->getFilters() as $field => $value) {
            $filters[] = $field . ':' . self::quote((string) $value);
        }
        if ($filters !== []) {
            $body['filters'] = implode(' AND ', $filters);
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
        $total = (int) ($response['nbHits'] ?? 0);
        $hits = [];
        $rank = count($response['hits'] ?? []);
        foreach ($response['hits'] ?? [] as $hit) {
            $id = (string) ($hit['objectID'] ?? '');
            unset($hit['objectID'], $hit['_highlightResult'], $hit['_rankingInfo']);
            $hits[] = new Hit($id, (float) $rank--, $hit);
        }
        return new SearchResult($hits, $total);
    }
}
