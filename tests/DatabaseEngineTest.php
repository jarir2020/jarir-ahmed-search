<?php

namespace JarirAhmed\Search\Tests;

use JarirAhmed\Search\Engines\DatabaseEngine;
use JarirAhmed\Search\Query;
use PDO;
use PHPUnit\Framework\TestCase;

class DatabaseEngineTest extends TestCase
{
    private function engine(): DatabaseEngine
    {
        $engine = new DatabaseEngine(new PDO('sqlite::memory:'));
        $engine->bulk([
            '1' => ['title' => 'Blue Running Shoes', 'category' => 'footwear', 'price' => 80],
            '2' => ['title' => 'Red Leather Boots', 'category' => 'footwear', 'price' => 150],
            '3' => ['title' => 'Blue Denim Jacket', 'category' => 'clothing', 'price' => 120],
        ]);
        return $engine;
    }

    public function testRejectsBadTableName()
    {
        $this->expectException(\InvalidArgumentException::class);
        new DatabaseEngine(new PDO('sqlite::memory:'), 'bad table; DROP');
    }

    public function testMatch()
    {
        $result = $this->engine()->search(Query::create('blue'));
        $this->assertSame(['1', '3'], $result->ids());
        $this->assertSame(2, $result->total());
    }

    public function testMultiTokenAnd()
    {
        $result = $this->engine()->search(Query::create('blue denim'));
        $this->assertSame(['3'], $result->ids());
    }

    public function testFilter()
    {
        $result = $this->engine()->search(Query::create('blue')->filter('category', 'clothing'));
        $this->assertSame(['3'], $result->ids());
    }

    public function testSortAndEmptyTerm()
    {
        $result = $this->engine()->search(Query::create('')->sortBy('price', 'desc'));
        $this->assertSame(['2', '3', '1'], $result->ids());
        $this->assertSame(3, $result->total());
    }

    public function testPagination()
    {
        $result = $this->engine()->search(Query::create('')->sortBy('price', 'asc')->from(1)->size(1));
        $this->assertSame(['3'], $result->ids());
        $this->assertSame(3, $result->total());
    }

    public function testDeleteAndClear()
    {
        $e = $this->engine();
        $this->assertTrue($e->delete('1'));
        $this->assertFalse($e->delete('1'));
        $this->assertSame(['3'], $e->search(Query::create('blue'))->ids());
        $e->clear();
        $this->assertSame(0, $e->search(Query::create(''))->total());
    }

    public function testReindexReplacesDocument()
    {
        $e = $this->engine();
        $e->index('1', ['title' => 'Green Sandals', 'category' => 'footwear']);
        $this->assertTrue($e->search(Query::create('blue'))->total() === 1); // only doc 3 now
        $this->assertSame(['1'], $e->search(Query::create('sandals'))->ids());
    }
}
