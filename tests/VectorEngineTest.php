<?php

namespace JarirAhmed\Search\Tests;

use JarirAhmed\Search\Engines\VectorEngine;
use JarirAhmed\Search\Query;
use PHPUnit\Framework\TestCase;

class VectorEngineTest extends TestCase
{
    private function engine(): VectorEngine
    {
        $e = new VectorEngine(); // field "embedding"
        $e->bulk([
            'cat'    => ['label' => 'cat',    'type' => 'animal', 'embedding' => [1.0, 0.0, 0.0]],
            'dog'    => ['label' => 'dog',    'type' => 'animal', 'embedding' => [0.9, 0.1, 0.0]],
            'car'    => ['label' => 'car',    'type' => 'vehicle', 'embedding' => [0.0, 0.0, 1.0]],
        ]);
        return $e;
    }

    public function testRanksByCosineSimilarity()
    {
        // Query close to "cat" (and "dog"), far from "car".
        $result = $this->engine()->search(Query::create('')->vector([1.0, 0.0, 0.0]));
        $this->assertSame(['cat', 'dog', 'car'], $result->ids());
        $this->assertEqualsWithDelta(1.0, $result->hits()[0]->score, 1e-9);
    }

    public function testFiltersApply()
    {
        $result = $this->engine()->search(
            Query::create('')->vector([0.0, 0.0, 1.0])->filter('type', 'vehicle')
        );
        $this->assertSame(['car'], $result->ids());
    }

    public function testMissingQueryVectorThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->engine()->search(Query::create('anything'));
    }

    public function testDimensionMismatchIsSkipped()
    {
        $e = new VectorEngine();
        $e->index('ok', ['embedding' => [1.0, 2.0]]);
        $e->index('bad', ['embedding' => [1.0, 2.0, 3.0]]); // wrong dimensionality
        $result = $e->search(Query::create('')->vector([1.0, 2.0]));
        $this->assertSame(['ok'], $result->ids());
    }

    public function testPagination()
    {
        $result = $this->engine()->search(Query::create('')->vector([1.0, 0.0, 0.0])->from(1)->size(1));
        $this->assertSame(['dog'], $result->ids());
        $this->assertSame(3, $result->total());
    }
}
