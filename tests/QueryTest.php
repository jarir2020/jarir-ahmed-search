<?php

namespace JarirAhmed\Search\Tests;

use JarirAhmed\Search\Query;
use PHPUnit\Framework\TestCase;

class QueryTest extends TestCase
{
    public function testFieldListNormalizedToBoosts()
    {
        $q = Query::create('x')->fields(['title', 'body']);
        $this->assertSame(['title' => 1.0, 'body' => 1.0], $q->getFields());
    }

    public function testFieldBoostsPreserved()
    {
        $q = Query::create('x')->fields(['title' => 2.5, 'body' => 1]);
        $this->assertSame(['title' => 2.5, 'body' => 1.0], $q->getFields());
    }

    public function testNegativeValuesClamped()
    {
        $q = Query::create('x')->fuzziness(-3)->size(-1)->from(-5);
        $this->assertSame(0, $q->getFuzziness());
        $this->assertSame(0, $q->getSize());
        $this->assertSame(0, $q->getFrom());
    }

    public function testSortDirectionNormalized()
    {
        $this->assertSame(['price', 'desc'], Query::create('x')->sortBy('price', 'DESC')->getSort());
        $this->assertSame(['price', 'asc'], Query::create('x')->sortBy('price', 'whatever')->getSort());
    }

    public function testDefaults()
    {
        $q = Query::create('hello');
        $this->assertSame('hello', $q->getTerm());
        $this->assertSame(10, $q->getSize());
        $this->assertSame(0, $q->getFrom());
        $this->assertNull($q->getSort());
        $this->assertSame([], $q->getFields());
    }
}
