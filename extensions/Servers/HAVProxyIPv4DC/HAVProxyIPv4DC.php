<?php

namespace Paymenter\Extensions\Servers\HAVProxyIPv4DC;

use App\Classes\Extension\Server;
use App\Models\LocationOption;
use App\Models\Product;
use App\Models\ProductLocationOffering;
use App\Models\ProviderLocationOffering;
use App\Models\ProviderLocationTarget;
use App\Models\Server as ProviderServer;
use App\Models\Service;
use App\Services\LocationAvailabilityService;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class HAVProxyIPv4DC extends Server
{
    private const KIND = 'ipv4_dc';

    private const DEFAULT_USER_AGENT = 'HAV-Proxy-IPv4-DC-Paymenter/1.0';

    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'base_url',
                'label' => 'Base URL',
                'type' => 'text',
                'description' => 'Provider API base URL, for example https://vn-proxy-ipv4.resvn.net',
                'required' => true,
                'validation' => 'url',
            ],
            [
                'name' => 'api_key',
                'label' => 'API Key',
                'type' => 'password',
                'required' => true,
                'encrypted' => true,
            ],
            [
                'name' => 'auth_header',
                'label' => 'Auth Header',
                'type' => 'select',
                'required' => true,
                'default' => 'X-API-Key',
                'options' => [
                    'X-API-Key' => 'X-API-Key',
                    'Authorization' => 'Authorization: Bearer',
                    'X-ACCESS-CODE' => 'X-ACCESS-CODE',
                ],
            ],
            [
                'name' => 'user_agent',
                'label' => 'User Agent',
                'type' => 'text',
                'default' => self::DEFAULT_USER_AGENT,
                'required' => true,
            ],
            [
                'name' => 'http_timeout',
                'label' => 'HTTP Timeout',
                'type' => 'number',
                'default' => 15,
                'required' => true,
                'validation' => 'integer|min:1|max:120',
            ],
            [
                'name' => 'poll_interval',
                'label' => 'Poll Interval',
                'type' => 'number',
                'default' => 2,
                'required' => true,
                'validation' => 'integer|min:1|max:30',
            ],
            [
                'name' => 'poll_timeout',
                'label' => 'Poll Timeout',
                'type' => 'number',
                'default' => 90,
                'required' => true,
                'validation' => 'integer|min:5|max:300',
            ],
        ];
    }

    public function getProductConfig($values = []): array
    {
        return [
            [
                'name' => 'protocol',
                'label' => 'Protocol',
                'type' => 'select',
                'required' => true,
                'default' => 'default',
                'options' => [
                    'default' => 'HTTP + SOCKS5',
                    'socks5' => 'SOCKS5',
                    'http' => 'HTTP',
                ],
            ],
            [
                'name' => 'speed_limit_mbps',
                'label' => 'Speed Limit Mbps',
                'type' => 'number',
                'default' => null,
                'required' => false,
                'validation' => 'nullable|integer|min:0',
            ],
            [
                'name' => 'bandwidth_limit_mb',
                'label' => 'Bandwidth Limit MB',
                'type' => 'number',
                'default' => null,
                'required' => false,
                'validation' => 'nullable|integer|min:0',
            ],
        ];
    }

    public function getCheckoutConfig(Product $product): array
    {
        $options = LocationAvailabilityService::forProduct($product, ProviderLocationOffering::SERVICE_PROXY)
            ->filter(fn (ProductLocationOffering $offering) => $offering->providerLocationOffering->stock_state !== ProviderLocationOffering::STOCK_UNAVAILABLE)
            ->filter(fn (ProductLocationOffering $offering) => LocationAvailabilityService::resolveTarget($offering->providerLocationOffering) !== null)
            ->mapWithKeys(function (ProductLocationOffering $offering) {
                $location = $offering->providerLocationOffering->locationOption;
                $group = $location->primaryGroup?->name;

                return [
                    $offering->id => $group ? $group . ' / ' . $location->display_name : $location->display_name,
                ];
            })
            ->all();

        if ($options === []) {
            return [];
        }

        return [
            [
                'name' => 'product_location_offering_id',
                'label' => 'Location',
                'type' => 'select',
                'required' => true,
                'options' => $options,
            ],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $capabilities = $this->request('get', '/api/v3/capabilities');

            if (!data_get($capabilities, 'success') || !data_get($capabilities, 'data.kinds.' . self::KIND)) {
                return 'Provider does not advertise ipv4_dc capabilities.';
            }

            $this->request('get', '/api/v3/inventory/groups', [
                'kind' => self::KIND,
            ]);

            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    public function syncLocationOfferings(ProviderServer $provider): int
    {
        $groups = data_get($this->request('get', '/api/v3/inventory/groups', [
            'kind' => self::KIND,
        ]), 'data', []);
        $synced = 0;

        foreach ((array) $groups as $group) {
            $legacyId = data_get($group, 'billing_group_id');
            $externalCode = data_get($group, 'id') ?? data_get($group, 'group_id');
            if (!is_numeric($legacyId) || !$externalCode) {
                continue;
            }

            $location = LocationOption::query()->where('legacy_id', (int) $legacyId)->first();
            if (!$location) {
                continue;
            }

            $offering = ProviderLocationOffering::updateOrCreate([
                'provider_id' => $provider->id,
                'location_option_id' => $location->id,
                'service_type' => ProviderLocationOffering::SERVICE_PROXY,
            ], [
                'enabled' => true,
                'stock_state' => $this->stockStateForGroup($group),
                'last_synced_at' => now(),
            ]);

            $target = $offering->targets()
                ->where(function ($query) use ($legacyId, $externalCode) {
                    $query->where('external_location_id', (string) $legacyId)
                        ->orWhere('external_location_code', (string) $externalCode);
                })
                ->first() ?? new ProviderLocationTarget;

            $target->fill([
                'external_location_id' => (string) $legacyId,
                'external_location_code' => (string) $externalCode,
                'external_name' => (string) (data_get($group, 'name') ?? data_get($group, 'code') ?? $location->display_name),
                'priority' => 100,
                'weight' => 100,
                'raw_payload' => $group,
                'status' => ProviderLocationTarget::STATUS_ACTIVE,
            ]);
            $offering->targets()->save($target);
            $synced++;
        }

        return $synced;
    }

    public function createServer(Service $service, $settings, $properties): array
    {
        if (!empty($properties['hav_proxy_ipv4_dc_connection_uri'])) {
            throw new Exception('Proxy already exists for this service.');
        }

        $pendingOperationId = $properties['hav_proxy_ipv4_dc_create_operation_id'] ?? null;
        if ($pendingOperationId) {
            return $this->pendingResult('create', $pendingOperationId);
        }

        [$providerOffering, $locationSnapshot] = $this->resolveProviderOffering($service, $properties);
        $externalCode = $locationSnapshot['external_location_code'] ?? null;
        if (!$externalCode) {
            throw new Exception('Selected location is missing provider group mapping.');
        }

        $payload = [
            'kind' => self::KIND,
            'group_id' => $externalCode,
            'protocol' => $settings['protocol'] ?? 'default',
            'external_ref' => sprintf('paymenter-service-%s', $service->id),
        ];
        foreach (['bandwidth_limit_mb', 'speed_limit_mbps'] as $setting) {
            if (array_key_exists($setting, (array) $settings) && $settings[$setting] !== null && $settings[$setting] !== '') {
                $payload[$setting] = (int) $settings[$setting];
            }
        }

        $response = $this->send('post', '/api/v3/proxies', $payload, [
            'Idempotency-Key' => $this->idempotencyKey('create', $service),
        ]);

        if ($response->status() === 409 && data_get($response->json(), 'error.code') === 'CAPACITY_EXHAUSTED') {
            $this->recordFailedOperation($service, 'create', 'CAPACITY_EXHAUSTED', 'Selected location is out of stock.', false, $providerOffering);

            throw new V3ProviderException('Selected location is out of stock.', 409, 'CAPACITY_EXHAUSTED', false);
        }

        $this->ensureSuccessfulResponse($response, 'create proxy');
        $operationId = data_get($response->json(), 'data.operation.id');
        if (!$operationId) {
            throw new Exception('Provider did not return a create operation id.');
        }

        $proxyId = data_get($response->json(), 'data.resource.id') ?? data_get($response->json(), 'data.operation.resource_id');
        $this->storeOperation($service, 'create', $operationId, $proxyId);

        return $this->pendingResult('create', $operationId);
    }

    public function terminateServer(Service $service, $settings, $properties): array|bool
    {
        $proxyId = $properties['hav_proxy_ipv4_dc_proxy_id'] ?? null;
        if (!$proxyId) {
            return true;
        }

        $pendingOperationId = $properties['hav_proxy_ipv4_dc_delete_operation_id'] ?? null;
        if ($pendingOperationId) {
            return $this->pendingResult('delete', $pendingOperationId);
        }

        $response = $this->send('delete', '/api/v3/proxies/' . rawurlencode($proxyId), headers: [
            'Idempotency-Key' => $this->idempotencyKey('delete', $service),
        ]);
        if ($response->status() === 404) {
            $this->deleteProxyProperties($service);

            return true;
        }

        $this->ensureSuccessfulResponse($response, 'delete proxy');
        $operationId = data_get($response->json(), 'data.operation.id');
        if (!$operationId) {
            throw new Exception('Provider did not return a delete operation id.');
        }

        $this->storeOperation($service, 'delete', $operationId, $proxyId);

        return $this->pendingResult('delete', $operationId);
    }

    public function suspendServer(Service $service, $settings, $properties): array|bool
    {
        return $this->runProxyAction($service, $properties, 'stop');
    }

    public function unsuspendServer(Service $service, $settings, $properties): array|bool
    {
        return $this->runProxyAction($service, $properties, 'start');
    }

    public function pollOperation(Service $service, $settings, $properties, string $action, string $operationId): array
    {
        $operation = data_get($this->request('get', '/api/v3/operations/' . rawurlencode($operationId)), 'data', []);
        $state = data_get($operation, 'state');
        if (!in_array($state, ['succeeded', 'failed', 'timed_out', 'cancelled'], true)) {
            return $this->pendingResult($action, $operationId);
        }

        $providerOffering = null;
        if ($action === 'create') {
            [$providerOffering] = $this->resolveProviderOffering($service, $properties);
        }

        if ($state !== 'succeeded') {
            $this->recordFailedOperation(
                $service,
                $action,
                (string) (data_get($operation, 'error_code') ?: 'PROVIDER_ERROR'),
                (string) (data_get($operation, 'error_message') ?: 'Provider operation failed.'),
                (bool) data_get($operation, 'retryable', $state === 'timed_out'),
                $providerOffering,
            );

            $this->throwFailedOperation($operation, $action);
        }

        if ($action === 'create') {
            $proxyId = data_get($operation, 'resource_id') ?? ($properties['hav_proxy_ipv4_dc_proxy_id'] ?? null);
            if (!$proxyId) {
                throw new Exception('Provider operation succeeded without a proxy id.');
            }

            $proxy = data_get($operation, 'resource_snapshot');
            if (!is_array($proxy) || empty($proxy['connection_uri'])) {
                $proxy = data_get($this->request('get', '/api/v3/proxies/' . rawurlencode($proxyId)), 'data');
            }
            if (!is_array($proxy) || empty($proxy['connection_uri'])) {
                throw new Exception('Provider did not return proxy credentials.');
            }

            $this->snapshotProxy($service, $proxyId, $operationId, $proxy, sprintf('paymenter-service-%s', $service->id));
            $this->markOfferingAvailable($providerOffering);

            return $this->notificationData($proxy);
        }

        if ($action === 'delete') {
            $this->deleteProxyProperties($service);

            return [];
        }

        $this->putProperty(
            $service,
            'hav_proxy_ipv4_dc_status',
            'Proxy status',
            (string) (data_get($operation, 'desired_status') ?: ($action === 'start' ? 'running' : 'stopped')),
        );
        $this->clearPendingOperation($service, $action);

        return [];
    }

    private function runProxyAction(Service $service, array $properties, string $action): array|bool
    {
        $proxyId = $properties['hav_proxy_ipv4_dc_proxy_id'] ?? null;
        if (!$proxyId) {
            throw new Exception('Proxy has not been created.');
        }

        $pendingOperationId = $properties['hav_proxy_ipv4_dc_' . $action . '_operation_id'] ?? null;
        if ($pendingOperationId) {
            return $this->pendingResult($action, $pendingOperationId);
        }

        $response = $this->send('post', sprintf('/api/v3/proxies/%s/actions/%s', rawurlencode($proxyId), $action), headers: [
            'Idempotency-Key' => $this->idempotencyKey($action, $service),
        ]);
        $this->ensureSuccessfulResponse($response, $action . ' proxy');

        $operationId = data_get($response->json(), 'data.operation.id');
        if (!$operationId) {
            throw new Exception('Provider did not return a ' . $action . ' operation id.');
        }

        $this->storeOperation($service, $action, $operationId, $proxyId);

        return $this->pendingResult($action, $operationId);
    }

    private function resolveProductLocationOffering(Service $service, array $properties): ProductLocationOffering
    {
        $productOfferingId = $properties['product_location_offering_id'] ?? null;

        if (!$productOfferingId) {
            throw new Exception('Location is required.');
        }

        $productOffering = ProductLocationOffering::query()
            ->with(['providerLocationOffering.locationOption', 'providerLocationOffering.targets'])
            ->where('product_id', $service->product_id)
            ->where('enabled', true)
            ->whereKey($productOfferingId)
            ->first();

        if (!$productOffering) {
            throw new Exception('Selected location is not enabled for this product.');
        }

        if ($productOffering->providerLocationOffering->provider_id !== $service->product->server_id) {
            throw new Exception('Selected location belongs to a different provider.');
        }

        if ($productOffering->providerLocationOffering->service_type !== ProviderLocationOffering::SERVICE_PROXY) {
            throw new Exception('Selected location is not configured for proxy service.');
        }

        if (!$productOffering->providerLocationOffering->enabled) {
            throw new Exception('Selected provider location is disabled.');
        }

        if ($productOffering->providerLocationOffering->stock_state === ProviderLocationOffering::STOCK_UNAVAILABLE) {
            throw new Exception('Selected location is out of stock.');
        }

        return $productOffering;
    }

    private function resolveProviderOffering(Service $service, array $properties): array
    {
        $providerOfferingId = $properties['provider_location_offering_id'] ?? null;
        $externalLocationCode = $properties['external_location_code'] ?? null;

        if ($providerOfferingId && $externalLocationCode) {
            $offering = ProviderLocationOffering::query()
                ->with('locationOption')
                ->find($providerOfferingId);
            if (!$offering) {
                throw new Exception('Selected provider location no longer exists.');
            }
            if ($offering->provider_id !== $service->product->server_id) {
                throw new Exception('Selected location belongs to a different provider.');
            }
            if ($offering->service_type !== ProviderLocationOffering::SERVICE_PROXY) {
                throw new Exception('Selected location is not configured for proxy service.');
            }

            return [$offering, [
                'external_location_code' => $externalLocationCode,
                'display_name' => $properties['display_name'] ?? $offering->locationOption?->display_name,
            ]];
        }

        $productOffering = $this->resolveProductLocationOffering($service, $properties);
        $snapshot = LocationAvailabilityService::snapshotSelection($service, $productOffering->providerLocationOffering);

        return [$productOffering->providerLocationOffering, $snapshot];
    }

    private function request(string $method, string $path, array $query = []): array
    {
        $response = $this->send($method, $path, query: $query);
        $this->ensureSuccessfulResponse($response, trim($method . ' ' . $path));

        return $response->json() ?? [];
    }

    private function send(string $method, string $path, array $body = [], array $headers = [], array $query = []): Response
    {
        $url = $this->apiBaseUrl() . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $request = Http::timeout((int) ($this->config('http_timeout') ?? 15))
            ->acceptJson()
            ->withUserAgent((string) ($this->config('user_agent') ?: self::DEFAULT_USER_AGENT))
            ->withHeaders(array_merge($this->authHeaders(), $headers));

        try {
            return match (strtolower($method)) {
                'get' => $request->get($url),
                'post' => $request->post($url, $body),
                'delete' => $request->delete($url, $body),
                default => throw new Exception('Unsupported provider method: ' . $method),
            };
        } catch (ConnectionException $exception) {
            throw new V3ProviderException(
                'Provider connection failed: ' . $exception->getMessage(),
                retryable: true,
            );
        }
    }

    private function authHeaders(): array
    {
        $apiKey = (string) $this->config('api_key');

        return match ($this->config('auth_header') ?: 'X-API-Key') {
            'Authorization' => ['Authorization' => 'Bearer ' . $apiKey],
            'X-ACCESS-CODE' => ['X-ACCESS-CODE' => $apiKey],
            default => ['X-API-Key' => $apiKey],
        };
    }

    private function ensureSuccessfulResponse(Response $response, string $action): void
    {
        if ($response->successful() && data_get($response->json(), 'success', true) !== false) {
            return;
        }

        $code = data_get($response->json(), 'error.code');
        $message = data_get($response->json(), 'error.message') ?: $response->body();
        $retryable = data_get($response->json(), 'error.retryable');

        throw new V3ProviderException(
            sprintf(
                'Provider failed to %s: HTTP %s%s%s%s',
                $action,
                $response->status(),
                $code ? ' ' . $code : '',
                $retryable === null ? '' : ' retryable=' . ($retryable ? 'true' : 'false'),
                $message ? ' - ' . Str::limit($message, 300) : '',
            ),
            $response->status(),
            $code ? (string) $code : null,
            (bool) ($retryable ?? in_array($response->status(), [408, 425, 429, 500, 502, 503, 504], true)),
        );
    }

    private function throwFailedOperation(array $operation, string $action): never
    {
        $code = data_get($operation, 'error_code') ?: 'PROVIDER_ERROR';
        $message = data_get($operation, 'error_message') ?: 'Provider operation failed.';
        $retryable = data_get($operation, 'retryable');

        throw new V3ProviderException(sprintf(
            'Provider failed to %s: %s%s - %s',
            $action,
            $code,
            $retryable === null ? '' : ' retryable=' . ($retryable ? 'true' : 'false'),
            Str::limit($message, 300)
        ), providerCode: $code, retryable: (bool) $retryable, details: ['terminal' => true]);
    }

    private function snapshotProxy(Service $service, string $proxyId, string $operationId, array $proxy, string $externalRef): void
    {
        $this->putProperty($service, 'hav_proxy_ipv4_dc_proxy_id', 'Proxy ID', $proxyId);
        $this->putProperty($service, 'hav_proxy_ipv4_dc_create_operation_id', 'Create operation ID', $operationId);
        $this->putProperty($service, 'hav_proxy_ipv4_dc_external_ref', 'External reference', $externalRef);
        $this->putProperty($service, 'hav_proxy_ipv4_dc_status', 'Proxy status', (string) data_get($proxy, 'status', 'running'));
        $this->putProperty($service, 'hav_proxy_ipv4_dc_host', 'Proxy host', (string) data_get($proxy, 'host'));
        $this->putProperty($service, 'hav_proxy_ipv4_dc_outbound_ip', 'Outbound IP', (string) data_get($proxy, 'outbound_ip'));
        $this->putProperty($service, 'hav_proxy_ipv4_dc_port_socks', 'SOCKS port', (string) data_get($proxy, 'port_socks'));
        $this->putProperty($service, 'hav_proxy_ipv4_dc_port_http', 'HTTP port', (string) data_get($proxy, 'port_http'));
        $this->putProperty($service, 'hav_proxy_ipv4_dc_username', 'Proxy username', (string) data_get($proxy, 'username'));
        $this->putProperty($service, 'hav_proxy_ipv4_dc_password', 'Proxy password', (string) data_get($proxy, 'password'));
        $this->putProperty($service, 'hav_proxy_ipv4_dc_connection_uri', 'Proxy connection URI', (string) data_get($proxy, 'connection_uri'));
    }

    private function notificationData(array $proxy): array
    {
        return [
            'host' => data_get($proxy, 'host'),
            'port_socks' => data_get($proxy, 'port_socks'),
            'port_http' => data_get($proxy, 'port_http'),
            'username' => data_get($proxy, 'username'),
            'password' => data_get($proxy, 'password'),
            'connection_uri' => data_get($proxy, 'connection_uri'),
        ];
    }

    private function apiBaseUrl(): string
    {
        $baseUrl = rtrim((string) $this->config('base_url'), '/');

        return preg_replace('#/api/v3$#', '', $baseUrl) ?: $baseUrl;
    }

    private function pendingResult(string $action, string $operationId): array
    {
        return [
            'provider_operation_pending' => true,
            'provider_operation_id' => $operationId,
            'provider_poll_interval' => max(1, (int) ($this->config('poll_interval') ?? 2)),
            'provider_poll_deadline_at' => time() + max(5, (int) ($this->config('poll_timeout') ?? 90)),
            'provider_operation_action' => $action,
        ];
    }

    private function storeOperation(Service $service, string $action, string $operationId, ?string $proxyId): void
    {
        $this->putProperty(
            $service,
            'hav_proxy_ipv4_dc_' . $action . '_operation_id',
            ucfirst($action) . ' operation ID',
            $operationId,
        );
        if ($proxyId) {
            $this->putProperty($service, 'hav_proxy_ipv4_dc_proxy_id', 'Proxy ID', $proxyId);
        }
    }

    private function clearPendingOperation(Service $service, string $action): void
    {
        $service->properties()
            ->where('key', 'hav_proxy_ipv4_dc_' . $action . '_operation_id')
            ->delete();
    }

    private function recordFailedOperation(
        Service $service,
        string $action,
        string $code,
        string $message,
        bool $retryable,
        ?ProviderLocationOffering $offering = null,
    ): void {
        if ($code === 'CAPACITY_EXHAUSTED' && $offering) {
            $this->markOfferingCapacityExhausted($offering);
        }

        $this->putProperty($service, 'hav_proxy_ipv4_dc_last_error_code', 'Last provider error code', $code);
        $this->putProperty($service, 'hav_proxy_ipv4_dc_last_error_message', 'Last provider error message', Str::limit($message, 1000));
        $this->putProperty($service, 'hav_proxy_ipv4_dc_last_error_retryable', 'Last provider error retryable', $retryable ? '1' : '0');

        $this->clearPendingOperation($service, $action);
        $service->properties()->where('key', 'hav_proxy_ipv4_dc_' . $action . '_idempotency_key')->delete();

        if ($action === 'create') {
            $service->properties()->whereIn('key', [
                'hav_proxy_ipv4_dc_proxy_id',
                'hav_proxy_ipv4_dc_create_operation_id',
            ])->delete();
            $attempt = (int) $service->properties()->where('key', 'hav_proxy_ipv4_dc_create_attempt')->value('value');
            $this->putProperty($service, 'hav_proxy_ipv4_dc_create_attempt', 'Create attempt', (string) max(2, $attempt + 1));
        }
    }

    private function idempotencyKey(string $action, Service $service): string
    {
        $key = 'hav_proxy_ipv4_dc_' . $action . '_idempotency_key';
        $existing = $service->properties()->where('key', $key)->value('value');
        if ($existing) {
            return $existing;
        }

        $attemptKey = 'hav_proxy_ipv4_dc_' . $action . '_attempt';
        $attempt = max(1, (int) ($service->properties()->where('key', $attemptKey)->value('value') ?: 1));
        $value = sprintf('paymenter:hav-proxy-ipv4-dc:%s:service:%s:attempt:%s', $action, $service->id, $attempt);
        $this->putProperty($service, $attemptKey, ucfirst($action) . ' attempt', (string) $attempt);
        $this->putProperty($service, $key, ucfirst($action) . ' idempotency key', $value);

        return $value;
    }

    private function putProperty(Service $service, string $key, string $name, ?string $value): void
    {
        $service->properties()->updateOrCreate([
            'key' => $key,
        ], [
            'name' => $name,
            'value' => $value ?? '',
        ]);
    }

    private function deleteProxyProperties(Service $service): void
    {
        $service->properties()
            ->where('key', 'like', 'hav_proxy_ipv4_dc_%')
            ->delete();
    }

    private function markOfferingCapacityExhausted(ProviderLocationOffering $offering): void
    {
        $offering->forceFill([
            'stock_state' => ProviderLocationOffering::STOCK_UNAVAILABLE,
            'last_synced_at' => now(),
        ])->save();
    }

    private function markOfferingAvailable(ProviderLocationOffering $offering): void
    {
        $offering->forceFill([
            'stock_state' => ProviderLocationOffering::STOCK_AVAILABLE,
            'last_synced_at' => now(),
        ])->save();
    }

    private function stockStateForGroup(array $group): string
    {
        $sellState = strtolower((string) data_get($group, 'sell_state', ''));
        $units = data_get($group, 'allocatable_units');

        if ($sellState === 'exhausted' || ($units !== null && (int) $units <= 0)) {
            return ProviderLocationOffering::STOCK_UNAVAILABLE;
        }

        if (in_array($sellState, ['limited', 'restricted'], true)) {
            return ProviderLocationOffering::STOCK_LIMITED;
        }

        return ProviderLocationOffering::STOCK_AVAILABLE;
    }
}
