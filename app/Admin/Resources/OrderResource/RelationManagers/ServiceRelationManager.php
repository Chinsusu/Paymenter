<?php

namespace App\Admin\Resources\OrderResource\RelationManagers;

use App\Admin\Resources\ServiceResource;
use App\Jobs\Server\TerminateJob;
use App\Models\Service;
use App\Services\Service\ProviderOperationLifecycleService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ServiceRelationManager extends RelationManager
{
    protected static string $relationship = 'services';

    // Renaem to Order Products
    public static string $name = 'Products/Services';

    public static ?string $label = 'Products/Services';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            ->columns([
                TextColumn::make('product.name'),
                TextColumn::make('quantity'),
                TextColumn::make('formattedPrice')->label('Price'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make()->url(fn ($record) => ServiceResource::getUrl('edit', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function (Collection $records, DeleteBulkAction $action): void {
                            $providerServices = $records->filter(fn (Service $service) => $service->status !== Service::STATUS_CANCELLED
                                && ProviderOperationLifecycleService::usesDeferredOperations($service));
                            if ($providerServices->isEmpty()) {
                                return;
                            }

                            $providerServices->each(fn (Service $service) => TerminateJob::dispatch($service));
                            Notification::make('Provider terminations queued')
                                ->title('Delete these services again after they reach cancelled status.')
                                ->warning()
                                ->send();
                            $action->halt();
                        }),
                ]),
            ]);
    }
}
