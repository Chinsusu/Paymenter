<?php

namespace App\Jobs\Server;

use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
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

class CreateJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SerializesProviderService;

    public $timeout = 45;

    public $tries = 100;

    public $uniqueFor = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(public Service $service, public $sendNotification = true) {}

    public function uniqueId(): string
    {
        return 'create-server:' . $this->service->id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->service->refresh();
        if ($this->service->status !== Service::STATUS_PENDING || $this->service->cancellation?->type === 'immediate') {
            return;
        }

        $data = [];
        // $data is the data that will be used to send the email, data is coming from the extension itself
        try {
            $data = ExtensionHelper::createServer($this->service);
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

        if (ProviderOperationLifecycleService::isPending($data)) {
            ProviderOperationLifecycleService::queuePending($this->service, 'create', $data, $this->sendNotification);

            return;
        }

        if (ProviderOperationLifecycleService::usesDeferredOperations($this->service)) {
            ProviderOperationLifecycleService::complete($this->service, 'create', $this->sendNotification, is_array($data) ? $data : []);

            return;
        }

        if ($this->sendNotification) {
            NotificationHelper::serverCreatedNotification($this->service->user, $this->service, is_array($data) ? $data : []);
        }
    }
}
