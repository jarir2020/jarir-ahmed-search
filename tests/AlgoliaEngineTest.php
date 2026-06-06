<?php

namespace JarirAhmed\Search\Tests;

use JarirAhmed\Search\Engines\AlgoliaEngine;
use JarirAhmed\Search\Query;
use PHPUnit\Framework\TestCase;

class AlgoliaEngineTest extends TestCase
{
    private function engine(FakeTransport $t): AlgoliaEngine
    {
        return new AlgoliaEngine($t, 'products');
    }

    public function testIndexPutsWithObjectIdInPath()
    {
        $t = new FakeTransport([]);
        $this->engine($t)->index('42', ['title' => 'Shoe']);
        $req = $t->lastRequest();
        $this->assertSame('PUT', $req['method']);
        $this->assertSame('/1/indexes/products/42', $req['path']);
        $this->assertSame(['title' => 'Shoe'], $req['body']);
    }

    public function testClear()
    {
        $t = new FakeTransport([]);
        $this->engine($t)->clear();
        $this->assertSame('/1/indexes/products/clear', $t->lastRequest()['path']);
    }

    public function testBuildBody()
    {
        $body = $this->engine(new FakeTransport())->buildBody(
            Query::create('blue')->fields(['title', 'desc'])->filter('cat', 'shoes')->from(20)->size(10)
        );
        $this->assertSame('blue', $body['query']);
        $this->assertSame(10, $body['hitsPerPage']);
        $this->assertSame(2, $body['page']); // from 20 / size 10
        $this->assertSame(['title', 'desc'], $body['restrictSearchableAttributes']);
        $this->assertSame('cat:"shoes"', $body['filters']);
    }

    public function testParse()
    {
        $t = new FakeTransport([
            'nbHits' => 2,
            'hits' => [
                ['objectID' => 'a', 'title' => 'A'],
                ['objectID' => 'b', 'title' => 'B'],
            ],
        ]);
        $result = $this->engine($t)->search(Query::create('x'));
        $this->assertSame(2, $result->total());
        $this->assertSame(['a', 'b'], $result->ids());
        $this->assertArrayNotHasKey('objectID', $result->hits()[0]->source);
    }
}
