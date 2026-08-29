<?php

namespace App\Services\Service;

use App\Jobs\Server\CreateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Service;

class RenewServiceService
{
    /**
     * Handle the service renewal.
     *
     * @return void
     */
    public function handle(Service $service)
    {
        $service->refresh();
        if ($service->status === Service::STATUS_CANCELLED || $service->cancellation?->type === 'immediate') {
            return;
        }

        if ($service->product->server || ProviderOperationLifecycleService::usesDeferredOperations($service)) {
            if ($service->status == Service::STATUS_SUSPENDED) {
                UnsuspendJob::dispatch($service);
                if (ProviderOperationLifecycleService::usesDeferredOperations($service)) {
                    return;
                }
            } elseif ($service->status == Service::STATUS_PENDING) {
                CreateJob::dispatch($service);
                if (ProviderOperationLifecycleService::usesDeferredOperations($service)) {
                    return;
                }
            }
        }

        $service->expires_at = $service->calculateNextDueDate();
        $service->status = Service::STATUS_ACTIVE;
        $service->save();
    }
}
