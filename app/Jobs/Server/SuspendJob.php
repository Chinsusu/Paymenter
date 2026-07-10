<?php

namespace App\Jobs\Server;

use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Service;
use App\Services\Service\ProviderOperationLifecycleService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SuspendJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(public Service $service, public $sendNotification = true) {}

    public function uniqueId(): string
    {
        return 'suspend-server:' . $this->service->id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $data = [];

        try {
            $data = ExtensionHelper::suspendServer($this->service);
        } catch (Exception $e) {
            if (ProviderOperationLifecycleService::shouldRetry($e) && $this->attempts() < $this->tries) {
                $this->release(ProviderOperationLifecycleService::retryDelay($this->attempts()));

                return;
            }
            if ($e->getMessage() !== 'No server assigned to this product') {
                throw $e;
            }
        }

        if (ProviderOperationLifecycleService::isPending($data)) {
            ProviderOperationLifecycleService::queuePending($this->service, 'stop', $data, $this->sendNotification);

            return;
        }

        if (ProviderOperationLifecycleService::usesDeferredOperations($this->service)) {
            ProviderOperationLifecycleService::suspend($this->service, $this->sendNotification, is_array($data) ? $data : []);

            return;
        }

        if ($this->sendNotification) {
            NotificationHelper::serverSuspendedNotification($this->service->user, $this->service, is_array($data) ? $data : []);
        }
    }
}
