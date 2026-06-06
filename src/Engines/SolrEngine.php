<?php

namespace JarirAhmed\Search\Engines;

use JarirAhmed\Search\Hit;
use JarirAhmed\Search\Query;
use JarirAhmed\Search\SearchEngineInterface;
use JarirAhmed\Search\SearchResult;
use JarirAhmed\Search\Transport\TransportInterface;

/**
 * Apache Solr backend (uses the JSON Request API and JSON update handler).
 */
class SolrEngine implements SearchEngineInterface
{
    /** @var TransportInterface */
    private $transport;
    /** @var string Solr core / collection name. */
    private $core;

    public function __construct(TransportInterface $transport, string $core)
    {
        $this->transport = $transport;
        $this->core = $core;
    }

    public function index(string $id, array $document): void
    {
        $this->transport->request('POST', "/{$this->core}/update?commit=true", [
            array_merge(['id' => $id], $document),
        ]);
    }

    public function bulk(array $documents): void
    {
        $docs = [];
        foreach ($documents as $id => $document) {
            $docs[] = array_merge(['id' => (string) $id], $document);
        }
        if ($docs !== []) {
            $this->transport->request('POST', "/{$this->core}/update?commit=true", $docs);
        }
    }

    public function delete(string $id): bool
    {
        $this->transport->request('POST', "/{$this->core}/update?commit=true", ['delete' => ['id' => $id]]);
        return true;
    }

    public function clear(): void
    {
        $this->transport->request('POST', "/{$this->core}/update?commit=true", ['delete' => ['query' => '*:*']]);
    }

    public function search(Query $query): SearchResult
    {
        $response = $this->transport->request('POST', "/{$this->core}/select", $this->buildBody($query));
        return $this->parse($response);
    }

    /** @return array<string,mixed> Solr JSON Request API body. */
    public function buildBody(Query $query): array
    {
        $term = trim($query->getTerm());
        $fuzz = $query->getFuzziness();

        if ($term === '') {
            $q = '*:*';
        } elseif ($fuzz > 0) {
            $tokens = preg_split('/\s+/', $term) ?: [];
            $q = implode(' ', array_map(static function ($t) use ($fuzz) {
                return $t . '~' . $fuzz;
            }, $tokens));
        } else {
            $q = $term;
        }

        $filters = [];
        foreach ($query->getFilters() as $field => $value) {
            $filters[] = $field . ':' . self::escape((string) $value);
        }

        $body = [
            'query' => $q,
            'offset' => $query->getFrom(),
            'limit' => $query->getSize(),
            'fields' => ['*', 'score'],
            'params' => [
                'defType' => 'edismax',
                'qf' => $this->qf($query->getFields()),
            ],
        ];
        if ($filters !== []) {
            $body['filter'] = $filters;
        }
        $sort = $query->getSort();
        if ($sort !== null) {
            $body['sort'] = $sort[0] . ' ' . $sort[1];
        }

        return $body;
    }

    /** @param array<string,float> $fields */
    private function qf(array $fields): string
    {
        if ($fields === []) {
            return '*';
        }
        $parts = [];
        foreach ($fields as $field => $boost) {
            $parts[] = ($boost != 1.0) ? $field . '^' . $boost : $field;
        }
        return implode(' ', $parts);
    }

    private static function escape(string $value): string
    {
        // Quote values that contain whitespace or Solr special characters.
        if (preg_match('/[\s:"]/', $value)) {
            return '"' . str_replace('"', '\"', $value) . '"';
        }
        return $value;
    }

    /** @param array<string,mixed> $response */
    private function parse(array $response): SearchResult
    {
        $node = $response['response'] ?? [];
        $total = (int) ($node['numFound'] ?? 0);

        $hits = [];
        foreach ($node['docs'] ?? [] as $doc) {
            $id = (string) ($doc['id'] ?? '');
            $score = (float) ($doc['score'] ?? 0);
            unset($doc['score']);
            $hits[] = new Hit($id, $score, $doc);
        }

        return new SearchResult($hits, $total);
    }
}
