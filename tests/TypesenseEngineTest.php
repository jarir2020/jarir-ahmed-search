<?php

namespace JarirAhmed\Search\Tests;

use JarirAhmed\Search\Engines\TypesenseEngine;
use JarirAhmed\Search\Query;
use PHPUnit\Framework\TestCase;

class TypesenseEngineTest extends TestCase
{
    public function testIndexAddsIdAndPosts()
    {
        $t = new FakeTransport([]);
        (new TypesenseEngine($t, 'products'))->index('9', ['title' => 'Shoe']);
        $req = $t->lastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/collections/products/documents', $req['path']);
        $this->assertSame(['title' => 'Shoe', 'id' => '9'], $req['body']);
    }

    public function testBuildPathFromQueryFields()
    {
        $engine = new TypesenseEngine(new FakeTransport(), 'products');
        $path = $engine->buildPath(
            Query::create('blue')->fields(['title', 'desc'])->filter('cat', 'shoes')->from(20)->size(10)->sortBy('price', 'desc')
        );
        $this->assertStringContainsString('q=blue', $path);
        $this->assertStringContainsString('query_by=' . urlencode('title,desc'), $path);
        $this->assertStringContainsString('filter_by=' . urlencode('cat:=shoes'), $path);
        $this->assertStringContainsString('sort_by=' . urlencode('price:desc'), $path);
        $this->assertStringContainsString('per_page=10', $path);
        $this->assertStringContainsString('page=3', $path); // from 20 / size 10 + 1
    }

    public function testDefaultQueryByUsedWhenNoFields()
    {
        $engine = new TypesenseEngine(new FakeTransport(), 'products', ['title', 'body']);
        $this->assertStringContainsString('query_by=' . urlencode('title,body'), $engine->buildPath(Query::create('x')));
    }

    public function testMissingQueryByThrows()
    {
        $engine = new TypesenseEngine(new FakeTransport(), 'products');
        $this->expectException(\InvalidArgumentException::class);
        $engine->buildPath(Query::create('x'));
    }

    public function testParse()
    {
        $t = new FakeTransport([
            'found' => 2,
            'hits' => [
                ['document' => ['id' => 'a', 'title' => 'A'], 'text_match' => 130],
                ['document' => ['id' => 'b', 'title' => 'B'], 'text_match' => 90],
            ],
        ]);
        $result = (new TypesenseEngine($t, 'products', ['title']))->search(Query::create('a'));
        $this->assertSame(2, $result->total());
        $this->assertSame(['a', 'b'], $result->ids());
        $this->assertSame(130.0, $result->hits()[0]->score);
    }
}
