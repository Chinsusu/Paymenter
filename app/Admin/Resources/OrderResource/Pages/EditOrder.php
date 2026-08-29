<?php

namespace App\Admin\Resources\OrderResource\Pages;

use App\Admin\Resources\OrderResource;
use App\Jobs\Server\TerminateJob;
use App\Models\Service;
use App\Services\Service\ProviderOperationLifecycleService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (DeleteAction $action): void {
                    $providerServices = $this->record->services
                        ->filter(fn (Service $service) => $service->status !== Service::STATUS_CANCELLED
                            && ProviderOperationLifecycleService::usesDeferredOperations($service));
                    if ($providerServices->isEmpty()) {
                        return;
                    }

                    $providerServices->each(fn (Service $service) => TerminateJob::dispatch($service));
                    Notification::make('Provider terminations queued')
                        ->title('Delete this order again after its services reach cancelled status.')
                        ->warning()
                        ->send();
                    $action->halt();
                }),
        ];
    }
}
