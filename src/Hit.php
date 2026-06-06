<?php

namespace JarirAhmed\Search;

/** A single search result: the document id, its relevance score, and the source document. */
class Hit
{
    /** @var string */
    public $id;
    /** @var float */
    public $score;
    /** @var array<string,mixed> */
    public $source;

    /** @param array<string,mixed> $source */
    public function __construct(string $id, float $score, array $source)
    {
        $this->id = $id;
        $this->score = $score;
        $this->source = $source;
    }
}
