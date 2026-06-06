<?php

namespace JarirAhmed\Search\Transport;

/**
 * cURL-based transport for talking to an Elasticsearch / OpenSearch HTTP endpoint.
 *
 *   $transport = new CurlTransport('http://localhost:9200', [
 *       'username' => 'elastic',
 *       'password' => 'changeme',
 *       // or 'apiKey' => 'base64idandkey',
 *       'timeout'  => 5,
 *   ]);
 */
class CurlTransport implements TransportInterface
{
    /** @var string */
    private $baseUrl;
    /** @var array<string,mixed> */
    private $options;

    /** @param array<string,mixed> $options username, password, apiKey, timeout, headers */
    public function __construct(string $baseUrl, array $options = [])
    {
        if (!function_exists('curl_init')) {
            throw new TransportException('CurlTransport requires the cURL extension.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->options = $options;
    }

    public function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $ch = curl_init($url);

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if (!empty($this->options['apiKey'])) {
            $headers[] = 'Authorization: ApiKey ' . $this->options['apiKey'];
        }
        foreach ((array) ($this->options['headers'] ?? []) as $h) {
            $headers[] = $h;
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) ($this->options['timeout'] ?? 10));

        if (isset($this->options['username'])) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->options['username'] . ':' . ($this->options['password'] ?? ''));
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new TransportException("HTTP request failed: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        if ($status < 200 || $status >= 300) {
            $reason = $decoded['error']['reason'] ?? $decoded['result'] ?? 'unexpected status';
            throw new TransportException("Elasticsearch returned HTTP {$status}: {$reason}");
        }

        return $decoded;
    }
}
