<?php

namespace App\Jobs\Server;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use App\Services\Service\ProviderOperationLifecycleService;
use DateTimeImmutable;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PollServerOperationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 30;

    public $tries = 1000;

    public function __construct(
        public int $serviceId,
        public string $action,
        public string $operationId,
        public int $deadlineTimestamp,
        public bool $sendNotification = true,
    ) {}

    public function uniqueId(): string
    {
        return sprintf('server-operation:%s:%s:%s', $this->serviceId, $this->action, $this->operationId);
    }

    public function retryUntil(): DateTimeImmutable
    {
        return (new DateTimeImmutable)->setTimestamp($this->deadlineTimestamp);
    }

    public function handle(): void
    {
        $service = Service::findOrFail($this->serviceId);

        try {
            $result = ExtensionHelper::pollServerOperation($service, $this->action, $this->operationId);
        } catch (Exception $exception) {
            if (ProviderOperationLifecycleService::shouldRetry($exception) && time() < $this->deadlineTimestamp) {
                $this->release(ProviderOperationLifecycleService::retryDelay($this->attempts()));

                return;
            }

            throw $exception;
        }

        if (ProviderOperationLifecycleService::isPending($result)) {
            if (time() >= $this->deadlineTimestamp) {
                throw new Exception(sprintf('Timed out waiting for provider %s operation %s.', $this->action, $this->operationId));
            }

            $this->release(max(1, (int) ($result['provider_poll_interval'] ?? 2)));

            return;
        }

        ProviderOperationLifecycleService::complete($service, $this->action, $this->sendNotification, is_array($result) ? $result : []);
    }
}
