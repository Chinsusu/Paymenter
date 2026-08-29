<?php

namespace App\Admin\Resources;

use App\Admin\Clusters\Services;
use App\Admin\Components\UserComponent;
use App\Admin\Resources\Common\RelationManagers\PropertiesRelationManager;
use App\Admin\Resources\ServiceResource\Pages\CreateService;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Admin\Resources\ServiceResource\Pages\ListService;
use App\Admin\Resources\ServiceResource\RelationManagers\ConfigOptionsRelationManager;
use App\Admin\Resources\ServiceResource\RelationManagers\InvoicesRelationManager;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\TerminateJob;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Service;
use App\Services\Service\ProviderOperationLifecycleService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-function-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-function-fill';

    public static function getNavigationBadge(): ?string
    {
        return Service::where('status', 'pending')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    protected static ?string $cluster = Services::class;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->label('Product')
                    ->required()
                    ->relationship(
                        name: 'product',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query->with('category')
                    )
                    ->getOptionLabelFromRecordUsing(fn (Product $product) => "{$product->name} - {$product->category->name} (#{$product->id})")
                    ->searchable()
                    ->live()
                    ->preload()
                    ->afterStateUpdated(function (Set $set, $state): void {
                        if (OrderResource::isHavProxyProduct((int) $state)) {
                            $set('status', Service::STATUS_PENDING);
                            $set('quantity', 1);
                            $set('product_location_offering_id', null);
                        }
                    })
                    ->disabled(fn (?Service $record) => $record && ProviderOperationLifecycleService::usesDeferredOperations($record))
                    ->placeholder('Select the product'),
                Select::make('product_location_offering_id')
                    ->label('Location')
                    ->options(fn (Get $get) => OrderResource::productLocationOptions($get('product_id')))
                    ->searchable()
                    ->preload()
                    ->visible(fn (?Service $record, Get $get) => !$record && OrderResource::isHavProxyProduct($get('product_id')))
                    ->disabled(fn (Get $get) => OrderResource::isHavProxyProduct($get('product_id')) && OrderResource::productLocationOptions($get('product_id')) === [])
                    ->required(fn (Get $get) => OrderResource::isHavProxyProduct($get('product_id')))
                    ->helperText(fn (Get $get) => OrderResource::isHavProxyProduct($get('product_id')) && OrderResource::productLocationOptions($get('product_id')) === []
                        ? 'No provider location is currently available.'
                        : null),
                Select::make('plan_id')
                    ->label('Plan')
                    ->required()
                    ->relationship('plan', 'name', fn (Builder $query, Get $get) => $query->where('priceable_id', $get('product_id'))->where('priceable_type', Product::class))
                    ->searchable()
                    ->preload()
                    ->disabled(fn (Get $get) => !$get('product_id'))
                    ->placeholder('Select the plan'),
                UserComponent::make('user_id'),
                Select::make('status')
                    ->label('Status')
                    ->required()
                    ->options([
                        // active, pending, suspended, cancelled
                        'active' => 'Active',
                        'pending' => 'Pending',
                        'suspended' => 'Suspended',
                        'cancelled' => 'Cancelled',
                    ])
                    ->disabled(fn (?Service $record, Get $get) => ($record && ProviderOperationLifecycleService::usesDeferredOperations($record))
                        || (!$record && OrderResource::isHavProxyProduct($get('product_id'))))
                    ->dehydrated()
                    ->default('pending'),
                TextInput::make('quantity')
                    ->label('Quantity')
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(fn (?Service $record, Get $get) => ($record && ProviderOperationLifecycleService::usesDeferredOperations($record)) || OrderResource::isHavProxyProduct($get('product_id')) ? 1 : null)
                    ->disabled(fn (?Service $record) => $record && ProviderOperationLifecycleService::usesDeferredOperations($record))
                    ->placeholder('Enter the quantity'),
                DatePicker::make('expires_at')
                    ->label('Expires At')
                    ->required(fn (Get $get) => $get('plan')?->type != 'one-time' && $get('plan')?->type != 'free' && $get('status') !== 'pending')
                    ->placeholder('Select the expiration date'),
                Select::make('coupon_id')
                    ->label('Coupon')
                    ->relationship('coupon', 'code')
                    ->searchable()
                    ->preload()
                    ->placeholder('Select the coupon'),
                Select::make('currency_code')
                    ->options(function (Get $get, ?string $state) {
                        $pricing = collect($get('../../pricing'))->pluck('currency_code');
                        if ($state !== null) {
                            $pricing = $pricing->filter(function ($code) use ($state) {
                                return $code !== $state;
                            });
                        }
                        $pricing = $pricing->filter(function ($code) {
                            return $code !== null;
                        });

                        return Currency::whereNotIn('code', $pricing)->pluck('code', 'code');
                    })
                    ->live()
                    ->default(config('settings.default_currency'))
                    ->required(),
                TextInput::make('price')
                    ->required()
                    ->label('Price')
                    // Suffix based on chosen currency
                    ->prefix(fn (Get $get) => Currency::where('code', $get('currency_code'))->first()?->prefix)
                    ->suffix(fn (Get $get) => Currency::where('code', $get('currency_code'))->first()?->suffix)
                    ->live(onBlur: true)
                    ->mask(RawJs::make(
                        <<<'JS'
                            $money($input, '.', '', 2)
                        JS
                    ))
                    ->numeric()
                    ->minValue(0)
                    ->hintAction(
                        Action::make('Recalculate Price')
                            ->action(function (Component $component, Service $service) {
                                if ($service) {
                                    Notification::make('Price Recalculated')
                                        ->title('The price has been successfully recalculated')
                                        ->success()
                                        ->send();
                                    // Update the form field
                                    $component->state($service->calculatePrice());
                                }
                            })
                            ->label('Recalculate Price')
                            ->icon('ri-refresh-line')
                    ),
                Select::make('billing_agreement_id')
                    ->label('Billing Agreement')
                    ->relationship('billingAgreement', 'name', fn (Builder $query, Get $get) => $query->where('user_id', $get('user_id')))
                    ->searchable()
                    ->preload()
                    ->placeholder('Select the billing agreement'),
                TextInput::make('subscription_id')
                    ->label('Subscription ID (deprecated)')
                    ->nullable()
                    ->placeholder('Enter the subscription ID')
                    ->hintAction(
                        Action::make('Cancel Subscription ID')
                            ->action(function (Component $component) {
                                if (ExtensionHelper::cancelSubscription($component->getRecord())) {
                                    Notification::make('Subscription Cancelled')
                                        ->title('The subscription has been successfully cancelled')
                                        ->success()
                                        ->send();
                                } else {
                                    Notification::make('Subscription Not Cancelled')
                                        ->title('The subscription could not be cancelled')
                                        ->error()
                                        ->send();
                                }
                                // Update the record to remove the subscription ID
                                $component->getRecord()->update(['subscription_id' => null]);
                            })
                            ->requiresConfirmation()
                            ->label('Cancel Subscription')
                            ->hidden(fn (Component $component) => !$component->getRecord()?->subscription_id),
                    ),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable(true, fn (Builder $query, string $search) => $query->whereHas('user', fn (Builder $query) => $query->where('first_name', 'like', "%$search%")->orWhere('last_name', 'like', "%$search%"))),
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (Service $record) => match ($record->status) {
                        'pending' => 'gray',
                        'active' => 'success',
                        'cancelled' => 'danger',
                        'suspended' => 'warning',
                    })
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->label('Status')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label('Expires At')
                    ->date()
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'pending' => 'Pending',
                        'suspended' => 'Suspended',
                        'cancelled' => 'Cancelled',
                    ]),
                SelectFilter::make('user')
                    ->label('User')
                    ->relationship('user', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name . ' (' . $record->email . ')')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('product')
                    ->label('Product')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('expires_at')
                    ->form([
                        DatePicker::make('expires_from')->label('Expires From'),
                        DatePicker::make('expires_until')->label('Expires Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['expires_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('expires_at', '>=', $date),
                            )
                            ->when(
                                $data['expires_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('expires_at', '<=', $date),
                            );
                    }),
            ])
            ->defaultSort(function (Builder $query): Builder {
                return $query
                    ->orderBy('id', 'desc');
            })
            ->recordActions([
                EditAction::make(),
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

    public static function getRelations(): array
    {
        return [
            InvoicesRelationManager::class,
            PropertiesRelationManager::class,
            ConfigOptionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListService::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }
}
