<?php

namespace App\Console\Commands;

use App\Jobs\Server\CreateJob;
use App\Jobs\Server\PollServerOperationJob;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReconcileProviderOperations extends Command
{
    protected $signature = 'provider:reconcile-operations {--service= : A single service ID}';

    protected $description = 'Requeue interrupted or lost asynchronous provider operations';

    public function handle(): int
    {
        $requeued = 0;

        Service::query()
            ->when($this->option('service'), fn ($query, $serviceId) => $query->whereKey($serviceId))
            ->where(function ($query) {
                $query
                    ->whereHas('properties', fn ($query) => $query
                        ->where('key', 'hav_proxy_ipv4_dc_pending_action')
                        ->where('value', '!=', ''))
                    ->orWhereHas('properties', fn ($query) => $query
                        ->whereIn('key', [
                            'hav_proxy_ipv4_dc_create_operation_id',
                            'hav_proxy_ipv4_dc_delete_operation_id',
                            'hav_proxy_ipv4_dc_start_operation_id',
                            'hav_proxy_ipv4_dc_stop_operation_id',
                        ])
                        ->where('value', '!=', ''));
            })
            ->with('properties')
            ->chunkById(100, function ($services) use (&$requeued) {
                foreach ($services as $service) {
                    $properties = $service->properties->pluck('value', 'key');
                    $action = (string) $properties->get('hav_proxy_ipv4_dc_pending_action');
                    $operationId = (string) $properties->get('hav_proxy_ipv4_dc_pending_operation_id');
                    $reconcileUntil = (int) $properties->get('hav_proxy_ipv4_dc_reconcile_until_at');

                    if ($action === '') {
                        [$action, $operationId] = $this->legacyOperation($service, $properties);
                        if ($action === '' || !$this->adoptLegacyOperation($service, $action, $operationId)) {
                            continue;
                        }
                        $reconcileUntil = time() + 3600;
                    }

                    if ($properties->get('hav_proxy_ipv4_dc_recovery_state') === 'manual') {
                        $this->warn(sprintf('Service %s is blocked for manual provider recovery.', $service->id));

                        continue;
                    }

                    if ($reconcileUntil <= 0) {
                        $reconcileUntil = time() + 3600;
                        $service->properties()->updateOrCreate([
                            'key' => 'hav_proxy_ipv4_dc_reconcile_until_at',
                        ], [
                            'name' => 'Provider reconciliation deadline',
                            'value' => (string) $reconcileUntil,
                        ]);
                    }

                    if ($reconcileUntil <= time()) {
                        $service->properties()->updateOrCreate([
                            'key' => 'hav_proxy_ipv4_dc_recovery_state',
                        ], [
                            'name' => 'Provider recovery state',
                            'value' => 'manual',
                        ]);
                        $this->warn(sprintf('Service %s requires manual recovery for %s.', $service->id, $action));

                        continue;
                    }

                    if ($operationId !== '') {
                        PollServerOperationJob::dispatch(
                            $service->id,
                            $action,
                            $operationId,
                            min($reconcileUntil ?: time() + 300, time() + 300),
                            false,
                        );
                        $requeued++;

                        continue;
                    }

                    if ($service->cancellation?->type === 'immediate' && $action !== 'delete') {
                        TerminateJob::dispatch($service, false);
                        $requeued++;

                        continue;
                    }

                    match ($action) {
                        'create' => CreateJob::dispatch($service, false),
                        'stop' => SuspendJob::dispatch($service, false),
                        'start' => UnsuspendJob::dispatch($service, false),
                        'delete' => TerminateJob::dispatch($service, false),
                        default => $service->properties()->updateOrCreate([
                            'key' => 'hav_proxy_ipv4_dc_recovery_state',
                        ], [
                            'name' => 'Provider recovery state',
                            'value' => 'manual',
                        ]),
                    };
                    $requeued++;
                }
            });

        $this->info(sprintf('Requeued %s provider operations.', $requeued));

        return self::SUCCESS;
    }

    private function legacyOperation(Service $service, $properties): array
    {
        $legacyKeys = [
            'delete' => 'hav_proxy_ipv4_dc_delete_operation_id',
            'start' => 'hav_proxy_ipv4_dc_start_operation_id',
            'stop' => 'hav_proxy_ipv4_dc_stop_operation_id',
            'create' => 'hav_proxy_ipv4_dc_create_operation_id',
        ];

        foreach ($legacyKeys as $action => $key) {
            $operationId = (string) $properties->get($key);
            if ($operationId === '') {
                continue;
            }
            if ($action === 'create' && ($service->status !== Service::STATUS_PENDING || $properties->get('hav_proxy_ipv4_dc_connection_uri'))) {
                $service->properties()->updateOrCreate([
                    'key' => 'hav_proxy_ipv4_dc_last_create_operation_id',
                ], [
                    'name' => 'Last create operation ID',
                    'value' => $operationId,
                ]);
                $service->properties()->where('key', $key)->delete();

                continue;
            }
            if ($action === 'delete' && $service->status === Service::STATUS_CANCELLED) {
                $service->properties()->where('key', $key)->delete();

                continue;
            }

            return [$action, $operationId];
        }

        return ['', ''];
    }

    private function adoptLegacyOperation(Service $service, string $action, string $operationId): bool
    {
        return DB::transaction(function () use ($service, $action, $operationId) {
            $lockedService = Service::query()->lockForUpdate()->findOrFail($service->id);
            if ($lockedService->properties()->where('key', 'hav_proxy_ipv4_dc_pending_action')->where('value', '!=', '')->exists()) {
                return false;
            }

            $values = [
                'hav_proxy_ipv4_dc_pending_action' => ['Pending provider action', $action],
                'hav_proxy_ipv4_dc_pending_operation_id' => ['Pending provider operation ID', $operationId],
                'hav_proxy_ipv4_dc_pending_generation' => ['Pending provider generation', (string) Str::uuid()],
                'hav_proxy_ipv4_dc_operation_deadline_at' => ['Provider operation deadline', (string) (time() + 300)],
                'hav_proxy_ipv4_dc_reconcile_until_at' => ['Provider reconciliation deadline', (string) (time() + 3600)],
            ];
            if ($action === 'create' && !$lockedService->properties()->where('key', 'hav_proxy_ipv4_dc_external_ref')->where('value', '!=', '')->exists()) {
                $values['hav_proxy_ipv4_dc_external_ref'] = ['External reference', sprintf('paymenter-service-%s', $lockedService->id)];
            }

            foreach ($values as $key => [$name, $value]) {
                $lockedService->properties()->updateOrCreate(['key' => $key], [
                    'name' => $name,
                    'value' => $value,
                ]);
            }

            return true;
        });
    }
}
