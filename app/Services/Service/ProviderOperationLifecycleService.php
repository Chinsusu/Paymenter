<?php

namespace App\Services\Service;

use App\Helpers\NotificationHelper;
use App\Jobs\Server\PollServerOperationJob;
use App\Jobs\Server\TerminateJob;
use App\Models\Service;
use DateTimeImmutable;
use Exception;
use Illuminate\Support\Facades\DB;

class ProviderOperationLifecycleService
{
    public const PENDING_RESULT_KEY = 'provider_operation_pending';

    public static function usesDeferredOperations(Service $service): bool
    {
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

    public static function complete(Service $service, string $action, bool $sendNotification = true, array $data = []): void
    {
        if ($action === 'create' && $service->fresh()->cancellation?->type === 'immediate') {
            TerminateJob::dispatch($service, $sendNotification);

            return;
        }

        match ($action) {
            'create', 'start' => self::activate($service, $sendNotification, $data),
            'stop' => self::suspend($service, $sendNotification, $data),
            'delete' => self::terminate($service, $sendNotification, $data),
            default => throw new Exception('Unsupported provider operation action: ' . $action),
        };
    }

    public static function activate(Service $service, bool $sendNotification = true, array $data = []): void
    {
        $service->refresh();
        if ($service->status !== Service::STATUS_ACTIVE) {
            $service->forceFill([
                'expires_at' => $service->calculateNextDueDate(),
                'status' => Service::STATUS_ACTIVE,
            ])->save();
        }

        if ($sendNotification && ($data['provider_operation_notified'] ?? false) !== true) {
            NotificationHelper::serverCreatedNotification($service->user, $service, $data);
        }
    }

    public static function suspend(Service $service, bool $sendNotification = true, array $data = []): void
    {
        $service->refresh();
        if ($service->status !== Service::STATUS_SUSPENDED) {
            $service->update(['status' => Service::STATUS_SUSPENDED]);
        }

        if ($sendNotification && ($data['provider_operation_notified'] ?? false) !== true) {
            NotificationHelper::serverSuspendedNotification($service->user, $service, $data);
        }
    }

    public static function terminate(Service $service, bool $sendNotification = true, array $data = []): void
    {
        $terminated = DB::transaction(function () use ($service) {
            $lockedService = Service::query()->with('product')->lockForUpdate()->findOrFail($service->id);
            if ($lockedService->status === Service::STATUS_CANCELLED) {
                return false;
            }

            $lockedService->update(['status' => Service::STATUS_CANCELLED]);
            $lockedService->invoices()->where('status', 'pending')->update(['status' => 'cancelled']);

            if ($lockedService->product->stock !== null) {
                $lockedService->product->increment('stock', $lockedService->quantity);
            }

            return true;
        });

        if ($terminated && $sendNotification && ($data['provider_operation_notified'] ?? false) !== true) {
            $service->refresh();
            NotificationHelper::serverTerminatedNotification($service->user, $service, $data);
        }
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
