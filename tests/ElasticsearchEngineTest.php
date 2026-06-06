<?php

namespace JarirAhmed\Search\Tests;

use JarirAhmed\Search\Engines\ElasticsearchEngine;
use JarirAhmed\Search\Query;
use JarirAhmed\Search\Transport\TransportException;
use PHPUnit\Framework\TestCase;

class ElasticsearchEngineTest extends TestCase
{
    private function engine(FakeTransport $t): ElasticsearchEngine
    {
        return new ElasticsearchEngine($t, 'products');
    }

    public function testIndexSendsPutToDocEndpoint()
    {
        $t = new FakeTransport(['result' => 'created']);
        $this->engine($t)->index('42', ['title' => 'Shoe']);

        $req = $t->lastRequest();
        $this->assertSame('PUT', $req['method']);
        $this->assertStringStartsWith('/products/_doc/42', $req['path']);
        $this->assertSame(['title' => 'Shoe'], $req['body']);
    }

    public function testDeleteReturnsTrueOnDeletedResult()
    {
        $t = new FakeTransport(['result' => 'deleted']);
        $this->assertTrue($this->engine($t)->delete('42'));
        $this->assertSame('DELETE', $t->lastRequest()['method']);
    }

    public function testDeleteReturnsFalseOnTransportError()
    {
        $t = new class extends FakeTransport {
            public function request(string $method, string $path, ?array $body = null): array
            {
                throw new TransportException('404 not_found');
            }
        };
        $this->assertFalse($this->engine($t)->delete('missing'));
    }

    public function testClearUsesDeleteByQueryMatchAll()
    {
        $t = new FakeTransport(['deleted' => 3]);
        $this->engine($t)->clear();
        $req = $t->lastRequest();
        $this->assertStringContainsString('_delete_by_query', $req['path']);
        $this->assertArrayHasKey('match_all', $req['body']['query']);
    }

    public function testBuildDslMultiMatchWithBoostAndFuzziness()
    {
        $dsl = $this->engine(new FakeTransport())->buildDsl(
            Query::create('blue shoes')
                ->fields(['title' => 2.0, 'description' => 1.0])
                ->fuzziness(1)
                ->filter('category', 'footwear')
                ->from(5)->size(20)
                ->sortBy('price', 'desc')
        );

        $multi = $dsl['query']['bool']['must'][0]['multi_match'];
        $this->assertSame('blue shoes', $multi['query']);
        $this->assertSame(['title^2', 'description'], $multi['fields']);
        $this->assertSame('1', $multi['fuzziness']);

        $this->assertSame([['term' => ['category' => 'footwear']]], $dsl['query']['bool']['filter']);
        $this->assertSame(5, $dsl['from']);
        $this->assertSame(20, $dsl['size']);
        $this->assertSame([['price' => ['order' => 'desc']]], $dsl['sort']);
    }

    public function testBuildDslEmptyTermIsMatchAll()
    {
        $dsl = $this->engine(new FakeTransport())->buildDsl(Query::create(''));
        $this->assertArrayHasKey('match_all', $dsl['query']['bool']['must'][0]);
    }

    public function testBuildDslDefaultFieldsIsWildcard()
    {
        $dsl = $this->engine(new FakeTransport())->buildDsl(Query::create('x'));
        $this->assertSame(['*'], $dsl['query']['bool']['must'][0]['multi_match']['fields']);
    }

    public function testSearchParsesHitsAndTotal()
    {
        $t = new FakeTransport([
            'hits' => [
                'total' => ['value' => 2],
                'hits' => [
                    ['_id' => '1', '_score' => 1.5, '_source' => ['title' => 'A']],
                    ['_id' => '2', '_score' => 0.8, '_source' => ['title' => 'B']],
                ],
            ],
        ]);
        $result = $this->engine($t)->search(Query::create('a'));

        $this->assertSame(2, $result->total());
        $this->assertSame(['1', '2'], $result->ids());
        $this->assertSame(1.5, $result->hits()[0]->score);
        $this->assertSame('A', $result->hits()[0]->source['title']);
        $this->assertSame('POST', $t->lastRequest()['method']);
        $this->assertStringContainsString('/products/_search', $t->lastRequest()['path']);
    }
}
