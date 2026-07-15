<?php

namespace App\Jobs\Server\Concerns;

use Illuminate\Queue\Middleware\WithoutOverlapping;

trait SerializesProviderService
{
    public function middleware(): array
    {
        $serviceId = $this->serviceId ?? $this->service->id;

        return [
            (new WithoutOverlapping('provider-service:' . $serviceId))
                ->shared()
                ->releaseAfter(5)
                ->expireAfter(60),
        ];
    }
}
