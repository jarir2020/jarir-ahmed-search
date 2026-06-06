<?php

namespace JarirAhmed\Search\Tests;

use JarirAhmed\Search\Engines\SolrEngine;
use JarirAhmed\Search\Query;
use PHPUnit\Framework\TestCase;

class SolrEngineTest extends TestCase
{
    private function engine(FakeTransport $t): SolrEngine
    {
        return new SolrEngine($t, 'products');
    }

    public function testIndexPostsToUpdateHandler()
    {
        $t = new FakeTransport([]);
        $this->engine($t)->index('1', ['title' => 'Shoe']);
        $req = $t->lastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertStringStartsWith('/products/update', $req['path']);
        $this->assertSame([['id' => '1', 'title' => 'Shoe']], $req['body']);
    }

    public function testClearDeletesAll()
    {
        $t = new FakeTransport([]);
        $this->engine($t)->clear();
        $this->assertSame(['delete' => ['query' => '*:*']], $t->lastRequest()['body']);
    }

    public function testBuildBody()
    {
        $body = $this->engine(new FakeTransport())->buildBody(
            Query::create('blue')->fields(['title' => 2.0, 'body'])->filter('cat', 'shoes')
                ->from(10)->size(5)->sortBy('price', 'desc')
        );
        $this->assertSame('blue', $body['query']);
        $this->assertSame('title^2 body', $body['params']['qf']);
        $this->assertSame('edismax', $body['params']['defType']);
        $this->assertSame(['cat:shoes'], $body['filter']);
        $this->assertSame(10, $body['offset']);
        $this->assertSame(5, $body['limit']);
        $this->assertSame('price desc', $body['sort']);
    }

    public function testFuzzinessAppendsTilde()
    {
        $body = $this->engine(new FakeTransport())->buildBody(Query::create('blu running')->fuzziness(2));
        $this->assertSame('blu~2 running~2', $body['query']);
    }

    public function testParse()
    {
        $t = new FakeTransport([
            'response' => [
                'numFound' => 7,
                'docs' => [
                    ['id' => '1', 'score' => 3.2, 'title' => 'A'],
                    ['id' => '2', 'score' => 1.1, 'title' => 'B'],
                ],
            ],
        ]);
        $result = $this->engine($t)->search(Query::create('a'));
        $this->assertSame(7, $result->total());
        $this->assertSame(['1', '2'], $result->ids());
        $this->assertSame(3.2, $result->hits()[0]->score);
        $this->assertArrayNotHasKey('score', $result->hits()[0]->source);
    }
}
