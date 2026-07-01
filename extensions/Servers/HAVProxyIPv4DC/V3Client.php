<?php

namespace Paymenter\Extensions\Servers\HAVProxyIPv4DC;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class V3Client
{
    private string $baseUrl;

    private string $apiToken;

    private string $authHeader;

    private string $userAgent;

    private int $timeout;

    public function __construct(array $profile, string $defaultAuthHeader = 'Authorization', string $userAgent = 'HAV-Proxy-IPv4-DC-Paymenter/1.0', int $timeout = 15)
    {
        $this->baseUrl = $this->normalizeBaseUrl((string) ($profile['base_url'] ?? ''));
        $this->apiToken = (string) ($profile['api_token'] ?? $profile['token'] ?? $profile['access_code'] ?? '');
        $this->authHeader = (string) ($profile['auth_header'] ?? $defaultAuthHeader);
        $this->userAgent = $userAgent;
        $this->timeout = max(1, $timeout);
    }

    public function capabilities(): array
    {
        return $this->request('get', '/capabilities');
    }

    public function groups(): array
    {
        return $this->request('get', '/inventory/groups', [
            'kind' => 'ipv4_dc',
        ]);
    }

    public function nodes(?string $groupId = null): array
    {
        $query = ['kind' => 'ipv4_dc'];

        if ($groupId) {
            $query['group_id'] = $groupId;
        }

        return $this->request('get', '/inventory/nodes', $query);
    }

    public function createProxy(array $payload, string $idempotencyKey): array
    {
        return $this->request('post', '/proxies', $payload, $idempotencyKey);
    }

    public function getProxy(string $proxyId): array
    {
        return $this->request('get', '/proxies/' . rawurlencode($proxyId));
    }

    public function getOperation(string $operationId): array
    {
        return $this->request('get', '/operations/' . rawurlencode($operationId));
    }

    public function proxyAction(string $proxyId, string $action, string $idempotencyKey): array
    {
        return $this->request('post', '/proxies/' . rawurlencode($proxyId) . '/actions/' . rawurlencode($action), [], $idempotencyKey);
    }

    public function deleteProxy(string $proxyId, string $idempotencyKey): array
    {
        return $this->request('delete', '/proxies/' . rawurlencode($proxyId), [], $idempotencyKey);
    }

    public function request(string $method, string $path, array $payload = [], ?string $idempotencyKey = null): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
            ...$this->authHeaders(),
        ];

        if ($idempotencyKey) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        try {
            $pending = Http::timeout($this->timeout)
                ->asJson()
                ->withHeaders($headers);

            $response = match (strtolower($method)) {
                'get' => $pending->get($url, $payload),
                'post' => $pending->post($url, $payload),
                'delete' => $pending->delete($url, $payload),
                default => throw new V3ProviderException('Unsupported HAV Proxy API method: ' . $method),
            };
        } catch (ConnectionException $exception) {
            throw new V3ProviderException(
                sprintf('HAV Proxy API %s %s connection failed: %s', strtoupper($method), $url, $exception->getMessage()),
                retryable: true,
            );
        }

        if (!$response->successful()) {
            throw V3ProviderException::fromResponse($response, $method, $url);
        }

        return $response->json() ?? [];
    }

    private function authHeaders(): array
    {
        $header = in_array($this->authHeader, ['Authorization', 'X-API-Key', 'X-ACCESS-CODE'], true)
            ? $this->authHeader
            : 'Authorization';

        if ($header === 'Authorization') {
            return [$header => str_starts_with($this->apiToken, 'Bearer ') ? $this->apiToken : 'Bearer ' . $this->apiToken];
        }

        return [$header => $this->apiToken];
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $baseUrl = preg_replace('#/api/v3$#', '', $baseUrl) ?: $baseUrl;

        return $baseUrl . '/api/v3';
    }
}
