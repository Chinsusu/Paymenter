<?php

namespace App\Services\Service;

use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Jobs\Server\PollServerOperationJob;
use App\Jobs\Server\TerminateJob;
use App\Models\Server as ProviderServer;
use App\Models\Service;
use DateTimeImmutable;
use Exception;
use Illuminate\Support\Facades\DB;

class ProviderOperationLifecycleService
{
    public const PENDING_RESULT_KEY = 'provider_operation_pending';

    public static function usesDeferredOperations(Service $service): bool
    {
        $snapshot = $service->properties()
            ->whereIn('key', ['provider_server_id', 'provider_extension'])
            ->pluck('value', 'key');
        $providerExtension = (string) ($snapshot['provider_extension'] ?? '');
        if ($providerExtension !== '') {
            return $providerExtension === 'HAVProxyIPv4DC';
        }

        $providerServerId = (int) ($snapshot['provider_server_id'] ?? 0);
        if ($providerServerId > 0 && ($server = ProviderServer::find($providerServerId))) {
            return $server->extension === 'HAVProxyIPv4DC';
        }

        if ($service->properties()->where('key', 'like', 'hav_proxy_ipv4_dc_%')->exists()) {
            return true;
        }

        return $service->product?->server?->extension === 'HAVProxyIPv4DC';
    }

    public static function isPending(array|bool $result): bool
    {
        return is_array($result) && ($result[self::PENDING_RESULT_KEY] ?? false) === true;
    }

    public static function queuePending(Service $service, string $action, array $result, bool $sendNotification = true): void
    {
        $operationId = (string) ($result['provider_operation_id'] ?? '');
        if ($operationId === '') {
            throw new Exception('Provider returned a pending operation without an operation id.');
        }

        $interval = max(1, (int) ($result['provider_poll_interval'] ?? 2));
        $deadlineTimestamp = (int) ($result['provider_poll_deadline_at'] ?? 0);
        if ($deadlineTimestamp <= time()) {
            throw new Exception('Provider operation deadline has expired.');
        }

        PollServerOperationJob::dispatch(
            $service->id,
            $action,
            $operationId,
            $deadlineTimestamp,
            $sendNotification,
        )->delay(now()->addSeconds($interval));
    }

    public static function complete(Service $service, string $action, bool $sendNotification = true, array $data = [], ?string $operationId = null): void
    {
        $outcome = DB::transaction(function () use ($service, $action, $operationId) {
            $lockedService = Service::query()
                ->with(['product', 'cancellation'])
                ->lockForUpdate()
                ->findOrFail($service->id);

            if ($action !== 'delete' && $lockedService->cancellation?->type === 'immediate') {
                ExtensionHelper::handoffServerOperationToDelete($lockedService, $action, $operationId);

                return ['handoff' => true, 'transitioned' => false];
            }

            $transitioned = match ($action) {
                'create', 'start' => self::activateLocked($lockedService),
                'stop' => self::suspendLocked($lockedService),
                'delete' => self::terminateLocked($lockedService),
                default => throw new Exception('Unsupported provider operation action: ' . $action),
            };

            ExtensionHelper::finalizeServerOperation($lockedService, $action, $operationId);

            return ['handoff' => false, 'transitioned' => $transitioned];
        });

        if ($outcome['handoff']) {
            TerminateJob::dispatch($service, $sendNotification);

            return;
        }

        self::sendTransitionNotification($service, $action, $outcome['transitioned'], $sendNotification, $data);
    }

    public static function handoffCancellation(Service $service, string $action, ?string $operationId = null): void
    {
        ExtensionHelper::handoffServerOperationToDelete($service, $action, $operationId);
    }

    public static function activate(Service $service, bool $sendNotification = true, array $data = []): void
    {
        $activated = DB::transaction(fn () => self::activateLocked(
            Service::query()->with('cancellation')->lockForUpdate()->findOrFail($service->id),
        ));

        if ($activated && $sendNotification && ($data['provider_operation_notified'] ?? false) !== true) {
            $service->refresh();
            NotificationHelper::serverCreatedNotification($service->user, $service, $data);
        }
    }

    public static function suspend(Service $service, bool $sendNotification = true, array $data = []): void
    {
        $suspended = DB::transaction(fn () => self::suspendLocked(
            Service::query()->lockForUpdate()->findOrFail($service->id),
        ));

        if ($suspended && $sendNotification && ($data['provider_operation_notified'] ?? false) !== true) {
            $service->refresh();
            NotificationHelper::serverSuspendedNotification($service->user, $service, $data);
        }
    }

    public static function terminate(Service $service, bool $sendNotification = true, array $data = []): void
    {
        $terminated = DB::transaction(fn () => self::terminateLocked(
            Service::query()->with('product')->lockForUpdate()->findOrFail($service->id),
        ));

        if ($terminated && $sendNotification && ($data['provider_operation_notified'] ?? false) !== true) {
            $service->refresh();
            NotificationHelper::serverTerminatedNotification($service->user, $service, $data);
        }
    }

    private static function activateLocked(Service $service): bool
    {
        if ($service->status === Service::STATUS_CANCELLED || $service->cancellation?->type === 'immediate') {
            return false;
        }
        if ($service->status === Service::STATUS_ACTIVE) {
            return false;
        }

        $service->forceFill([
            'expires_at' => $service->calculateNextDueDate(),
            'status' => Service::STATUS_ACTIVE,
        ])->save();

        return true;
    }

    private static function suspendLocked(Service $service): bool
    {
        if (in_array($service->status, [Service::STATUS_CANCELLED, Service::STATUS_SUSPENDED], true)) {
            return false;
        }

        $service->update(['status' => Service::STATUS_SUSPENDED]);

        return true;
    }

    private static function terminateLocked(Service $service): bool
    {
        if ($service->status === Service::STATUS_CANCELLED) {
            return false;
        }

        $service->update(['status' => Service::STATUS_CANCELLED]);
        $service->invoices()->where('status', 'pending')->update(['status' => 'cancelled']);

        if ($service->product->stock !== null) {
            $service->product->increment('stock', $service->quantity);
        }

        return true;
    }

    private static function sendTransitionNotification(Service $service, string $action, bool $transitioned, bool $sendNotification, array $data): void
    {
        if (!$transitioned || !$sendNotification || ($data['provider_operation_notified'] ?? false) === true) {
            return;
        }

        $service->refresh();
        match ($action) {
            'create', 'start' => NotificationHelper::serverCreatedNotification($service->user, $service, $data),
            'stop' => NotificationHelper::serverSuspendedNotification($service->user, $service, $data),
            'delete' => NotificationHelper::serverTerminatedNotification($service->user, $service, $data),
        };
    }

    public static function shouldRetry(Exception $exception): bool
    {
        if (property_exists($exception, 'details') && ($exception->details['terminal'] ?? false) === true) {
            return false;
        }

        return property_exists($exception, 'retryable') && (bool) $exception->retryable;
    }

    public static function retryDelay(int $attempt): int
    {
        return min(60, max(5, 5 * $attempt));
    }

    public static function deadline(int $seconds): DateTimeImmutable
    {
        return (new DateTimeImmutable)->setTimestamp(time() + max(5, $seconds));
    }
}
