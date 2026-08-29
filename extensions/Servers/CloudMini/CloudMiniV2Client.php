<?php

namespace Paymenter\Extensions\Servers\CloudMini;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class CloudMiniV2Client
{
    private const MAX_LIST_PAGES = 100;

    private string $baseUrl;

    private string $token;

    private int $timeout;

    private int $connectTimeout;

    private int $maxGetAttempts;

    private int $getRetryDelayMs;

    public function __construct(
        string $baseUrl,
        string $token,
        int $timeout = 30,
        int $connectTimeout = 10,
        int $maxGetAttempts = 3,
        int $getRetryDelayMs = 200
    ) {
        $base = rtrim(trim($baseUrl), '/');
        if ($base === '') {
            throw new InvalidArgumentException('baseUrl must be a non-empty absolute https URL.');
        }

        $parts = parse_url($base);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('baseUrl must be an absolute https URL with a host.');
        }
        if (strtolower($parts['scheme']) !== 'https') {
            throw new InvalidArgumentException('baseUrl must use the https scheme.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('baseUrl must not contain user or password information.');
        }
        if (isset($parts['query'])) {
            throw new InvalidArgumentException('baseUrl must not contain a query string.');
        }
        if (isset($parts['fragment'])) {
            throw new InvalidArgumentException('baseUrl must not contain a fragment.');
        }

        $token = trim($token);
        if ($token === '' || strpbrk($token, "\r\n") !== false) {
            throw new InvalidArgumentException('token must be non-empty and must not contain CR or LF characters.');
        }

        if ($timeout <= 0) {
            throw new InvalidArgumentException('timeout must be a positive number of seconds.');
        }

        if ($connectTimeout <= 0) {
            throw new InvalidArgumentException('connectTimeout must be a positive number of seconds.');
        }

        if ($maxGetAttempts <= 0) {
            throw new InvalidArgumentException('maxGetAttempts must be a positive integer.');
        }

        if ($getRetryDelayMs < 0) {
            throw new InvalidArgumentException('getRetryDelayMs must be a non-negative number of milliseconds.');
        }

        $this->baseUrl = $base;
        $this->token = $token;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->maxGetAttempts = $maxGetAttempts;
        $this->getRetryDelayMs = $getRetryDelayMs;
    }

    public function orderConfig(?string $type = null): mixed
    {
        $query = ($type === null || $type === '') ? [] : ['type' => $type];

        return $this->get('/order_config', $query, false);
    }

    public function account(): mixed
    {
        return $this->get('/account');
    }

    public function findOrder(string|int $id): mixed
    {
        return $this->get('/order', ['id' => $id]);
    }

    public function createOrder(array $payload): mixed
    {
        return $this->post('/order', $payload);
    }

    public function vps(array $query = []): array
    {
        return $this->collectList('/vps', $query);
    }

    public function proxies(array $query = []): array
    {
        return $this->collectList('/proxy', $query);
    }

    public function availableActions(): mixed
    {
        return $this->get('/action');
    }

    public function performAction(array $payload): mixed
    {
        return $this->post('/action', $payload);
    }

    public function createResidentialOrder(array $payload): mixed
    {
        return $this->post('/residential/order', $payload);
    }

    public function residentialList(array $query = []): array
    {
        return $this->collectList('/residential/list', $query);
    }

    private function pending(bool $authenticated): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withoutRedirecting();

        if ($authenticated) {
            $request = $request->withHeaders([
                'Authorization' => 'Token ' . $this->token,
            ]);
        }

