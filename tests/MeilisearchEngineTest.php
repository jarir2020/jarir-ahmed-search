<?php

namespace JarirAhmed\Search\Tests;

use JarirAhmed\Search\Engines\MeilisearchEngine;
use JarirAhmed\Search\Query;
use PHPUnit\Framework\TestCase;

class MeilisearchEngineTest extends TestCase
{
    private function engine(FakeTransport $t): MeilisearchEngine
    {
        return new MeilisearchEngine($t, 'products');
    }

    public function testIndexAddsPrimaryKeyAndPostsArray()
    {
        $t = new FakeTransport([]);
        $this->engine($t)->index('7', ['title' => 'Shoe']);
        $req = $t->lastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/indexes/products/documents', $req['path']);
        $this->assertSame([['title' => 'Shoe', 'id' => '7']], $req['body']);
    }

    public function testDelete()
    {
        $t = new FakeTransport([]);
        $this->assertTrue($this->engine($t)->delete('7'));
        $this->assertSame('DELETE', $t->lastRequest()['method']);
        $this->assertSame('/indexes/products/documents/7', $t->lastRequest()['path']);
    }

    public function testBuildBody()
    {
        $body = $this->engine(new FakeTransport())->buildBody(
            Query::create('blue')->fields(['title'])->filter('cat', 'shoes')->from(5)->size(15)->sortBy('price', 'asc')
        );
        $this->assertSame('blue', $body['q']);
        $this->assertSame(5, $body['offset']);
        $this->assertSame(15, $body['limit']);
        $this->assertSame(['title'], $body['attributesToSearchOn']);
        $this->assertSame(['cat = "shoes"'], $body['filter']);
        $this->assertSame(['price:asc'], $body['sort']);
    }

    public function testParse()
    {
        $t = new FakeTransport([
            'estimatedTotalHits' => 2,
            'hits' => [
                ['id' => 'a', 'title' => 'A'],
                ['id' => 'b', 'title' => 'B'],
            ],
        ]);
        $result = $this->engine($t)->search(Query::create('x'));
        $this->assertSame(2, $result->total());
        $this->assertSame(['a', 'b'], $result->ids());
    }
}
