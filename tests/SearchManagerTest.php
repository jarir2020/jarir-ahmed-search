<?php

namespace JarirAhmed\Search\Tests;

use JarirAhmed\Search\Engines\InMemoryEngine;
use JarirAhmed\Search\Query;
use JarirAhmed\Search\SearchManager;
use PHPUnit\Framework\TestCase;

class SearchManagerTest extends TestCase
{
    private function manager(): SearchManager
    {
        $m = new SearchManager(new InMemoryEngine());
        $m->bulk([
            '1' => ['title' => 'Blue Shoes'],
            '2' => ['title' => 'Red Hat'],
        ]);
        return $m;
    }

    public function testSearchWithString()
    {
        $this->assertSame(['1'], $this->manager()->search('blue')->ids());
    }

    public function testSearchWithQueryObject()
    {
        $this->assertSame(['2'], $this->manager()->search(Query::create('hat'))->ids());
    }

    public function testInvalidQueryTypeThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->search(123);
    }

    public function testDelegatesDeleteAndClear()
    {
        $m = $this->manager();
        $this->assertTrue($m->delete('1'));
        $m->clear();
        $this->assertSame(0, $m->search('')->total());
    }

    public function testFluentChaining()
    {
        $m = new SearchManager(new InMemoryEngine());
        $result = $m->index('a', ['title' => 'one'])->index('b', ['title' => 'two'])->search('one');
        $this->assertSame(['a'], $result->ids());
    }
}