        return $request;
    }

    private function parseEnvelope(Response $response): ?array
    {
        $decoded = json_decode($response->body(), true);

        if (!is_array($decoded) || !array_key_exists('error', $decoded) || !array_key_exists('msg', $decoded) || !array_key_exists('data', $decoded)) {
            return null;
        }

        if (!is_bool($decoded['error']) || !is_string($decoded['msg'])) {
            return null;
        }

        return $decoded;
    }

    private function sleepBetweenAttempts(): void
    {
        if ($this->getRetryDelayMs > 0) {
            usleep($this->getRetryDelayMs * 1000);
        }
    }

    private function message(string $text, string $method, string $path, ?int $status = null, ?int $attempts = null): string
    {
        $message = $text . ' [method: ' . strtoupper($method) . ', path: ' . $path;

        if ($status !== null) {
            $message .= ', http_status: ' . $status;
        }

        if ($attempts !== null) {
            $message .= ', attempts: ' . $attempts;
        }

        return $message . ']';
    }

    private function exception(string $method, string $path, ?int $status, string $reason, bool $ambiguous, bool $retryable, ?int $attempts = null): CloudMiniApiException
    {
        $safeContext = ['reason' => $reason];

        if ($status !== null) {
            $safeContext['http_status'] = $status;
        }

        if ($attempts !== null) {
            $safeContext['attempts'] = $attempts;
        }

        return new CloudMiniApiException(
            $this->message('CloudMini API request failed.', $method, $path, $status, $attempts),
            $status,
            $method,
            $path,
            $ambiguous,
            $retryable,
            $safeContext,
        );
    }

    private function get(string $path, array $query = [], bool $authenticated = true): mixed
    {
        $url = $this->baseUrl . $path;

        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&');
        }

        for ($attempt = 1; $attempt <= $this->maxGetAttempts; $attempt++) {
            try {
                $response = $this->pending($authenticated)->get($url);
            } catch (ConnectionException) {
                if ($attempt < $this->maxGetAttempts) {
                    $this->sleepBetweenAttempts();

                    continue;
                }

                throw $this->exception('GET', $path, null, 'connection', false, true, $attempt);
            }

            $status = $response->status();

            if ($status >= 200 && $status < 300) {
                $envelope = $this->parseEnvelope($response);

                if ($envelope === null) {
                    if ($attempt < $this->maxGetAttempts) {
                        $this->sleepBetweenAttempts();

                        continue;
                    }

                    throw $this->exception('GET', $path, $status, 'malformed', false, true, $attempt);
                }

                if ($envelope['error'] === true) {
                    throw $this->exception('GET', $path, $status, 'api_error', false, false, $attempt);
                }

                return $envelope['data'];
            }

            if ($status >= 300 && $status < 400) {
                throw $this->exception('GET', $path, $status, 'redirect', false, false);
            }

            if ($status === 429 || $status >= 500) {
                if ($attempt < $this->maxGetAttempts) {
                    $this->sleepBetweenAttempts();

                    continue;
                }

                $reason = $status === 429 ? 'rate_limited' : 'http_error';

                throw $this->exception('GET', $path, $status, $reason, false, true, $attempt);
            }

            throw $this->exception('GET', $path, $status, 'http_error', false, false);
        }
    }

    private function post(string $path, array $payload): mixed
    {
        $url = $this->baseUrl . $path;

        try {
            $response = $this->pending(true)->asForm()->post($url, $payload);
        } catch (ConnectionException) {
            throw $this->exception('POST', $path, null, 'connection', true, false);
        }

        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            $envelope = $this->parseEnvelope($response);

            if ($envelope === null) {
                throw $this->exception('POST', $path, $status, 'malformed', true, false);
            }

            if ($envelope['error'] === true) {
                throw $this->exception('POST', $path, $status, 'api_error', false, false);
            }

            return $envelope['data'];
        }

        if ($status >= 300 && $status < 400) {
            throw $this->exception('POST', $path, $status, 'redirect', true, false);
        }

        if ($status === 429) {
            throw $this->exception('POST', $path, $status, 'rate_limited', false, true);
        }

        if ($status >= 500) {
            throw $this->exception('POST', $path, $status, 'http_error', true, false);
        }

        throw $this->exception('POST', $path, $status, 'http_error', false, false);
    }

    private function collectList(string $path, array $query): array
    {
        $page = (isset($query['page']) && is_int($query['page']) && $query['page'] > 0) ? $query['page'] : 1;

        $items = [];

        while (true) {
            $query['page'] = $page;

            $data = $this->get($path, $query);

            if (is_array($data) && array_is_list($data)) {
                return $data;
            }

            if (
                !is_array($data)
                || !array_key_exists('page', $data)
                || !array_key_exists('has_more', $data)
                || !array_key_exists('items', $data)
                || !is_int($data['page'])
                || $data['page'] !== $page
                || !is_bool($data['has_more'])
                || !is_array($data['items'])
            ) {
                throw $this->exception('GET', $path, null, 'unexpected_payload', false, false);
            }

            $items = array_merge($items, $data['items']);

            if ($data['has_more'] === false) {
                return $items;
            }

            if ($page >= self::MAX_LIST_PAGES) {
                throw new CloudMiniApiException(
                    $this->message('CloudMini API request failed.', 'GET', $path),
                    null,
                    'GET',
                    $path,
                    false,
                    false,
                    ['reason' => 'page_limit_reached', 'max_pages' => self::MAX_LIST_PAGES],
                );
            }

            $page++;
        }
    }
}
