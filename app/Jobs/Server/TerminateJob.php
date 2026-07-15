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

class TerminateJob implements ShouldBeUnique, ShouldQueue
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
        return 'terminate-server:' . $this->service->id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $data = [];
        $this->service->refresh();

        if (ProviderOperationLifecycleService::usesDeferredOperations($this->service) && $this->service->cancellation?->type === 'immediate') {
            $pending = $this->service->properties()
                ->whereIn('key', [
                    'hav_proxy_ipv4_dc_pending_action',
                    'hav_proxy_ipv4_dc_pending_operation_id',
                ])
                ->pluck('value', 'key');
            $pendingAction = (string) ($pending['hav_proxy_ipv4_dc_pending_action'] ?? '');
            $pendingOperationId = (string) ($pending['hav_proxy_ipv4_dc_pending_operation_id'] ?? '');

            if ($pendingAction !== '' && $pendingAction !== 'delete' && $pendingOperationId === '') {
                try {
                    ExtensionHelper::recoverServerOperationWithoutId($this->service, $pendingAction);
                    ProviderOperationLifecycleService::handoffCancellation($this->service, $pendingAction);
                } catch (Exception $e) {
                    if (ProviderOperationLifecycleService::shouldRetry($e)) {
                        $this->release(ProviderOperationLifecycleService::retryDelay($this->attempts()));

                        return;
                    }

                    $this->fail($e);

                    return;
                }
            }
        }

        try {
            $data = ExtensionHelper::terminateServer($this->service);
        } catch (Exception $e) {
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
            ProviderOperationLifecycleService::queuePending($this->service, 'delete', $data, $this->sendNotification);

            return;
        }

        if (ProviderOperationLifecycleService::usesDeferredOperations($this->service)) {
            ProviderOperationLifecycleService::complete($this->service, 'delete', $this->sendNotification, is_array($data) ? $data : []);

            return;
        }

        if ($this->sendNotification) {
            NotificationHelper::serverTerminatedNotification($this->service->user, $this->service, is_array($data) ? $data : []);
        }
    }
}
