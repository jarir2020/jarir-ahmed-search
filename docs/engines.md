# Engine setup guide

Every engine implements the same `SearchEngineInterface`, so application code
(`$engine->index(...)`, `$engine->search(Query...)`) never changes. What differs is the
one-time setup: the transport/credentials and any index configuration the service needs
for filtering, sorting and field selection.

The `Query` features map onto each backend as follows:

| Query feature        | Requirement on the backend |
|----------------------|----------------------------|
| `fields(['t'=>2.0])` | Field must be searchable/text. Boost support varies (see per-engine notes). |
| `filter($f,$v)`      | Field must be filterable / a keyword (exact) field. |
| `sortBy($f,$dir)`    | Field must be sortable (keyword/numeric). |
| `fuzziness($n)`      | Native where supported; otherwise approximated or ignored. |
| `from()`/`size()`    | Pagination (offset/limit or page based). |
| `vector([...])`      | Only the `VectorEngine`. |

---

## InMemoryEngine

```php
use JarirAhmed\Search\Engines\InMemoryEngine;
$engine = new InMemoryEngine();
```

No setup. Tokenized scoring with term frequency, field boosts, fuzzy (Levenshtein) and
substring matching, exact filters (including array membership), sorting and pagination.
Great for tests, small/static datasets, or as a fallback. Not for very large corpora.

---

## ElasticsearchEngine / OpenSearch

```php
use JarirAhmed\Search\Engines\ElasticsearchEngine;
use JarirAhmed\Search\Transport\CurlTransport;

$transport = new CurlTransport('http://localhost:9200', [
    'username' => 'elastic', 'password' => 'changeme', // or 'apiKey' => '...'
    'timeout'  => 5,
]);
$engine = new ElasticsearchEngine($transport, 'products');
```

- `index()` uses `?refresh=true` for read-after-write in tests/demos; drop it for bulk
  throughput in production.
- `filter` uses a `term` query and `sort` sorts on the raw field, so those fields should be
  `keyword`/numeric in your mapping (e.g. `category.keyword`).
- `fuzziness($n)` maps to the `multi_match` `fuzziness` parameter.

---

## SolrEngine

```php
use JarirAhmed\Search\Engines\SolrEngine;
$engine = new SolrEngine(new CurlTransport('http://localhost:8983/solr'), 'products');
```

- Uses the JSON Request API with `defType=edismax`; field boosts become `qf` (`title^2`).
- Documents must have an `id` field (added automatically from the index id).
- `fuzziness($n)` appends `~n` to each term.
- `filter`/`sort` fields must be indexed accordingly in your Solr schema.

---

## AlgoliaEngine

```php
use JarirAhmed\Search\Engines\AlgoliaEngine;
$transport = new CurlTransport('https://APPID-dsn.algolia.net', ['headers' => [
    'X-Algolia-Application-Id: APPID',
    'X-Algolia-API-Key: SEARCH_OR_ADMIN_KEY',
]]);
$engine = new AlgoliaEngine($transport, 'products');
```

- Configure `searchableAttributes` and `attributesForFaceting` (for `filter`) on the index
  in the Algolia dashboard or via settings.
- `fields()` maps to `restrictSearchableAttributes`. Per-query boosts are not supported
  by Algolia (ranking is index-level); they are ignored.
- Algolia returns no numeric relevance score, so `Hit::$score` is the descending rank.

---

## MeilisearchEngine

```php
use JarirAhmed\Search\Engines\MeilisearchEngine;
$transport = new CurlTransport('http://127.0.0.1:7700', ['headers' => [
    'Authorization: Bearer MASTER_OR_API_KEY',
]]);
$engine = new MeilisearchEngine($transport, 'products', 'id'); // primary key
```

- `filter` requires the fields to be in the index `filterableAttributes`; `sort` requires
  `sortableAttributes`.
- Typo tolerance is automatic, so `fuzziness()` is not needed (and is ignored).

---

## TypesenseEngine

```php
use JarirAhmed\Search\Engines\TypesenseEngine;
$transport = new CurlTransport('http://127.0.0.1:8108', ['headers' => [
    'X-TYPESENSE-API-KEY: KEY',
]]);
// Provide default query_by fields (Typesense requires them):
$engine = new TypesenseEngine($transport, 'products', ['title', 'description']);
```

- Create the collection with a schema first; the searched fields must exist and be
  `string`/`string[]`. `filter` and `sort` fields must be defined accordingly.
- `clear()` drops the collection (Typesense has no "delete all documents" without a filter).

---

## DatabaseEngine (PDO)

```php
use JarirAhmed\Search\Engines\DatabaseEngine;
$engine = new DatabaseEngine(new PDO('mysql:host=localhost;dbname=app', 'user', 'pass'));
// or new PDO('sqlite:/path/search.db'), or a Postgres DSN
```

- Self-creates a table (`search_index` by default: `id`, `content`, `source`).
- Matching is portable `LIKE` over a lowercased `content` column (all tokens must appear),
  with filters/scoring/sorting refined in PHP. Works on SQLite, MySQL and Postgres with no
  extra setup. For very large tables, add a native full-text index and adapt as needed.

---

## VectorEngine (semantic)

```php
use JarirAhmed\Search\Engines\VectorEngine;
$engine = new VectorEngine('embedding'); // document field holding the float[] vector

$engine->index('doc1', ['title' => '...', 'embedding' => $modelEmbedding]);
$result = $engine->search(Query::create('')->vector($queryEmbedding));
```

- Bring embeddings from any model (OpenAI, Cohere, sentence-transformers, ...).
- Ranks by cosine similarity; documents whose vector dimensionality differs from the query
  vector are skipped. Filters apply before ranking.
- In-process — pair it with a vector database for large-scale ANN if needed.
