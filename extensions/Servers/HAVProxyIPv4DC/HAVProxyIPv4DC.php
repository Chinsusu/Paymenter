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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class HAVProxyIPv4DC extends Server
{
    private const KIND = 'ipv4_dc';

    private const DEFAULT_USER_AGENT = 'HAV-Proxy-IPv4-DC-Paymenter/1.0';

    private const PENDING_ACTION_KEY = 'hav_proxy_ipv4_dc_pending_action';

    private const PENDING_OPERATION_KEY = 'hav_proxy_ipv4_dc_pending_operation_id';

    private const PENDING_GENERATION_KEY = 'hav_proxy_ipv4_dc_pending_generation';

    private const OPERATION_DEADLINE_KEY = 'hav_proxy_ipv4_dc_operation_deadline_at';

    private const RECONCILE_UNTIL_KEY = 'hav_proxy_ipv4_dc_reconcile_until_at';

    private const RECOVERY_STATE_KEY = 'hav_proxy_ipv4_dc_recovery_state';

    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'base_url',
                'label' => 'Base URL',
                'type' => 'text',
                'description' => 'Provider API base URL, for example https://vn-proxy-ipv4.resvn.net',
                'required' => true,
                'validation' => 'url|starts_with:https://',
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
                'validation' => 'integer|min:1|max:30',
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
            ->filter(fn (ProductLocationOffering $offering) => $offering->providerLocationOffering->isSellable())
            ->filter(fn (ProductLocationOffering $offering) => LocationAvailabilityService::resolveTarget($offering->providerLocationOffering) !== null)
            ->mapWithKeys(function (ProductLocationOffering $offering) {
                $location = $offering->providerLocationOffering->locationOption;
                $group = $location->primaryGroup?->name;

                return [
                    $offering->id => $group ? $group . ' / ' . $location->display_name : $location->display_name,
                ];
            })
            ->all();

        return [
            [
                'name' => 'product_location_offering_id',
                'label' => 'Location',
                'type' => 'select',
                'required' => true,
                'options' => $options,
                'description' => $options === [] ? 'No location is currently available for this product.' : null,
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
        $seenOfferingIds = [];
        $seenTargetIds = [];

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

            $offering = ProviderLocationOffering::firstOrNew([
                'provider_id' => $provider->id,
                'location_option_id' => $location->id,
                'service_type' => ProviderLocationOffering::SERVICE_PROXY,
            ]);
            if (!$offering->exists) {
                $offering->enabled = true;
            }
            $offering->forceFill([
                'stock_state' => $this->stockStateForGroup($group),
                'last_synced_at' => now(),
            ])->save();
            $seenOfferingIds[] = $offering->id;

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
            $seenTargetIds[] = $target->id;
            $synced++;
        }

        ProviderLocationOffering::query()
            ->where('provider_id', $provider->id)
            ->where('service_type', ProviderLocationOffering::SERVICE_PROXY)
            ->when($seenOfferingIds !== [], fn ($query) => $query->whereNotIn('id', $seenOfferingIds))
            ->update([
                'stock_state' => ProviderLocationOffering::STOCK_UNAVAILABLE,
                'last_synced_at' => now(),
            ]);

        ProviderLocationTarget::query()
            ->whereHas('providerLocationOffering', fn ($query) => $query
                ->where('provider_id', $provider->id)
                ->where('service_type', ProviderLocationOffering::SERVICE_PROXY))
            ->when($seenTargetIds !== [], fn ($query) => $query->whereNotIn('id', $seenTargetIds))
            ->update(['status' => ProviderLocationTarget::STATUS_DISABLED]);

        return $synced;
    }

    public function markLocationOfferingsUnavailable(ProviderServer $provider): void
    {
        ProviderLocationOffering::query()
            ->where('provider_id', $provider->id)
            ->where('service_type', ProviderLocationOffering::SERVICE_PROXY)
            ->update(['stock_state' => ProviderLocationOffering::STOCK_UNAVAILABLE]);
    }

    public function createServer(Service $service, $settings, $properties): array
    {
        $service->refresh();
        if ($service->status !== Service::STATUS_PENDING || $service->cancellation?->type === 'immediate') {
            throw new Exception(sprintf('Cannot create a proxy while the service status is %s.', $service->status));
        }

        if (!empty($properties['hav_proxy_ipv4_dc_connection_uri'])) {
            throw new Exception('Proxy already exists for this service.');
        }

        $pendingOperationId = $this->assertOperationAvailable($service, 'create');
        if ($pendingOperationId) {
            return $this->pendingResult($service, 'create', $pendingOperationId);
        }

        [$providerOffering, $locationSnapshot] = $this->resolveProviderOffering($service, $properties);
        $externalCode = $locationSnapshot['external_location_code'] ?? null;
        if (!$externalCode) {
            throw new Exception('Selected location is missing provider group mapping.');
        }

        $claim = $this->claimOperationContext($service, 'create');
        $idempotencyKey = $claim['idempotency_key'];
        $externalRef = $this->createExternalRef($service, $idempotencyKey);
        $this->putProperty($service, 'hav_proxy_ipv4_dc_external_ref', 'External reference', $externalRef);
        $this->abortClaimIfCancelled($service, 'create');

        $payload = [
            'kind' => self::KIND,
            'group_id' => $externalCode,
            'protocol' => $properties['hav_proxy_ipv4_dc_protocol'] ?? $settings['protocol'] ?? 'default',
            'external_ref' => $externalRef,
        ];
        foreach (['bandwidth_limit_mb', 'speed_limit_mbps'] as $setting) {
            $value = $properties['hav_proxy_ipv4_dc_' . $setting] ?? ($settings[$setting] ?? null);
            if ($value !== null && $value !== '') {
                $payload[$setting] = (int) $value;
            }
        }

        try {
            $response = $this->send('post', '/api/v3/proxies', $payload, [
                'Idempotency-Key' => $idempotencyKey,
            ]);

            if ($response->status() === 409 && data_get($response->json(), 'error.code') === 'CAPACITY_EXHAUSTED') {
                throw new V3ProviderException('Selected location is out of stock.', 409, 'CAPACITY_EXHAUSTED', false);
            }

            $this->ensureSuccessfulResponse($response, 'create proxy');
        } catch (V3ProviderException $exception) {
            if (!$exception->retryable) {
                $this->recordFailedOperation(
                    $service,
                    'create',
                    (string) ($exception->providerCode ?: 'PROVIDER_ERROR'),
                    $exception->getMessage(),
                    false,
                    $providerOffering,
                    null,
                    $claim['generation'],
                );
            }

            throw $exception;
        }
        $operationId = data_get($response->json(), 'data.operation.id');
        if (!$operationId) {
            throw new V3ProviderException('Provider did not return a create operation id.', 202, 'AMBIGUOUS_PROVIDER_RESPONSE', true);
        }

        $proxyId = data_get($response->json(), 'data.resource.id') ?? data_get($response->json(), 'data.operation.resource_id');
        $this->storeOperation($service, 'create', $claim['generation'], $operationId, $proxyId);

        return $this->pendingResult($service, 'create', $operationId);
    }

    public function terminateServer(Service $service, $settings, $properties): array|bool
    {
        $pendingOperationId = $this->assertOperationAvailable($service, 'delete');
        if ($pendingOperationId) {
            return $this->pendingResult($service, 'delete', $pendingOperationId);
        }

        $proxyId = $properties['hav_proxy_ipv4_dc_proxy_id'] ?? null;
        if (!$proxyId) {
            return true;
        }

        $claim = $this->claimOperationContext($service, 'delete');
        $idempotencyKey = $claim['idempotency_key'];

        try {
            $response = $this->send('delete', '/api/v3/proxies/' . rawurlencode($proxyId), headers: [
                'Idempotency-Key' => $idempotencyKey,
            ]);
            if ($response->status() !== 404) {
                $this->ensureSuccessfulResponse($response, 'delete proxy');
            }
        } catch (V3ProviderException $exception) {
            if (!$exception->retryable) {
                $this->recordFailedOperation(
                    $service,
                    'delete',
                    (string) ($exception->providerCode ?: 'PROVIDER_ERROR'),
                    $exception->getMessage(),
                    false,
                    null,
                    null,
                    $claim['generation'],
                );
            }

            throw $exception;
        }

        if ($response->status() === 404) {
            return true;
        }

        $operationId = data_get($response->json(), 'data.operation.id');
        if (!$operationId) {
            throw new V3ProviderException('Provider did not return a delete operation id.', 202, 'AMBIGUOUS_PROVIDER_RESPONSE', true);
        }

        $this->storeOperation($service, 'delete', $claim['generation'], $operationId, $proxyId);

        return $this->pendingResult($service, 'delete', $operationId);
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
        $this->assertCurrentOperation($service, $action, $operationId);
        try {
            $operation = data_get($this->request('get', '/api/v3/operations/' . rawurlencode($operationId)), 'data', []);
        } catch (V3ProviderException $exception) {
            if ($exception->status === 404) {
                return $this->reconcileMissingOperation($service, $properties, $action, $operationId);
            }

            throw $exception;
        }
        $this->assertOperationResponse($service, $properties, $operation, $action, $operationId);
        $state = data_get($operation, 'state');
        if (!in_array($state, ['succeeded', 'failed', 'timed_out', 'cancelled'], true)) {
            return $this->pendingResult($service, $action, $operationId);
        }

        $providerOffering = null;
        if ($action === 'create') {
            [$providerOffering] = $this->resolveProviderOffering($service, $properties);
        }

        if ($state === 'timed_out') {
            return $this->reconcileMissingOperation($service, $properties, $action, $operationId);
        }

        if ($state !== 'succeeded') {
            $this->recordFailedOperation(
                $service,
                $action,
                (string) (data_get($operation, 'error_code') ?: 'PROVIDER_ERROR'),
                (string) (data_get($operation, 'error_message') ?: 'Provider operation failed.'),
                (bool) data_get($operation, 'retryable', $state === 'timed_out'),
                $providerOffering,
                $operationId,
            );

            $this->throwFailedOperation($operation, $action);
        }

        if ($action === 'create') {
            $proxyId = data_get($operation, 'resource_id') ?? ($properties['hav_proxy_ipv4_dc_proxy_id'] ?? null);
            if (!$proxyId) {
                $this->markManualRecovery($service, 'Provider operation succeeded without a proxy id.');

                throw new Exception('Provider operation succeeded without a proxy id.');
            }

            $proxy = data_get($operation, 'resource_snapshot');
            if (!is_array($proxy) || empty($proxy['host'])) {
                $proxy = data_get($this->request('get', '/api/v3/proxies/' . rawurlencode($proxyId)), 'data');
            }
            if (!is_array($proxy) || empty($proxy['host'])) {
                $this->markManualRecovery($service, 'Provider did not return proxy credentials.');

                throw new Exception('Provider did not return proxy credentials.');
            }
            $proxy = $this->normalizeProxySnapshot($proxy);
            if (empty($proxy['connection_uri'])) {
                $this->markManualRecovery($service, 'Provider proxy snapshot is missing usable ports.');

                throw new Exception('Provider proxy snapshot is missing usable ports.');
            }

            $externalRef = (string) ($service->properties()->where('key', 'hav_proxy_ipv4_dc_external_ref')->value('value') ?: sprintf('paymenter-service-%s', $service->id));
            DB::transaction(function () use ($service, $action, $operationId, $proxyId, $proxy, $externalRef) {
                $lockedService = Service::query()->lockForUpdate()->findOrFail($service->id);
                $this->assertLockedOperation($lockedService, $action, $operationId);
                $this->snapshotProxy($lockedService, $proxyId, $operationId, $proxy, $externalRef);
            });

            return $this->notificationData($proxy);
        }

        if ($action === 'delete') {
            return [];
        }

        DB::transaction(function () use ($service, $action, $operationId, $operation) {
            $lockedService = Service::query()->lockForUpdate()->findOrFail($service->id);
            $this->assertLockedOperation($lockedService, $action, $operationId);
            $this->putProperty(
                $lockedService,
                'hav_proxy_ipv4_dc_status',
                'Proxy status',
                (string) (data_get($operation, 'desired_status') ?: ($action === 'start' ? 'running' : 'stopped')),
            );
        });

        return [];
    }

    public function finalizeOperation(Service $service, string $action, ?string $operationId = null): void
    {
        DB::transaction(function () use ($service, $action, $operationId) {
            $lockedService = Service::query()->lockForUpdate()->findOrFail($service->id);
            $pendingAction = $lockedService->properties()->where('key', self::PENDING_ACTION_KEY)->value('value');

            if (!$pendingAction) {
                if ($action === 'delete' && $operationId === null) {
                    $this->deleteProxyProperties($lockedService);
                }

                return;
            }

            $this->assertLockedOperation($lockedService, $action, $operationId);
            if ($action === 'delete') {
                $this->deleteProxyProperties($lockedService);

                return;
            }

            if ($pendingAction === $action) {
                $this->completeOperationContext($lockedService, $action);
            }
        });
    }

    public function handoffOperationToDelete(Service $service, string $action, ?string $operationId = null): void
    {
        if ($action === 'delete') {
            return;
        }

        DB::transaction(function () use ($service, $action, $operationId) {
            $lockedService = Service::query()->lockForUpdate()->findOrFail($service->id);
            $this->assertLockedOperation($lockedService, $action, $operationId);

            $storedOperationId = (string) $lockedService->properties()
                ->where('key', self::PENDING_OPERATION_KEY)
                ->value('value');
            if ($storedOperationId !== '') {
                $this->putProperty(
                    $lockedService,
                    'hav_proxy_ipv4_dc_last_' . $action . '_operation_id',
                    'Last ' . $action . ' operation ID',
                    $storedOperationId,
                );
            }

            $lockedService->properties()->whereIn('key', [
                'hav_proxy_ipv4_dc_' . $action . '_operation_id',
                'hav_proxy_ipv4_dc_' . $action . '_idempotency_key',
                self::PENDING_ACTION_KEY,
                self::PENDING_OPERATION_KEY,
                self::PENDING_GENERATION_KEY,
                self::OPERATION_DEADLINE_KEY,
                self::RECONCILE_UNTIL_KEY,
                self::RECOVERY_STATE_KEY,
                'hav_proxy_ipv4_dc_recovery_reason',
            ])->delete();

            $pollTimeout = max(5, (int) ($this->config('poll_timeout') ?? 90));
            $generation = (string) Str::uuid();
            $this->putProperty($lockedService, self::PENDING_ACTION_KEY, 'Pending provider action', 'delete');
            $this->putProperty($lockedService, self::PENDING_GENERATION_KEY, 'Pending provider generation', $generation);
            $this->putProperty(
                $lockedService,
                'hav_proxy_ipv4_dc_delete_idempotency_key',
                'Delete idempotency key',
                $this->newIdempotencyKey('delete', $lockedService, $generation),
            );
            $this->putProperty($lockedService, self::OPERATION_DEADLINE_KEY, 'Provider operation deadline', (string) (time() + $pollTimeout));
            $this->putProperty($lockedService, self::RECONCILE_UNTIL_KEY, 'Provider reconciliation deadline', (string) (time() + max(3600, $pollTimeout * 4)));
        });
    }

    public function recoverOperationWithoutId(Service $service, $settings, $properties, string $action): array
    {
        if (!in_array($action, ['create', 'start', 'stop'], true)) {
            throw new V3ProviderException('Unsupported ambiguous provider action.', 409, 'INVALID_RECOVERY_ACTION', false, ['terminal' => true]);
        }

        DB::transaction(function () use ($service, $action) {
            $lockedService = Service::query()->lockForUpdate()->findOrFail($service->id);
            $this->assertLockedOperation($lockedService, $action, null);
            $reconcileUntil = (int) $lockedService->properties()->where('key', self::RECONCILE_UNTIL_KEY)->value('value');
            if ($reconcileUntil > 0 && $reconcileUntil <= time()) {
                throw new V3ProviderException('Provider reconciliation deadline expired.', 409, 'MANUAL_RECOVERY_REQUIRED', false, ['terminal' => true]);
            }
        });

        if ($action !== 'create') {
            return [];
        }

        $externalRef = $this->externalReferenceForRecovery($service, $properties);
        if ($externalRef === '') {
            $this->markManualRecovery($service, 'Ambiguous create operation is missing its external reference.');

            throw new V3ProviderException('Ambiguous create operation is missing its external reference.', 409, 'MANUAL_RECOVERY_REQUIRED', false, ['terminal' => true]);
        }

        $proxy = $this->findProxyByExternalReference($service, $externalRef);
        if ($proxy === null) {
            throw new V3ProviderException('Ambiguous create outcome is not available yet.', 404, 'RECONCILIATION_PENDING', true);
        }

        $proxyId = (string) data_get($proxy, 'id');
        if ($proxyId === '' || empty($proxy['host'])) {
            throw new V3ProviderException('Reconciled proxy credentials are not available yet.', 404, 'RECONCILIATION_PENDING', true);
        }
        $proxy = $this->normalizeProxySnapshot($proxy);
        if (empty($proxy['connection_uri'])) {
            throw new V3ProviderException('Reconciled proxy snapshot is missing usable ports.', 404, 'RECONCILIATION_PENDING', true);
        }

        DB::transaction(function () use ($service, $action, $proxyId, $proxy, $externalRef) {
            $lockedService = Service::query()->lockForUpdate()->findOrFail($service->id);
            $this->assertLockedOperation($lockedService, $action, null);
            $this->snapshotProxy($lockedService, $proxyId, null, $proxy, $externalRef);
        });

        return [];
    }

    private function reconcileMissingOperation(Service $service, array $properties, string $action, string $operationId): array
    {
        $proxyId = $properties['hav_proxy_ipv4_dc_proxy_id']
            ?? $service->properties()->where('key', 'hav_proxy_ipv4_dc_proxy_id')->value('value');

        if ($action === 'create') {
            $externalRef = $this->externalReferenceForRecovery($service, $properties);
            if ($externalRef === '') {
                $this->markManualRecovery($service, 'Create operation is missing its external reference.');

                throw new V3ProviderException('Create operation is missing its external reference.', 409, 'MANUAL_RECOVERY_REQUIRED', false, ['terminal' => true]);
            }

            $proxy = $this->findProxyByExternalReference($service, $externalRef);
            if ($proxy === null) {
                throw new V3ProviderException('Provider operation outcome is not available yet.', 404, 'RECONCILIATION_PENDING', true);
            }

            $proxyId = (string) (data_get($proxy, 'id') ?: $proxyId);
            if ($proxyId === '') {
                $this->markManualRecovery($service, 'Reconciled proxy is missing its id.');

                throw new V3ProviderException('Reconciled proxy is missing its id.', 409, 'MANUAL_RECOVERY_REQUIRED', false, ['terminal' => true]);
            }
            if (empty($proxy['host'])) {
                $proxy = (array) data_get($this->request('get', '/api/v3/proxies/' . rawurlencode($proxyId)), 'data', []);
            }
            if (empty($proxy['host'])) {
                throw new V3ProviderException('Reconciled proxy credentials are not available yet.', 404, 'RECONCILIATION_PENDING', true);
            }

            $proxy = $this->normalizeProxySnapshot($proxy);
            if (empty($proxy['connection_uri'])) {
                throw new V3ProviderException('Reconciled proxy snapshot is missing usable ports.', 404, 'RECONCILIATION_PENDING', true);
            }
            DB::transaction(function () use ($service, $action, $operationId, $proxyId, $proxy, $externalRef) {
                $lockedService = Service::query()->lockForUpdate()->findOrFail($service->id);
                $this->assertLockedOperation($lockedService, $action, $operationId);
                $this->snapshotProxy($lockedService, $proxyId, $operationId, $proxy, $externalRef);
            });

            return $this->notificationData($proxy);
        }

        if (!$proxyId) {
            $this->markManualRecovery($service, 'Provider operation is missing its proxy id.');

            throw new V3ProviderException('Provider operation is missing its proxy id.', 409, 'MANUAL_RECOVERY_REQUIRED', false, ['terminal' => true]);
        }

        try {
            $proxy = (array) data_get($this->request('get', '/api/v3/proxies/' . rawurlencode($proxyId)), 'data', []);
        } catch (V3ProviderException $exception) {
            if ($action === 'delete' && $exception->status === 404) {
                return [];
            }

            throw $exception;
        }

        $status = (string) data_get($proxy, 'status');
        $expectedStatus = $action === 'start' ? 'running' : ($action === 'stop' ? 'stopped' : 'deleted');
        if ($status !== $expectedStatus) {
            throw new V3ProviderException('Provider operation outcome is not available yet.', 404, 'RECONCILIATION_PENDING', true);
        }

        if ($action === 'delete') {
            return [];
        }

        DB::transaction(function () use ($service, $action, $operationId, $status) {
            $lockedService = Service::query()->lockForUpdate()->findOrFail($service->id);
            $this->assertLockedOperation($lockedService, $action, $operationId);
            $this->putProperty($lockedService, 'hav_proxy_ipv4_dc_status', 'Proxy status', $status);
        });

        return [];
    }

    private function findProxyByExternalReference(Service $service, string $externalRef): ?array
    {
        $matches = collect(data_get($this->request('get', '/api/v3/proxies', ['external_ref' => $externalRef]), 'data', []))
            ->map(function ($proxy) {
                $proxy = (array) $proxy;
                $proxyId = (string) data_get($proxy, 'id');
                if ($proxyId !== '' && (!data_get($proxy, 'external_ref') || !data_get($proxy, 'kind') || empty($proxy['host']))) {
                    $details = data_get($this->request('get', '/api/v3/proxies/' . rawurlencode($proxyId)), 'data', []);
                    if (is_array($details)) {
                        $proxy = array_replace($proxy, $details);
                    }
                }

                return $proxy;
            })
            ->filter(fn ($proxy) => data_get($proxy, 'external_ref') === $externalRef
                && data_get($proxy, 'kind') === self::KIND
                && data_get($proxy, 'status') !== 'deleted')
            ->values();

        if ($matches->count() > 1) {
            $this->markManualRecovery($service, 'Multiple proxies matched the create external reference.');

            throw new V3ProviderException('Multiple proxies matched the create external reference.', 409, 'MULTIPLE_PROXY_MATCHES', false, ['terminal' => true]);
        }

        return $matches->isEmpty() ? null : (array) $matches->first();
    }

    private function externalReferenceForRecovery(Service $service, array $properties): string
    {
        $externalRef = (string) ($properties['hav_proxy_ipv4_dc_external_ref']
            ?? $service->properties()->where('key', 'hav_proxy_ipv4_dc_external_ref')->value('value'));
        if ($externalRef !== '') {
            return $externalRef;
        }

        $idempotencyKey = (string) $service->properties()
            ->where('key', 'hav_proxy_ipv4_dc_create_idempotency_key')
            ->value('value');
        if ($idempotencyKey !== '') {
            return $this->createExternalRef($service, $idempotencyKey);
        }

        return sprintf('paymenter-service-%s', $service->id);
    }

    private function runProxyAction(Service $service, array $properties, string $action): array|bool
    {
        $pendingOperationId = $this->assertOperationAvailable($service, $action);
        if ($pendingOperationId) {
            return $this->pendingResult($service, $action, $pendingOperationId);
        }

        $service->refresh();
        $requiredStatus = $action === 'start' ? Service::STATUS_SUSPENDED : Service::STATUS_ACTIVE;
        if ($service->status !== $requiredStatus || $service->cancellation?->type === 'immediate') {
            throw new Exception(sprintf('Cannot %s a proxy while the service status is %s.', $action, $service->status));
        }

        $proxyId = $properties['hav_proxy_ipv4_dc_proxy_id'] ?? null;
        if (!$proxyId) {
            throw new Exception('Proxy has not been created.');
        }

        $claim = $this->claimOperationContext($service, $action);
        $idempotencyKey = $claim['idempotency_key'];
        $this->abortClaimIfCancelled($service, $action);

        try {
            $response = $this->send('post', sprintf('/api/v3/proxies/%s/actions/%s', rawurlencode($proxyId), $action), headers: [
                'Idempotency-Key' => $idempotencyKey,
            ]);
            $this->ensureSuccessfulResponse($response, $action . ' proxy');
        } catch (V3ProviderException $exception) {
            if (!$exception->retryable) {
                $this->recordFailedOperation(
                    $service,
                    $action,
                    (string) ($exception->providerCode ?: 'PROVIDER_ERROR'),
                    $exception->getMessage(),
                    false,
                    null,
                    null,
                    $claim['generation'],
                );
            }

            throw $exception;
        }

        $operationId = data_get($response->json(), 'data.operation.id');
        if (!$operationId) {
            throw new V3ProviderException('Provider did not return a ' . $action . ' operation id.', 202, 'AMBIGUOUS_PROVIDER_RESPONSE', true);
        }

        $this->storeOperation($service, $action, $claim['generation'], $operationId, $proxyId);

        return $this->pendingResult($service, $action, $operationId);
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

        if (!$productOffering->providerLocationOffering->isSellable()) {
            throw new Exception('Selected location is out of stock.');
        }

        return $productOffering;
    }

    private function resolveProviderOffering(Service $service, array $properties): array
    {
        $providerOfferingId = $properties['provider_location_offering_id'] ?? null;
        $externalLocationCode = $properties['external_location_code'] ?? null;
        $providerServerId = (int) ($properties['provider_server_id'] ?? 0);

        if ($providerOfferingId && $externalLocationCode) {
            $offering = ProviderLocationOffering::query()
                ->with('locationOption')
                ->find($providerOfferingId);
            $expectedProviderId = $providerServerId ?: (int) $service->product?->server_id;
            if ($offering && $expectedProviderId && $offering->provider_id !== $expectedProviderId) {
                throw new Exception('Selected location belongs to a different provider.');
            }
            if ($offering && $offering->service_type !== ProviderLocationOffering::SERVICE_PROXY) {
                throw new Exception('Selected location is not configured for proxy service.');
            }
            if (!$providerServerId && $offering) {
                $this->putProperty($service, 'provider_server_id', 'provider server id', (string) $offering->provider_id);
            }

            return [$offering, [
                'external_location_code' => $externalLocationCode,
                'display_name' => $properties['display_name'] ?? $offering?->locationOption?->display_name,
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

        $request = Http::connectTimeout(10)
            ->timeout(min(30, max(1, (int) ($this->config('http_timeout') ?? 15))))
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

    private function snapshotProxy(Service $service, string $proxyId, ?string $operationId, array $proxy, string $externalRef): void
    {
        $this->putProperty($service, 'hav_proxy_ipv4_dc_proxy_id', 'Proxy ID', $proxyId);
        if ($operationId) {
            $this->putProperty($service, 'hav_proxy_ipv4_dc_last_create_operation_id', 'Last create operation ID', $operationId);
        }
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
        // Credentials remain encrypted service properties and are never copied into queued mail payloads.
        return [];
    }

    private function apiBaseUrl(): string
    {
        $baseUrl = rtrim((string) $this->config('base_url'), '/');
        if (!str_starts_with(strtolower($baseUrl), 'https://')) {
            throw new Exception('Provider Base URL must use HTTPS.');
        }

        return preg_replace('#/api/v3$#', '', $baseUrl) ?: $baseUrl;
    }

    private function pendingResult(Service $service, string $action, string $operationId): array
    {
        $deadline = (int) $service->properties()->where('key', self::OPERATION_DEADLINE_KEY)->value('value');

        return [
            'provider_operation_pending' => true,
            'provider_operation_id' => $operationId,
            'provider_poll_interval' => max(1, (int) ($this->config('poll_interval') ?? 2)),
            'provider_poll_deadline_at' => $deadline > time() ? $deadline : time() + max(5, (int) ($this->config('poll_timeout') ?? 90)),
            'provider_operation_action' => $action,
        ];
    }

    private function assertOperationAvailable(Service $service, string $action): ?string
    {
        $properties = $service->properties()
            ->whereIn('key', [self::PENDING_ACTION_KEY, self::PENDING_OPERATION_KEY, self::RECOVERY_STATE_KEY])
            ->pluck('value', 'key');

        if (($properties[self::RECOVERY_STATE_KEY] ?? null) === 'manual') {
            throw new V3ProviderException(
                'Service is blocked for manual provider recovery.',
                409,
                'MANUAL_RECOVERY_REQUIRED',
                false,
                ['terminal' => true],
            );
        }

        $pendingAction = $properties[self::PENDING_ACTION_KEY] ?? null;
        $operationId = $properties[self::PENDING_OPERATION_KEY] ?? null;

        if (!$pendingAction) {
            $legacyOperationId = $service->properties()
                ->where('key', 'hav_proxy_ipv4_dc_' . $action . '_operation_id')
                ->value('value');
            if ($legacyOperationId) {
                $claim = $this->claimOperationContext($service, $action);
                $this->storeOperation($service, $action, $claim['generation'], (string) $legacyOperationId, null);

                return (string) $legacyOperationId;
            }

            return null;
        }

        if ($pendingAction !== $action) {
            throw new V3ProviderException(
                sprintf('Provider %s operation is still pending for this service.', $pendingAction),
                409,
                'OPERATION_IN_PROGRESS',
                true,
            );
        }

        return $operationId ?: null;
    }

    private function claimOperationContext(Service $service, string $action): array
    {
        return DB::transaction(function () use ($service, $action) {
            $lockedService = Service::query()->lockForUpdate()->findOrFail($service->id);
            $idempotencyKeyName = 'hav_proxy_ipv4_dc_' . $action . '_idempotency_key';
            $context = $lockedService->properties()
                ->whereIn('key', [
                    self::PENDING_ACTION_KEY,
                    self::PENDING_OPERATION_KEY,
                    self::PENDING_GENERATION_KEY,
                    self::OPERATION_DEADLINE_KEY,
                    self::RECONCILE_UNTIL_KEY,
                    self::RECOVERY_STATE_KEY,
                    $idempotencyKeyName,
                ])
                ->pluck('value', 'key');
            $pendingAction = $context[self::PENDING_ACTION_KEY] ?? null;

            if (($context[self::RECOVERY_STATE_KEY] ?? null) === 'manual') {
                throw new V3ProviderException(
                    'Service is blocked for manual provider recovery.',
                    409,
                    'MANUAL_RECOVERY_REQUIRED',
                    false,
                    ['terminal' => true],
                );
            }

            if ($pendingAction && $pendingAction !== $action) {
                throw new V3ProviderException(
                    sprintf('Provider %s operation is still pending for this service.', $pendingAction),
                    409,
                    'OPERATION_IN_PROGRESS',
                    true,
                );
            }

            $pollTimeout = max(5, (int) ($this->config('poll_timeout') ?? 90));
            $generation = (string) ($context[self::PENDING_GENERATION_KEY] ?? '');
            $idempotencyKey = (string) ($context[$idempotencyKeyName] ?? '');
            if (!$pendingAction) {
                $generation = (string) Str::uuid();
                $idempotencyKey = $this->newIdempotencyKey($action, $lockedService, $generation);
                $this->putProperty($lockedService, self::PENDING_ACTION_KEY, 'Pending provider action', $action);
                $this->putProperty($lockedService, self::PENDING_GENERATION_KEY, 'Pending provider generation', $generation);
                $this->putProperty($lockedService, $idempotencyKeyName, ucfirst($action) . ' idempotency key', $idempotencyKey);
                $this->putProperty($lockedService, self::OPERATION_DEADLINE_KEY, 'Provider operation deadline', (string) (time() + $pollTimeout));
                $this->putProperty($lockedService, self::RECONCILE_UNTIL_KEY, 'Provider reconciliation deadline', (string) (time() + max(3600, $pollTimeout * 4)));
                $lockedService->properties()->whereIn('key', [
                    self::PENDING_OPERATION_KEY,
                    self::RECOVERY_STATE_KEY,
                    'hav_proxy_ipv4_dc_recovery_reason',
                ])->delete();

                return [
                    'generation' => $generation,
                    'idempotency_key' => $idempotencyKey,
                ];
            }

            if ($generation === '') {
                $generation = (string) Str::uuid();
                $this->putProperty($lockedService, self::PENDING_GENERATION_KEY, 'Pending provider generation', $generation);
            }
            if ($idempotencyKey === '') {
                $idempotencyKey = $this->newIdempotencyKey($action, $lockedService, $generation);
                $this->putProperty($lockedService, $idempotencyKeyName, ucfirst($action) . ' idempotency key', $idempotencyKey);
            }
            if ((int) ($context[self::OPERATION_DEADLINE_KEY] ?? 0) <= 0) {
                $this->putProperty($lockedService, self::OPERATION_DEADLINE_KEY, 'Provider operation deadline', (string) (time() + $pollTimeout));
            }
            if ((int) ($context[self::RECONCILE_UNTIL_KEY] ?? 0) <= 0) {
                $this->putProperty($lockedService, self::RECONCILE_UNTIL_KEY, 'Provider reconciliation deadline', (string) (time() + max(3600, $pollTimeout * 4)));
            }

            return [
                'generation' => $generation,
                'idempotency_key' => $idempotencyKey,
            ];
        });
    }

    private function storeOperation(Service $service, string $action, string $generation, string $operationId, ?string $proxyId): void
    {
        try {
            DB::transaction(function () use ($service, $action, $generation, $operationId, $proxyId) {
                $lockedService = Service::query()->lockForUpdate()->findOrFail($service->id);
                $context = $lockedService->properties()
                    ->whereIn('key', [
                        self::PENDING_ACTION_KEY,
                        self::PENDING_OPERATION_KEY,
                        self::PENDING_GENERATION_KEY,
                        'hav_proxy_ipv4_dc_proxy_id',
                    ])
                    ->pluck('value', 'key');
                $storedOperationId = (string) ($context[self::PENDING_OPERATION_KEY] ?? '');
                $storedProxyId = (string) ($context['hav_proxy_ipv4_dc_proxy_id'] ?? '');
                $mismatch = ($context[self::PENDING_ACTION_KEY] ?? null) !== $action
                    || (string) ($context[self::PENDING_GENERATION_KEY] ?? '') !== $generation
                    || ($storedOperationId !== '' && $storedOperationId !== $operationId)
                    || ($proxyId && $storedProxyId !== '' && $storedProxyId !== $proxyId);

                if ($mismatch) {
                    throw new V3ProviderException(
                        'Provider response does not match the active operation generation.',
                        409,
                        'OPERATION_MISMATCH',
                        false,
                        ['terminal' => true],
                    );
                }

                $this->putProperty(
                    $lockedService,
                    'hav_proxy_ipv4_dc_' . $action . '_operation_id',
                    ucfirst($action) . ' operation ID',
                    $operationId,
                );
                $this->putProperty($lockedService, self::PENDING_OPERATION_KEY, 'Pending provider operation ID', $operationId);
                if ($proxyId) {
                    $this->putProperty($lockedService, 'hav_proxy_ipv4_dc_proxy_id', 'Proxy ID', $proxyId);
                }
            });
        } catch (V3ProviderException $exception) {
            if ($exception->providerCode === 'OPERATION_MISMATCH') {
                $this->markManualRecovery($service, $exception->getMessage());
            }

            throw $exception;
        }
    }

    private function assertCurrentOperation(Service $service, string $action, string $operationId): void
    {
        $pending = $this->assertOperationAvailable($service, $action);
        if ($pending !== $operationId) {
            throw new V3ProviderException(
                'Ignoring a stale or mismatched provider operation completion.',
                409,
                'STALE_OPERATION',
                false,
                ['terminal' => true],
            );
        }
    }

    private function assertLockedOperation(Service $service, string $action, ?string $operationId): void
    {
        $context = $service->properties()
            ->whereIn('key', [self::PENDING_ACTION_KEY, self::PENDING_OPERATION_KEY])
            ->pluck('value', 'key');
        $storedOperationId = (string) ($context[self::PENDING_OPERATION_KEY] ?? '');
        $operationMatches = $operationId === null
            ? $storedOperationId === ''
            : $storedOperationId === $operationId;

        if (($context[self::PENDING_ACTION_KEY] ?? null) !== $action || !$operationMatches) {
            throw new V3ProviderException(
                'Ignoring a stale or mismatched provider operation completion.',
                409,
                'STALE_OPERATION',
                false,
                ['terminal' => true],
            );
        }
    }

    private function assertOperationResponse(Service $service, array $properties, array $operation, string $action, string $operationId): void
    {
        $actualOperationId = data_get($operation, 'id');
        $actualAction = data_get($operation, 'action');
        $actualKind = data_get($operation, 'proxy_kind');
        $actualExternalRef = data_get($operation, 'external_ref');
        $actualResourceId = data_get($operation, 'resource_id');
        $expectedExternalRef = $properties['hav_proxy_ipv4_dc_external_ref']
            ?? $service->properties()->where('key', 'hav_proxy_ipv4_dc_external_ref')->value('value');
        $expectedResourceId = $properties['hav_proxy_ipv4_dc_proxy_id']
            ?? $service->properties()->where('key', 'hav_proxy_ipv4_dc_proxy_id')->value('value');

        $ownershipMismatch = $expectedResourceId
            ? (string) $actualResourceId !== (string) $expectedResourceId
            : (!$expectedExternalRef || (string) $actualExternalRef !== (string) $expectedExternalRef);

        $invalid = (string) $actualOperationId !== $operationId
            || (string) $actualAction !== $action
            || (string) $actualKind !== self::KIND
            || ($actualExternalRef !== null && $expectedExternalRef && (string) $actualExternalRef !== (string) $expectedExternalRef)
            || $ownershipMismatch;

        if ($invalid) {
            $this->markManualRecovery($service, 'Provider operation does not belong to this service action.');

            throw new V3ProviderException(
                'Provider operation does not belong to this service action.',
                409,
                'OPERATION_MISMATCH',
                false,
                ['terminal' => true],
            );
        }
    }

    private function markManualRecovery(Service $service, string $reason): void
    {
        $this->putProperty($service, self::RECOVERY_STATE_KEY, 'Provider recovery state', 'manual');
        $this->putProperty($service, 'hav_proxy_ipv4_dc_recovery_reason', 'Provider recovery reason', Str::limit($reason, 1000));
    }

    private function completeOperationContext(Service $service, string $action): void
    {
        $operationId = $service->properties()->where('key', self::PENDING_OPERATION_KEY)->value('value');
        if ($operationId) {
            $this->putProperty($service, 'hav_proxy_ipv4_dc_last_' . $action . '_operation_id', 'Last ' . $action . ' operation ID', (string) $operationId);
        }

        $service->properties()->whereIn('key', [
            'hav_proxy_ipv4_dc_' . $action . '_operation_id',
            'hav_proxy_ipv4_dc_' . $action . '_idempotency_key',
            self::PENDING_ACTION_KEY,
            self::PENDING_OPERATION_KEY,
            self::PENDING_GENERATION_KEY,
            self::OPERATION_DEADLINE_KEY,
            self::RECONCILE_UNTIL_KEY,
            self::RECOVERY_STATE_KEY,
            'hav_proxy_ipv4_dc_recovery_reason',
        ])->delete();
    }

    private function recordFailedOperation(
        Service $service,
        string $action,
        string $code,
        string $message,
        bool $retryable,
        ?ProviderLocationOffering $offering = null,
        ?string $operationId = null,
        ?string $generation = null,
    ): void {
        DB::transaction(function () use ($service, $action, $code, $message, $retryable, $operationId, $generation) {
            $lockedService = Service::query()->lockForUpdate()->findOrFail($service->id);
            if ($operationId !== null) {
                $this->assertLockedOperation($lockedService, $action, $operationId);
            } elseif ($generation !== null) {
                $context = $lockedService->properties()
                    ->whereIn('key', [self::PENDING_ACTION_KEY, self::PENDING_OPERATION_KEY, self::PENDING_GENERATION_KEY])
                    ->pluck('value', 'key');
                if (
                    ($context[self::PENDING_ACTION_KEY] ?? null) !== $action
                    || (string) ($context[self::PENDING_GENERATION_KEY] ?? '') !== $generation
                    || (string) ($context[self::PENDING_OPERATION_KEY] ?? '') !== ''
                ) {
                    throw new V3ProviderException(
                        'Ignoring a stale provider failure.',
                        409,
                        'STALE_OPERATION',
                        false,
                        ['terminal' => true],
                    );
                }
            }

            $this->putProperty($lockedService, 'hav_proxy_ipv4_dc_last_error_code', 'Last provider error code', $code);
            $this->putProperty($lockedService, 'hav_proxy_ipv4_dc_last_error_message', 'Last provider error message', Str::limit($message, 1000));
            $this->putProperty($lockedService, 'hav_proxy_ipv4_dc_last_error_retryable', 'Last provider error retryable', $retryable ? '1' : '0');
            $this->completeOperationContext($lockedService, $action);

            if ($action === 'create') {
                $lockedService->properties()->whereIn('key', [
                    'hav_proxy_ipv4_dc_proxy_id',
                    'hav_proxy_ipv4_dc_create_operation_id',
                ])->delete();
            }
        });

        if ($code === 'CAPACITY_EXHAUSTED' && $offering) {
            $this->markOfferingCapacityExhausted($offering);
        }
    }

    private function newIdempotencyKey(string $action, Service $service, string $generation): string
    {
        return sprintf('paymenter:hav-proxy-ipv4-dc:%s:service:%s:%s', $action, $service->id, $generation);
    }

    private function abortClaimIfCancelled(Service $service, string $action): void
    {
        $service->refresh();
        if ($service->status !== Service::STATUS_CANCELLED && $service->cancellation?->type !== 'immediate') {
            return;
        }

        $this->handoffOperationToDelete($service, $action);

        throw new V3ProviderException(
            'Service was cancelled before the provider request was sent.',
            409,
            'SERVICE_CANCELLED',
            false,
            ['terminal' => true],
        );
    }

    private function createExternalRef(Service $service, string $idempotencyKey): string
    {
        $token = Str::afterLast($idempotencyKey, ':');

        return sprintf('paymenter-service-%s-create-%s', $service->id, $token);
    }

    private function normalizeProxySnapshot(array $proxy): array
    {
        if (!empty($proxy['connection_uri'])) {
            return $proxy;
        }

        $host = (string) data_get($proxy, 'host');
        $username = (string) data_get($proxy, 'username');
        $password = (string) data_get($proxy, 'password');
        $socksPort = (int) data_get($proxy, 'port_socks');
        $httpPort = (int) data_get($proxy, 'port_http');
        $scheme = $socksPort > 0 ? 'socks5' : 'http';
        $port = $socksPort > 0 ? $socksPort : $httpPort;

        if ($host !== '' && $port > 0) {
            $authentication = $username !== ''
                ? rawurlencode($username) . ':' . rawurlencode($password) . '@'
                : '';
            $proxy['connection_uri'] = sprintf('%s://%s%s:%s', $scheme, $authentication, $host, $port);
        }

        return $proxy;
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

    private function stockStateForGroup(array $group): string
    {
        $sellState = strtolower((string) data_get($group, 'sell_state', ''));
        $units = data_get($group, 'allocatable_units');

        if ($sellState === 'exhausted' || ($units !== null && (int) $units <= 0)) {
            return ProviderLocationOffering::STOCK_UNAVAILABLE;
        }

        if (in_array($sellState, ['low_capacity', 'limited', 'restricted'], true)) {
            return ProviderLocationOffering::STOCK_LIMITED;
        }

        if (in_array($sellState, ['sellable', 'available'], true)) {
            return ProviderLocationOffering::STOCK_AVAILABLE;
        }

        return ProviderLocationOffering::STOCK_UNAVAILABLE;
    }
}
