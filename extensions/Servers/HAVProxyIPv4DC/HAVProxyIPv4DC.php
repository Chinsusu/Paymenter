<?php

namespace Paymenter\Extensions\Servers\HAVProxyIPv4DC;

use App\Classes\Extension\Server;
use App\Models\LocationOption;
use App\Models\Product;
use App\Models\ProductLocationOffering;
use App\Models\ProviderLocationOffering;
use App\Models\ProviderLocationTarget;
use App\Models\Server as ServerModel;
use App\Models\Service;
use App\Services\LocationAvailabilityService;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class HAVProxyIPv4DC extends Server
{
    private const KIND = 'ipv4_dc';

    private const SERVICE_TYPE = ProviderLocationOffering::SERVICE_PROXY;

    private const PROP_PRODUCT_LOCATION_OFFERING_ID = 'product_location_offering_id';

    private const PROP_PROXY_ID = 'hav_proxy_id';

    private const PROP_BASE_URL_KEY = 'hav_proxy_base_url_key';

    private const PROP_CREATE_IDEMPOTENCY_KEY = 'hav_proxy_create_idempotency_key';

    public function getConfig($values = []): array
    {
        $hasLegacyProfiles = !empty($values['base_url_profiles']);

        return [
            [
                'name' => 'base_url',
                'type' => 'text',
                'label' => 'Base URL',
                'description' => 'Root URL of the HAV Proxy API. /api/v3 is added automatically if omitted.',
                'required' => !$hasLegacyProfiles,
                'validation' => $hasLegacyProfiles ? 'nullable|url' : 'url',
                'placeholder' => 'https://proxy-api.example.com',
            ],
            [
                'name' => 'api_token',
                'type' => 'password',
                'label' => 'API Token',
                'required' => !$hasLegacyProfiles,
                'encrypted' => true,
            ],
            [
                'name' => 'default_auth_header',
                'type' => 'select',
                'label' => 'Default Auth Header',
                'required' => true,
                'default' => 'Authorization',
                'options' => [
                    'Authorization' => 'Authorization: Bearer',
                    'X-API-Key' => 'X-API-Key',
                    'X-ACCESS-CODE' => 'X-ACCESS-CODE',
                ],
            ],
            [
                'name' => 'location_map',
                'type' => 'textarea',
                'label' => 'Location Map Overrides',
                'description' => 'Optional. One mapping per line: provider-group-or-code=internal-location-code. Leave blank to auto-match by location code/name.',
                'placeholder' => "billing-us=united-states\nvn-viettel=viet-nam-viettel",
            ],
            [
                'name' => 'user_agent',
                'type' => 'text',
                'label' => 'User Agent',
                'default' => 'HAV-Proxy-IPv4-DC-Paymenter/1.0',
            ],
            [
                'name' => 'timeout_seconds',
                'type' => 'number',
                'label' => 'HTTP Timeout',
                'default' => 15,
                'database_type' => 'integer',
                'min_value' => 1,
                'max_value' => 60,
            ],
            [
                'name' => 'poll_interval_seconds',
                'type' => 'number',
                'label' => 'Poll Interval',
                'default' => 2,
                'database_type' => 'integer',
                'min_value' => 0,
                'max_value' => 15,
            ],
            [
                'name' => 'poll_timeout_seconds',
                'type' => 'number',
                'label' => 'Poll Timeout',
                'default' => 90,
                'database_type' => 'integer',
                'min_value' => 1,
                'max_value' => 110,
            ],
        ];
    }

    public function getProductConfig($values = []): array
    {
        return [
            [
                'name' => 'protocol',
                'type' => 'select',
                'label' => 'Protocol',
                'required' => true,
                'default' => 'socks5',
                'options' => [
                    'socks5' => 'SOCKS5',
                    'http' => 'HTTP',
                    'default' => 'Provider default',
                    'vmess' => 'VMess',
                    'vless' => 'VLESS',
                    'shadowsocks' => 'Shadowsocks',
                    'trojan' => 'Trojan',
                    'wireguard' => 'WireGuard',
                ],
            ],
            [
                'name' => 'speed_limit_mbps',
                'type' => 'number',
                'label' => 'Speed Limit',
                'description' => 'Mbps. Leave 0 to let provider decide.',
                'default' => 0,
                'database_type' => 'integer',
                'min_value' => 0,
            ],
            [
                'name' => 'bandwidth_limit_mb',
                'type' => 'number',
                'label' => 'Bandwidth Limit',
                'description' => 'MB. Leave 0 for provider default.',
                'default' => 0,
                'database_type' => 'integer',
                'min_value' => 0,
            ],
            [
                'name' => 'preferred_outbound_ip',
                'type' => 'text',
                'label' => 'Preferred Outbound IP',
                'validation' => 'nullable|ip',
            ],
        ];
    }

    public function getCheckoutConfig(Product $product, $values = [], $settings = []): array
    {
        $options = [];

        foreach (LocationAvailabilityService::forProduct($product, self::SERVICE_TYPE) as $productOffering) {
            $providerOffering = $productOffering->providerLocationOffering;

            if ($product->server_id && $providerOffering->provider_id !== $product->server_id) {
                continue;
            }

            if ($providerOffering->stock_state === ProviderLocationOffering::STOCK_UNAVAILABLE) {
                continue;
            }

            if (!LocationAvailabilityService::resolveTarget($providerOffering)) {
                continue;
            }

            $location = $providerOffering->locationOption;
            $group = $location->primaryGroup?->name;
            $options[$productOffering->id] = $group ? $group . ' / ' . $location->display_name : $location->display_name;
        }

        return [
            [
                'name' => self::PROP_PRODUCT_LOCATION_OFFERING_ID,
                'type' => 'select',
                'label' => 'Location',
                'required' => true,
                'description' => $options ? null : 'No provider locations are currently enabled for this product.',
                'default' => $options ? array_key_first($options) : null,
                'options' => $options,
            ],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $profiles = $this->profiles();
        } catch (Exception $exception) {
            return $exception->getMessage();
        }

        if (!$profiles) {
            return 'No enabled base URL profiles configured.';
        }

        foreach ($profiles as $profile) {
            try {
                $this->client($profile)->capabilities();
            } catch (Exception $exception) {
                return sprintf('Profile %s failed: %s', $profile['key'], $exception->getMessage());
            }
        }

        return true;
    }

    public function syncLocationTargets(ServerModel $provider): array
    {
        $locationMap = $this->locationMap();
        $stats = [
            'created' => 0,
            'updated' => 0,
            'disabled_targets' => 0,
            'unavailable_offerings' => 0,
            'skipped' => [],
        ];
        $seenTargetIds = [];

        foreach ($this->profiles() as $profile) {
            $groups = $this->items($this->client($profile)->groups());

            foreach ($groups as $group) {
                $locationCode = $this->mappedLocationCode($group, $locationMap);

                if (!$locationCode) {
                    $stats['skipped'][] = [
                        'profile' => $profile['key'],
                        'external' => $this->externalName($group),
                        'reason' => 'not_mapped',
                    ];

                    continue;
                }

                $location = LocationOption::where('code', $locationCode)->first();

                if (!$location) {
                    $stats['skipped'][] = [
                        'profile' => $profile['key'],
                        'external' => $this->externalName($group),
                        'location_code' => $locationCode,
                        'reason' => 'missing_location_option',
                    ];

                    continue;
                }

                $offering = ProviderLocationOffering::firstOrNew([
                    'provider_id' => $provider->id,
                    'location_option_id' => $location->id,
                    'service_type' => self::SERVICE_TYPE,
                ]);
                $wasNew = !$offering->exists;

                if ($wasNew) {
                    $offering->enabled = true;
                }

                $offering->fill([
                    'stock_state' => $this->stockState($group),
                    'capabilities' => $this->capabilitiesFromGroup($profile, $group),
                    'last_synced_at' => now(),
                ])->save();

                $target = $this->targetForProfile($offering, $profile, $group);

                if (!$target->exists) {
                    $target->status = ProviderLocationTarget::STATUS_ACTIVE;
                }

                $target->fill([
                    'external_location_id' => $this->externalId($group),
                    'external_name' => $this->externalName($group),
                    'raw_payload' => $this->targetPayload($profile, $group),
                ])->save();

                $seenTargetIds[] = $target->id;
                $stats[$wasNew ? 'created' : 'updated']++;
            }
        }

        $stats['disabled_targets'] = $this->disableStaleTargets($provider, $seenTargetIds);
        $stats['unavailable_offerings'] = $this->markOfferingsWithoutActiveTargetsUnavailable($provider);

        return $stats;
    }

    public function createServer(Service $service, $settings, $properties)
    {
        if (!empty($properties[self::PROP_PROXY_ID])) {
            return $this->storedProxyData($properties);
        }

        [$productOffering, $providerOffering, $target] = $this->validatedSelection($service, $properties);
        $profile = $this->profileForTarget($target);
        $client = $this->client($profile);
        $payload = $this->createPayload($service, $settings, $target);
        $idempotencyKey = $this->existingOrCreateIdempotencyKey($service);

        LocationAvailabilityService::snapshotSelection($service, $providerOffering);
        $this->putProperty($service, self::PROP_PRODUCT_LOCATION_OFFERING_ID, (string) $productOffering->id, 'Product Location Offering ID');
        $this->putProperty($service, self::PROP_BASE_URL_KEY, $profile['key'], 'HAV Proxy Base URL Key');

        $accepted = $client->createProxy($payload, $idempotencyKey);
        $operationId = $this->operationId($accepted);
        $proxyId = $this->resourceId($accepted);

        if ($operationId) {
            $operation = $this->waitForOperation($client, $operationId);
            $this->putProperty($service, 'hav_proxy_operation_id', $operationId, 'HAV Proxy Operation ID');
            $proxyId = $this->resourceId($operation) ?: $proxyId;
        }

        if (!$proxyId) {
            throw new Exception('HAV Proxy API did not return a proxy resource id.');
        }

        $proxy = $this->proxyData($client->getProxy($proxyId));
        $this->storeProxyProperties($service, $profile, $proxyId, $proxy);

        return $proxy ?: $accepted;
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        return $this->runProxyAction($service, $properties, 'stop');
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        return $this->runProxyAction($service, $properties, 'start');
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        $proxyId = $this->proxyId($properties);
        $client = $this->client($this->profileForProperties($properties));
        $idempotencyKey = $this->freshIdempotencyKey($service, 'delete');
        $accepted = $client->deleteProxy($proxyId, $idempotencyKey);
        $operationId = $this->operationId($accepted);

        if ($operationId) {
            $this->waitForOperation($client, $operationId);
        }

        $this->putProperty($service, 'hav_proxy_deleted_at', now()->toIso8601String(), 'HAV Proxy Deleted At');
        $this->putProperty($service, 'hav_proxy_status', 'deleted', 'HAV Proxy Status');

        return $accepted;
    }

    private function runProxyAction(Service $service, array $properties, string $action): array
    {
        $proxyId = $this->proxyId($properties);
        $client = $this->client($this->profileForProperties($properties));
        $idempotencyKey = $this->freshIdempotencyKey($service, $action);
        $accepted = $client->proxyAction($proxyId, $action, $idempotencyKey);
        $operationId = $this->operationId($accepted);
        $operation = $operationId ? $this->waitForOperation($client, $operationId) : $accepted;

        $this->putProperty($service, 'hav_proxy_status', $action === 'start' ? 'active' : 'stopped', 'HAV Proxy Status');

        if ($operationId) {
            $this->putProperty($service, 'hav_proxy_operation_id', $operationId, 'HAV Proxy Operation ID');
        }

        return $operation;
    }

    private function validatedSelection(Service $service, array $properties): array
    {
        $productOfferingId = $properties[self::PROP_PRODUCT_LOCATION_OFFERING_ID] ?? null;

        if (!$productOfferingId) {
            throw new Exception('Location is required for HAV Proxy provisioning.');
        }

        $productOffering = ProductLocationOffering::with([
            'providerLocationOffering.locationOption.primaryGroup',
            'providerLocationOffering.targets',
        ])->find($productOfferingId);

        if (!$productOffering || $productOffering->product_id !== $service->product_id || !$productOffering->enabled) {
            throw new Exception('Selected location is not enabled for this product.');
        }

        $providerOffering = $productOffering->providerLocationOffering;

        if (!$providerOffering || !$providerOffering->enabled) {
            throw new Exception('Selected location is not enabled for this provider.');
        }

        if ($providerOffering->provider_id !== $service->product->server_id) {
            throw new Exception('Selected location belongs to a different provider.');
        }

        if ($providerOffering->service_type !== self::SERVICE_TYPE) {
            throw new Exception('Selected location is not a proxy offering.');
        }

        if ($providerOffering->stock_state === ProviderLocationOffering::STOCK_UNAVAILABLE) {
            throw new Exception('Selected location is unavailable.');
        }

        if ($providerOffering->locationOption->status !== LocationOption::STATUS_ACTIVE) {
            throw new Exception('Selected location is not active.');
        }

        $target = LocationAvailabilityService::resolveTarget($providerOffering);

        if (!$target) {
            throw new Exception('Selected location has no active provider target.');
        }

        return [$productOffering, $providerOffering, $target];
    }

    private function createPayload(Service $service, array $settings, ProviderLocationTarget $target): array
    {
        $raw = $target->raw_payload ?? [];
        $protocol = (string) ($settings['protocol'] ?? 'socks5');
        $payload = [
            'kind' => self::KIND,
            'external_ref' => 'paymenter:service:' . $service->id,
            'protocol' => $protocol,
        ];

        if (($raw['target_type'] ?? 'group') === 'node' && !empty($raw['node_id'])) {
            $payload['node_id'] = (string) $raw['node_id'];
        } else {
            $payload['group_id'] = (string) ($raw['group_id'] ?? $target->external_location_id ?? $target->external_location_code);
        }

        $bandwidthLimit = (int) ($settings['bandwidth_limit_mb'] ?? 0);
        $speedLimit = (int) ($settings['speed_limit_mbps'] ?? 0);
        $preferredOutboundIp = $settings['preferred_outbound_ip'] ?? null;

        if ($bandwidthLimit > 0) {
            $payload['bandwidth_limit_mb'] = $bandwidthLimit;
        }

        if ($speedLimit > 0) {
            $payload['speed_limit_mbps'] = $speedLimit;
        }

        if ($preferredOutboundIp) {
            $payload['preferred_outbound_ip'] = $preferredOutboundIp;
        }

        return $payload;
    }

    private function waitForOperation(V3Client $client, string $operationId): array
    {
        $timeout = min(max((int) ($this->config('poll_timeout_seconds') ?? 90), 1), 110);
        $interval = max((int) ($this->config('poll_interval_seconds') ?? 2), 0);
        $deadline = microtime(true) + $timeout;

        do {
            $operation = $client->getOperation($operationId);
            $status = strtolower((string) (data_get($operation, 'status') ?? data_get($operation, 'data.status') ?? ''));

            if (in_array($status, ['succeeded', 'success', 'completed', 'done'], true)) {
                return $operation;
            }

            if (in_array($status, ['failed', 'error', 'cancelled', 'canceled'], true)) {
                $message = data_get($operation, 'error.message') ?? data_get($operation, 'message') ?? 'HAV Proxy operation failed.';
                throw new Exception($message);
            }

            if ($interval > 0) {
                sleep($interval);
            }
        } while (microtime(true) < $deadline);

        throw new Exception('HAV Proxy operation timed out: ' . $operationId);
    }

    private function storeProxyProperties(Service $service, array $profile, string $proxyId, array $proxy): void
    {
        $properties = [
            self::PROP_PROXY_ID => [$proxyId, 'HAV Proxy ID'],
            self::PROP_BASE_URL_KEY => [$profile['key'], 'HAV Proxy Base URL Key'],
            'hav_proxy_status' => [(string) ($proxy['status'] ?? 'active'), 'HAV Proxy Status'],
            'hav_proxy_host' => [$proxy['host'] ?? null, 'HAV Proxy Host'],
            'hav_proxy_outbound_ip' => [$proxy['outbound_ip'] ?? null, 'HAV Proxy Outbound IP'],
            'hav_proxy_port_socks' => [$proxy['port_socks'] ?? null, 'HAV Proxy SOCKS Port'],
            'hav_proxy_port_http' => [$proxy['port_http'] ?? null, 'HAV Proxy HTTP Port'],
            'hav_proxy_username' => [$proxy['username'] ?? null, 'HAV Proxy Username'],
            'hav_proxy_password' => [$proxy['password'] ?? null, 'HAV Proxy Password'],
            'hav_proxy_connection_uri' => [$proxy['connection_uri'] ?? null, 'HAV Proxy Connection URI'],
        ];

        foreach ($properties as $key => [$value, $name]) {
            if ($value !== null && $value !== '') {
                $this->putProperty($service, $key, (string) $value, $name);
            }
        }
    }

    private function putProperty(Service $service, string $key, string $value, ?string $name = null): void
    {
        $service->properties()->updateOrCreate([
            'key' => $key,
        ], [
            'name' => $name ?? Str::headline($key),
            'value' => $value,
        ]);
    }

    private function existingOrCreateIdempotencyKey(Service $service): string
    {
        $existing = $service->properties()->where('key', self::PROP_CREATE_IDEMPOTENCY_KEY)->value('value');

        if ($existing) {
            return $existing;
        }

        $key = 'paymenter-service-' . $service->id . '-create-' . Str::uuid();
        $this->putProperty($service, self::PROP_CREATE_IDEMPOTENCY_KEY, $key, 'HAV Proxy Create Idempotency Key');

        return $key;
    }

    private function freshIdempotencyKey(Service $service, string $action): string
    {
        $key = 'paymenter-service-' . $service->id . '-' . $action . '-' . Str::uuid();
        $this->putProperty($service, 'hav_proxy_' . $action . '_idempotency_key', $key, 'HAV Proxy ' . Str::headline($action) . ' Idempotency Key');

        return $key;
    }

    private function proxyId(array $properties): string
    {
        $proxyId = (string) ($properties[self::PROP_PROXY_ID] ?? '');

        if (!$proxyId) {
            throw new Exception('HAV Proxy service has no provider proxy id.');
        }

        return $proxyId;
    }

    private function storedProxyData(array $properties): array
    {
        return Arr::only($properties, [
            self::PROP_PROXY_ID,
            self::PROP_BASE_URL_KEY,
            'hav_proxy_status',
            'hav_proxy_host',
            'hav_proxy_outbound_ip',
            'hav_proxy_port_socks',
            'hav_proxy_port_http',
            'hav_proxy_username',
            'hav_proxy_password',
            'hav_proxy_connection_uri',
        ]);
    }

    private function profiles(): array
    {
        $simpleProfile = $this->simpleProfile();

        if ($simpleProfile) {
            return [
                $simpleProfile['key'] => $simpleProfile,
            ];
        }

        $value = $this->config('base_url_profiles');
        $profiles = is_array($value) ? $value : json_decode((string) $value, true);

        if (!is_array($profiles)) {
            throw new Exception('Base URL Profiles must be valid JSON.');
        }

        if (!array_is_list($profiles)) {
            $profiles = array_values($profiles);
        }

        $normalized = [];

        foreach ($profiles as $profile) {
            if (!is_array($profile) || ($profile['enabled'] ?? true) === false) {
                continue;
            }

            foreach (['key', 'base_url'] as $required) {
                if (empty($profile[$required])) {
                    throw new Exception('Every Base URL profile needs a ' . $required . '.');
                }
            }

            if (empty($profile['api_token']) && empty($profile['token']) && empty($profile['access_code'])) {
                throw new Exception('Every Base URL profile needs an api_token, token, or access_code.');
            }

            $profile['key'] = (string) $profile['key'];
            $normalized[$profile['key']] = $profile;
        }

        return $normalized;
    }

    private function simpleProfile(): ?array
    {
        $baseUrl = $this->config('base_url');
        $apiToken = $this->config('api_token');

        if (!$baseUrl && !$apiToken) {
            return null;
        }

        if (!$baseUrl || !$apiToken) {
            throw new Exception('Base URL and API Token are required.');
        }

        return [
            'key' => 'primary',
            'label' => 'Primary',
            'base_url' => (string) $baseUrl,
            'api_token' => (string) $apiToken,
            'auth_header' => (string) ($this->config('default_auth_header') ?? 'Authorization'),
            'enabled' => true,
        ];
    }

    private function profileForTarget(ProviderLocationTarget $target): array
    {
        $raw = $target->raw_payload ?? [];
        $key = $raw['base_url_key'] ?? null;

        return $this->profileByKey($key ? (string) $key : null);
    }

    private function profileForProperties(array $properties): array
    {
        return $this->profileByKey($properties[self::PROP_BASE_URL_KEY] ?? null);
    }

    private function profileByKey(?string $key): array
    {
        $profiles = $this->profiles();

        if ($key && isset($profiles[$key])) {
            return $profiles[$key];
        }

        if (!$key && count($profiles) === 1) {
            return reset($profiles);
        }

        throw new Exception('HAV Proxy base URL profile not found: ' . ($key ?: 'none'));
    }

    private function client(array $profile): V3Client
    {
        return new V3Client(
            $profile,
            (string) ($this->config('default_auth_header') ?? 'Authorization'),
            (string) ($this->config('user_agent') ?? 'HAV-Proxy-IPv4-DC-Paymenter/1.0'),
            (int) ($this->config('timeout_seconds') ?? 15),
        );
    }

    private function locationMap(): array
    {
        $value = $this->config('location_map');

        if (!$value) {
            return [];
        }

        $map = is_array($value) ? $value : $this->parseLocationMap((string) $value);

        if (!is_array($map)) {
            throw new Exception('Location Map must use provider-code=location-code lines.');
        }

        $normalized = [];

        foreach ($map as $external => $locationCode) {
            $normalized[(string) $external] = (string) $locationCode;
            $normalized[Str::lower((string) $external)] = (string) $locationCode;
        }

        return $normalized;
    }

    private function parseLocationMap(string $value): array
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return [];
        }

        if (str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);

            if (!is_array($decoded)) {
                throw new Exception('Location Map JSON is invalid.');
            }

            return $decoded;
        }

        $map = [];

        foreach (preg_split('/\r\n|\r|\n/', $trimmed) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s*(?:=>|=|:)\s*/', $line, 2);

            if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
                throw new Exception('Invalid Location Map line: ' . $line);
            }

            $map[trim($parts[0])] = trim($parts[1]);
        }

        return $map;
    }

    private function mappedLocationCode(array $group, array $locationMap): ?string
    {
        foreach ($this->externalKeys($group) as $key) {
            if (isset($locationMap[$key])) {
                return $locationMap[$key];
            }

            $lower = Str::lower($key);

            if (isset($locationMap[$lower])) {
                return $locationMap[$lower];
            }
        }

        return $this->autoMappedLocationCode($group);
    }

    private function autoMappedLocationCode(array $group): ?string
    {
        $candidates = [];

        foreach ($this->externalKeys($group) as $key) {
            $candidates[] = $key;
            $candidates[] = Str::lower($key);
            $candidates[] = Str::slug($key);
        }

        foreach (array_values(array_unique(array_filter($candidates))) as $candidate) {
            if (LocationOption::where('code', $candidate)->where('status', LocationOption::STATUS_ACTIVE)->exists()) {
                return $candidate;
            }
        }

        return null;
    }

    private function externalKeys(array $group): array
    {
        return array_values(array_filter(array_unique(array_map('strval', [
            data_get($group, 'billing_group_id'),
            data_get($group, 'location_code'),
            data_get($group, 'code'),
            data_get($group, 'slug'),
            data_get($group, 'id'),
            data_get($group, 'name'),
        ]))));
    }

    private function items(array $response): array
    {
        if (array_is_list($response)) {
            return $response;
        }

        foreach (['data', 'groups', 'nodes', 'items'] as $key) {
            $items = data_get($response, $key);

            if (is_array($items)) {
                return $items;
            }
        }

        return [];
    }

    private function stockState(array $group): string
    {
        $state = Str::lower((string) (data_get($group, 'stock_state') ?? data_get($group, 'sell_state') ?? data_get($group, 'status') ?? 'unknown'));

        return match ($state) {
            'available', 'active', 'ready', 'online', 'ok' => ProviderLocationOffering::STOCK_AVAILABLE,
            'limited', 'low_stock', 'low-stock' => ProviderLocationOffering::STOCK_LIMITED,
            'unavailable', 'disabled', 'sold_out', 'sold-out', 'offline' => ProviderLocationOffering::STOCK_UNAVAILABLE,
            default => ProviderLocationOffering::STOCK_UNKNOWN,
        };
    }

    private function capabilitiesFromGroup(array $profile, array $group): array
    {
        return [
            'kind' => self::KIND,
            'base_url_key' => $profile['key'],
            'base_url_label' => $profile['label'] ?? $profile['key'],
            'provider_status' => data_get($group, 'status'),
            'sell_state' => data_get($group, 'sell_state'),
            'available_count' => data_get($group, 'available_count'),
            'capacity' => data_get($group, 'capacity'),
        ];
    }

    private function targetPayload(array $profile, array $group): array
    {
        return [
            'target_type' => 'group',
            'base_url_key' => $profile['key'],
            'group_id' => $this->externalId($group),
            'external_location_code' => $this->externalCode($group),
            'group_code' => data_get($group, 'code'),
            'billing_group_id' => data_get($group, 'billing_group_id'),
            'kind' => self::KIND,
        ];
    }

    private function targetForProfile(ProviderLocationOffering $offering, array $profile, array $group): ProviderLocationTarget
    {
        return ProviderLocationTarget::query()
            ->where('provider_location_offering_id', $offering->id)
            ->where('external_location_code', $this->externalCode($group))
            ->where('raw_payload->base_url_key', $profile['key'])
            ->first()
            ?? new ProviderLocationTarget([
                'provider_location_offering_id' => $offering->id,
                'external_location_code' => $this->externalCode($group),
            ]);
    }

    private function disableStaleTargets(ServerModel $provider, array $seenTargetIds): int
    {
        return ProviderLocationTarget::query()
            ->whereHas('providerLocationOffering', function ($query) use ($provider) {
                $query
                    ->where('provider_id', $provider->id)
                    ->where('service_type', self::SERVICE_TYPE);
            })
            ->where('status', ProviderLocationTarget::STATUS_ACTIVE)
            ->where('raw_payload->kind', self::KIND)
            ->when($seenTargetIds, fn ($query) => $query->whereNotIn('id', $seenTargetIds))
            ->update([
                'status' => ProviderLocationTarget::STATUS_DISABLED,
            ]);
    }

    private function markOfferingsWithoutActiveTargetsUnavailable(ServerModel $provider): int
    {
        return ProviderLocationOffering::query()
            ->where('provider_id', $provider->id)
            ->where('service_type', self::SERVICE_TYPE)
            ->whereDoesntHave('targets', fn ($query) => $query->where('status', ProviderLocationTarget::STATUS_ACTIVE))
            ->update([
                'stock_state' => ProviderLocationOffering::STOCK_UNAVAILABLE,
                'last_synced_at' => now(),
            ]);
    }

    private function externalId(array $group): string
    {
        return (string) (data_get($group, 'id') ?? data_get($group, 'group_id') ?? data_get($group, 'billing_group_id') ?? data_get($group, 'code'));
    }

    private function externalCode(array $group): string
    {
        return (string) (data_get($group, 'billing_group_id') ?? data_get($group, 'code') ?? data_get($group, 'id'));
    }

    private function externalName(array $group): string
    {
        return (string) (data_get($group, 'name') ?? data_get($group, 'label') ?? $this->externalCode($group));
    }

    private function operationId(array $payload): ?string
    {
        $id = data_get($payload, 'operation.id')
            ?? data_get($payload, 'data.operation.id')
            ?? data_get($payload, 'operation_id')
            ?? data_get($payload, 'data.operation_id');

        return $id === null ? null : (string) $id;
    }

    private function resourceId(array $payload): ?string
    {
        $id = data_get($payload, 'resource.id')
            ?? data_get($payload, 'data.resource.id')
            ?? data_get($payload, 'resource_id')
            ?? data_get($payload, 'data.resource_id')
            ?? data_get($payload, 'id')
            ?? data_get($payload, 'data.id');

        return $id === null ? null : (string) $id;
    }

    private function proxyData(array $payload): array
    {
        return data_get($payload, 'data') ?? data_get($payload, 'resource') ?? $payload;
    }
}
