<?php

namespace App\Jobs\Server;

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\Concerns\SerializesProviderService;
use App\Models\Service;
use App\Services\Service\ProviderOperationLifecycleService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UnsuspendJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SerializesProviderService;

    public $timeout = 45;

    public $tries = 100;

    public $uniqueFor = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(public Service $service, public $sendNotification = false) {}

    public function uniqueId(): string
    {
        return 'unsuspend-server:' . $this->service->id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->service->refresh();
        if ($this->service->status !== Service::STATUS_SUSPENDED || $this->service->cancellation?->type === 'immediate') {
            return;
        }

        try {
            $data = ExtensionHelper::unsuspendServer($this->service);
        } catch (Exception $e) {
            if (property_exists($e, 'providerCode') && $e->providerCode === 'SERVICE_CANCELLED') {
                return;
            }
            if (ProviderOperationLifecycleService::shouldRetry($e)) {
                $this->release(ProviderOperationLifecycleService::retryDelay($this->attempts()));

                return;
            }
            if ($e->getMessage() !== 'No server assigned to this product' || ProviderOperationLifecycleService::usesDeferredOperations($this->service)) {
                $this->fail($e);

                return;
            }
        }

        if (ProviderOperationLifecycleService::isPending($data ?? false)) {
            ProviderOperationLifecycleService::queuePending($this->service, 'start', $data, $this->sendNotification);

            return;
        }

        if (ProviderOperationLifecycleService::usesDeferredOperations($this->service)) {
            ProviderOperationLifecycleService::complete($this->service, 'start', $this->sendNotification, is_array($data ?? null) ? $data : []);
        }
    }
}
