# search

A unified, framework-agnostic **search abstraction** for PHP. Write your queries once and run
them against **Elasticsearch / OpenSearch** in production or a dependency-free **in-memory**
engine for tests and small datasets — same API, same results shape.

## Features

- One `SearchEngineInterface`: `index`, `bulk`, `delete`, `clear`, `search` — swap backends
  without touching call sites.
- Engines for **Elasticsearch/OpenSearch, Solr, Algolia, Meilisearch, Typesense**, a
  **database** (PDO) engine, an **in-memory** engine, and a **vector/semantic** engine.
- Fluent `Query` builder shared by every engine: term, field boosts, exact filters,
  fuzziness, sorting, pagination, and embeddings for semantic search.
- HTTP engines talk through a pluggable `TransportInterface`, so they're fully
  unit-testable without a live server.

## Backends

| Engine | Class | Notes |
|--------|-------|-------|
| In-memory | `Engines\InMemoryEngine` | Tokenized scoring, fuzzy (Levenshtein), substring, filters. No deps. |
| Elasticsearch / OpenSearch | `Engines\ElasticsearchEngine` | Query DSL via transport. |
| Solr | `Engines\SolrEngine` | JSON Request API + edismax. |
| Algolia | `Engines\AlgoliaEngine` | Transport carries app-id / API-key headers. |
| Meilisearch | `Engines\MeilisearchEngine` | Bearer-token transport. |
| Typesense | `Engines\TypesenseEngine` | Needs `query_by` fields. |
| Database (PDO) | `Engines\DatabaseEngine` | Portable LIKE-based FTS, self-creates its table. |
| Vector / semantic | `Engines\VectorEngine` | Cosine similarity over caller-supplied embeddings. |

## Requirements

- PHP >= 7.4, `ext-json`
- `ext-curl` only if you use the bundled `CurlTransport`

## Install

```bash
composer require jarir-ahmed/search
```

## Quick start (in-memory)

```php
use JarirAhmed\Search\SearchManager;
use JarirAhmed\Search\Engines\InMemoryEngine;
use JarirAhmed\Search\Query;

$search = new SearchManager(new InMemoryEngine());

$search->bulk([
    '1' => ['title' => 'Blue Running Shoes', 'category' => 'footwear', 'price' => 80],
    '2' => ['title' => 'Red Leather Boots',  'category' => 'footwear', 'price' => 150],
]);

// Simple string search
$result = $search->search('blue');
$result->ids();        // ['1']
$result->total();      // 1
$result->documents();  // [['title' => 'Blue Running Shoes', ...]]

// Rich query: boosts, fuzziness, filter, sort, pagination
$result = $search->search(
    Query::create('runing')                 // typo
        ->fields(['title' => 2.0])
        ->fuzziness(1)                       // matches "running"
        ->filter('category', 'footwear')
        ->sortBy('price', 'asc')
        ->from(0)->size(20)
);
```

## Elasticsearch / OpenSearch

```php
use JarirAhmed\Search\SearchManager;
use JarirAhmed\Search\Engines\ElasticsearchEngine;
use JarirAhmed\Search\Transport\CurlTransport;

$transport = new CurlTransport('http://localhost:9200', [
    'username' => 'elastic',
    'password' => 'changeme',
    // or 'apiKey' => '...'
    'timeout'  => 5,
]);

$search = new SearchManager(new ElasticsearchEngine($transport, 'products'));

$search->index('1', ['title' => 'Blue Running Shoes', 'category' => 'footwear']);
$result = $search->search(Query::create('blue')->fields(['title' => 2.0]));
```

The engine talks to ES through a `TransportInterface`. Swap in your own (Guzzle, PSR-18, a
mock) by implementing `request(string $method, string $path, ?array $body): array`.

> Note: `term` filters and `sort` assume the field is keyword/numeric in your ES mapping.

Solr, Algolia, Meilisearch and Typesense work the same way — construct the engine with a
transport and an index/collection name. Configure service auth on the transport, e.g.:

```php
new CurlTransport('https://APPID-dsn.algolia.net', ['headers' => [
    'X-Algolia-Application-Id: APPID',
    'X-Algolia-API-Key: KEY',
]]);                                   // Algolia
new CurlTransport('http://127.0.0.1:7700', ['headers' => ['Authorization: Bearer KEY']]);   // Meilisearch
new CurlTransport('http://127.0.0.1:8108', ['headers' => ['X-TYPESENSE-API-KEY: KEY']]);    // Typesense
```

## Database engine (no search server)

```php
use JarirAhmed\Search\Engines\DatabaseEngine;

$engine = new DatabaseEngine(new PDO('sqlite:search.db')); // or MySQL / Postgres
$engine->index('1', ['title' => 'Blue Shoes', 'category' => 'footwear']);
$hits = $engine->search(Query::create('blue')->filter('category', 'footwear'));
```

## Semantic / vector search

Supply embeddings from any model; ranking is by cosine similarity — no external service.

```php
use JarirAhmed\Search\Engines\VectorEngine;

$engine = new VectorEngine(); // documents carry an "embedding" field
$engine->index('cat', ['label' => 'cat', 'embedding' => [1.0, 0.0, 0.0]]);
$engine->index('car', ['label' => 'car', 'embedding' => [0.0, 0.0, 1.0]]);

$result = $engine->search(Query::create('')->vector([0.95, 0.05, 0.0]));
$result->ids(); // ['cat', ...] ranked by similarity
```

## Query reference

| Method | Description |
|--------|-------------|
| `Query::create($term)` | New query for a search string (empty = match all). |
| `->fields(['title' => 2.0, 'body'])` | Restrict + boost searched fields. |
| `->filter($field, $value)` | Exact filter (ANDed). Matches array membership too. |
| `->fuzziness($n)` | Max edit distance for approximate matches. |
| `->sortBy($field, 'asc'|'desc')` | Sort by a field instead of relevance. |
| `->from($n)` / `->size($n)` | Pagination. |

`search()` returns a `SearchResult` (iterable, countable): `hits()`, `ids()`, `documents()`,
`total()`, `isEmpty()`. Each `Hit` has `id`, `score`, `source`.

## Testing

```bash
composer install
composer test
```

The suite covers the in-memory engine end-to-end and the Elasticsearch engine via a fake
transport (no server required).

## License

MIT
