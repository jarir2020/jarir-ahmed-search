<?php

namespace JarirAhmed\Search\Transport;

/**
 * Minimal HTTP transport used by {@see \JarirAhmed\Search\Engines\ElasticsearchEngine}.
 * Abstracted so the engine can be unit-tested with a fake transport (no network).
 */
interface TransportInterface
{
    /**
     * Perform a request and return the decoded JSON response body.
     *
     * @param string                   $method HTTP method (GET, POST, PUT, DELETE).
     * @param string                   $path   Path beginning with "/", relative to the base URL.
     * @param array<string,mixed>|null $body   Request body, JSON-encoded by the transport.
     * @return array<string,mixed> Decoded JSON response.
     *
     * @throws TransportException on connection failure or a non-2xx response.
     */
    public function request(string $method, string $path, ?array $body = null): array;
}
