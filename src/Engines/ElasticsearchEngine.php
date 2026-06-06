<?php

namespace JarirAhmed\Search\Engines;

use JarirAhmed\Search\Hit;
use JarirAhmed\Search\Query;
use JarirAhmed\Search\SearchEngineInterface;
use JarirAhmed\Search\SearchResult;
use JarirAhmed\Search\Transport\TransportException;
use JarirAhmed\Search\Transport\TransportInterface;

/**
 * Elasticsearch / OpenSearch backend. Talks to the REST API through a
 * {@see TransportInterface}, translating {@see Query} into Query DSL and parsing the
 * response back into a {@see SearchResult}.
 */
class ElasticsearchEngine implements SearchEngineInterface
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
        $this->transport->request('PUT', "/{$this->index}/_doc/" . rawurlencode($id) . '?refresh=true', $document);
    }

    public function bulk(array $documents): void
    {
        // Kept simple and transport-agnostic: one request per document. For very large
        // imports, use the Elasticsearch _bulk API directly.
        foreach ($documents as $id => $document) {
            $this->index((string) $id, $document);
        }
    }

    public function delete(string $id): bool
    {
        try {
            $res = $this->transport->request('DELETE', "/{$this->index}/_doc/" . rawurlencode($id) . '?refresh=true');
        } catch (TransportException $e) {
            return false; // e.g. 404 not_found
        }
        return ($res['result'] ?? null) === 'deleted';
    }

    public function clear(): void
    {
        $this->transport->request('POST', "/{$this->index}/_delete_by_query?refresh=true", [
            'query' => ['match_all' => (object) []],
        ]);
    }

    public function search(Query $query): SearchResult
    {
        $response = $this->transport->request('POST', "/{$this->index}/_search", $this->buildDsl($query));
        return $this->parse($response);
    }

    /**
     * @return array<string,mixed> Elasticsearch Query DSL.
     */
    public function buildDsl(Query $query): array
    {
        $term = $query->getTerm();

        if ($term === '') {
            $must = ['match_all' => (object) []];
        } else {
            $multiMatch = [
                'query' => $term,
                'fields' => $this->esFields($query->getFields()),
            ];
            if ($query->getFuzziness() > 0) {
                $multiMatch['fuzziness'] = (string) $query->getFuzziness();
            }
            $must = ['multi_match' => $multiMatch];
        }

        $filter = [];
        foreach ($query->getFilters() as $field => $value) {
            $filter[] = ['term' => [$field => $value]];
        }

        $dsl = [
            'from' => $query->getFrom(),
            'size' => $query->getSize(),
            'query' => [
                'bool' => [
                    'must' => [$must],
                    'filter' => $filter,
                ],
            ],
        ];

        $sort = $query->getSort();
        if ($sort !== null) {
            [$field, $direction] = $sort;
            $dsl['sort'] = [[$field => ['order' => $direction]]];
        }

        return $dsl;
    }

    /**
     * @param array<string,float> $fields
     * @return string[] e.g. ['title^2', 'body'] or ['*']
     */
    private function esFields(array $fields): array
    {
        if ($fields === []) {
            return ['*'];
        }
        $out = [];
        foreach ($fields as $field => $boost) {
            $out[] = ($boost != 1.0) ? $field . '^' . $boost : $field;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $response
     */
    private function parse(array $response): SearchResult
    {
        $hitsNode = $response['hits'] ?? [];

        $total = 0;
        if (isset($hitsNode['total'])) {
            $total = is_array($hitsNode['total'])
                ? (int) ($hitsNode['total']['value'] ?? 0)
                : (int) $hitsNode['total'];
        }

        $hits = [];
        foreach ($hitsNode['hits'] ?? [] as $h) {
            $hits[] = new Hit(
                (string) ($h['_id'] ?? ''),
                (float) ($h['_score'] ?? 0),
                (array) ($h['_source'] ?? [])
            );
        }

        return new SearchResult($hits, $total);
    }
}
