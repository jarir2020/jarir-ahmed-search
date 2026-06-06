<?php

namespace JarirAhmed\Search\Tests;

use JarirAhmed\Search\Transport\TransportInterface;

/**
 * Test double: records every request and returns canned responses, so the
 * Elasticsearch engine can be tested without a server.
 */
class FakeTransport implements TransportInterface
{
    /** @var array<int,array{method:string,path:string,body:array<string,mixed>|null}> */
    public $requests = [];
    /** @var array<string,mixed> */
    private $response;

    /** @param array<string,mixed> $response */
    public function __construct(array $response = [])
    {
        $this->response = $response;
    }

    /** @param array<string,mixed> $response */
    public function setResponse(array $response): void
    {
        $this->response = $response;
    }

    public function request(string $method, string $path, ?array $body = null): array
    {
        $this->requests[] = ['method' => $method, 'path' => $path, 'body' => $body];
        return $this->response;
    }

    /** @return array{method:string,path:string,body:array<string,mixed>|null} */
    public function lastRequest(): array
    {
        return $this->requests[count($this->requests) - 1];
    }
}
