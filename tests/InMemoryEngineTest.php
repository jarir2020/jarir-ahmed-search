<?php

namespace JarirAhmed\Search\Tests;

use JarirAhmed\Search\Engines\InMemoryEngine;
use JarirAhmed\Search\Query;
use PHPUnit\Framework\TestCase;

class InMemoryEngineTest extends TestCase
{
    private function seed(): InMemoryEngine
    {
        $e = new InMemoryEngine();
        $e->bulk([
            '1' => ['title' => 'Blue Running Shoes', 'category' => 'footwear', 'price' => 80],
            '2' => ['title' => 'Red Leather Boots', 'category' => 'footwear', 'price' => 150],
            '3' => ['title' => 'Blue Denim Jacket', 'category' => 'clothing', 'price' => 120],
            '4' => ['title' => 'Running Socks', 'category' => 'clothing', 'price' => 10],
        ]);
        return $e;
    }

    public function testSimpleMatch()
    {
        $result = $this->seed()->search(Query::create('blue'));
        $this->assertSame(['1', '3'], $result->ids());
        $this->assertSame(2, $result->total());
    }

    public function testBoostAffectsRanking()
    {
        $e = new InMemoryEngine();
        $e->index('a', ['title' => 'mango', 'body' => 'nothing here']);
        $e->index('b', ['title' => 'nothing', 'body' => 'mango mango']);

        // Boost title heavily: 'a' (1x in title) should outrank 'b' (2x in body).
        $q = Query::create('mango')->fields(['title' => 5.0, 'body' => 1.0]);
        $this->assertSame(['a', 'b'], $e->search($q)->ids());
    }

    public function testFuzzyMatch()
    {
        $e = $this->seed();
        // "runing" (typo) matches "running" with edit distance 1.
        $exact = $e->search(Query::create('runing'));
        $this->assertTrue($exact->isEmpty());

        $fuzzy = $e->search(Query::create('runing')->fuzziness(1));
        $this->assertContains('1', $fuzzy->ids());
        $this->assertContains('4', $fuzzy->ids());
    }

    public function testSubstringPartialMatch()
    {
        $result = $this->seed()->search(Query::create('leath')); // partial of "leather"
        $this->assertSame(['2'], $result->ids());
    }

    public function testFilters()
    {
        $result = $this->seed()->search(
            Query::create('blue')->filter('category', 'clothing')
        );
        $this->assertSame(['3'], $result->ids());
    }

    public function testSortByField()
    {
        $result = $this->seed()->search(
            Query::create('')->filter('category', 'footwear')->sortBy('price', 'desc')
        );
        $this->assertSame(['2', '1'], $result->ids()); // 150 before 80
    }

    public function testEmptyTermMatchesAll()
    {
        $this->assertSame(4, $this->seed()->search(Query::create(''))->total());
    }

    public function testPagination()
    {
        $e = new InMemoryEngine();
        for ($i = 1; $i <= 10; $i++) {
            $e->index((string) $i, ['title' => 'item', 'n' => $i]);
        }
        $page = $e->search(Query::create('item')->sortBy('n', 'asc')->from(3)->size(2));
        $this->assertSame(['4', '5'], $page->ids());
        $this->assertSame(10, $page->total());
    }

    public function testDeleteAndClear()
    {
        $e = $this->seed();
        $this->assertTrue($e->delete('1'));
        $this->assertFalse($e->delete('1'));
        $this->assertSame(['3'], $e->search(Query::create('blue'))->ids());

        $e->clear();
        $this->assertSame(0, $e->search(Query::create(''))->total());
    }

    public function testDocumentsAndArrayFieldSearch()
    {
        $e = new InMemoryEngine();
        $e->index('x', ['title' => 'post', 'tags' => ['php', 'search', 'elastic']]);
        $hit = $e->search(Query::create('elastic'))->documents();
        $this->assertSame('post', $hit[0]['title']);
    }

    public function testFilterOnArrayFieldMembership()
    {
        $e = new InMemoryEngine();
        $e->index('x', ['title' => 'post', 'tags' => ['php', 'search']]);
        $e->index('y', ['title' => 'post', 'tags' => ['js']]);
        $this->assertSame(['x'], $e->search(Query::create('post')->filter('tags', 'search'))->ids());
    }
}
