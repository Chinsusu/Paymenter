<?php

namespace App\Jobs\Server;

use App\Helpers\ExtensionHelper;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(public Service $service) {}

    public function uniqueId(): string
    {
        return 'unsuspend-server:' . $this->service->id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $data = ExtensionHelper::unsuspendServer($this->service);
        } catch (Exception $e) {
            if (ProviderOperationLifecycleService::shouldRetry($e) && $this->attempts() < $this->tries) {
                $this->release(ProviderOperationLifecycleService::retryDelay($this->attempts()));

                return;
            }
            if ($e->getMessage() !== 'No server assigned to this product') {
                throw $e;
            }
        }

        if (ProviderOperationLifecycleService::isPending($data ?? false)) {
            ProviderOperationLifecycleService::queuePending($this->service, 'start', $data, false);

            return;
        }

        if (ProviderOperationLifecycleService::usesDeferredOperations($this->service)) {
            ProviderOperationLifecycleService::activate($this->service, false, is_array($data ?? null) ? $data : []);
        }
    }
}
