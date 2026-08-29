<?php

namespace App\Admin\Resources;

use App\Admin\Components\UserComponent;
use App\Admin\Resources\OrderResource\Pages\CreateOrder;
use App\Admin\Resources\OrderResource\Pages\EditOrder;
use App\Admin\Resources\OrderResource\Pages\ListOrders;
use App\Admin\Resources\OrderResource\RelationManagers\ServiceRelationManager;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\TerminateJob;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductLocationOffering;
use App\Models\ProviderLocationOffering;
use App\Models\Service;
use App\Services\LocationAvailabilityService;
use App\Services\Service\ProviderOperationLifecycleService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-shopping-bag-4-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-shopping-bag-4-fill';

    public static string|\UnitEnum|null $navigationGroup = 'Administration';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                UserComponent::make('user_id')
                    ->afterStateUpdated(function (Set $set, Get $get) {
                        // update all the services user_id
                        $set('services', collect($get('services'))->map(fn ($service) => array_merge($service, ['user_id' => $get('user_id')]))->toArray());
                    }),
                Select::make('currency_code')
                    ->label('Currency')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, Get $get) {
                        // update all the services currency_code
                        $set('services', collect($get('services'))->map(fn ($service) => array_merge($service, ['currency_code' => $get('currency_code')]))->toArray());
                    })
                    ->options(Currency::query()->pluck('code', 'code'))
                    ->helperText('Does not convert the price, only displays the currency symbol')
                    ->placeholder('Select the currency'),
                Repeater::make('services')
                    ->relationship('services')
                    ->label('Services')
                    ->addable(fn (?Order $record) => $record === null)
                    ->deletable(fn (?Order $record) => $record === null)
                    ->columnSpanFull()
                    ->columns(2)
                    ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                        unset($data['product_location_offering_id']);

                        return $data;
                    })
                    ->schema([
                        Hidden::make('user_id')
                            ->default(fn (Get $get) => $get('../../user_id')),
                        Hidden::make('currency_code')
                            ->default(fn (Get $get) => $get('../../currency_code')),
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
                            ->preload()
                            ->live()
                            ->disabled(fn (?Service $record) => $record && ProviderOperationLifecycleService::usesDeferredOperations($record))
                            ->afterStateUpdated(function (Set $set, $state) {
                                $set('plan_id', null);
                                $set('product_location_offering_id', null);
                                if (self::isHavProxyProduct((int) $state)) {
                                    $set('quantity', 1);
                                }
                            })
                            ->placeholder('Select the product'),
                        Select::make('product_location_offering_id')
                            ->label('Location')
                            ->options(fn (Get $get) => self::productLocationOptions($get('product_id')))
                            ->searchable()
                            ->preload()
                            ->hidden(fn (?Service $record, Get $get) => $record || (!self::isHavProxyProduct($get('product_id')) && self::productLocationOptions($get('product_id')) === []))
                            ->disabled(fn (Get $get) => self::isHavProxyProduct($get('product_id')) && self::productLocationOptions($get('product_id')) === [])
                            ->required(fn (Get $get) => self::isHavProxyProduct($get('product_id')) || self::productLocationOptions($get('product_id')) !== [])
                            ->helperText(fn (Get $get) => self::isHavProxyProduct($get('product_id')) && self::productLocationOptions($get('product_id')) === []
                                ? 'No provider location is currently available.'
                                : null)
                            ->placeholder('Select the location'),
                        Select::make('plan_id')
                            ->label('Plan')
                            ->required()
                            ->relationship('plan', 'name', fn (Builder $query, Get $get) => $query->where('priceable_type', Product::class)->where('priceable_id', $get('product_id')))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->disabled(fn (Get $get) => (!$get('product_id') || !$get('../../currency_code')))
                            ->afterStateUpdated(function (Set $set, Get $get) {
                                if (!$get('product_id') || !$get('plan_id') || !$get('../../currency_code')) {
                                    return;
                                }
                                // Update the price when the plan changes
                                $plan = Product::find($get('product_id'))->plans->find($get('plan_id'))->prices->where('currency_code', $get('../../currency_code'))->first();
                                if (!$plan) {
                                    return;
                                }
                                $set('price', $plan->price);
                            })
                            ->placeholder('Select the plan'),
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->required()
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(fn (?Service $record, Get $get) => ($record && ProviderOperationLifecycleService::usesDeferredOperations($record)) || self::isHavProxyProduct($get('product_id')) ? 1 : null)
                            ->disabled(fn (?Service $record) => $record && ProviderOperationLifecycleService::usesDeferredOperations($record))
                            ->placeholder('Enter the quantity'),
                        TextInput::make('price')
                            ->suffix(fn (Component $component, Get $get) => $component->getRecord()?->currency->suffix ?? Currency::where('code', $get('../../currency_code'))->first()?->suffix)
                            ->prefix(fn (Component $component, Get $get) => $component->getRecord()?->currency->prefix ?? Currency::where('code', $get('../../currency_code'))->first()?->prefix)
                            ->label('Price')
                            ->required()
                            ->mask(RawJs::make(
                                <<<'JS'
                                    $money($input, '.', '', 2)
                                JS
                            ))
                            ->placeholder('Enter the price'),
                        TextInput::make('subscription_id')
                            ->label('Subscription ID')
                            ->nullable()
                            ->placeholder('Enter the subscription ID')
                            ->hintActions([
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
                            ]),
                    ]),
            ]);
    }

    public static function productLocationOptions(?int $productId): array
    {
        if (!$productId) {
            return [];
        }

        $product = Product::find($productId);
        if (!$product?->server_id) {
            return [];
        }

        return LocationAvailabilityService::forProduct($product, ProviderLocationOffering::SERVICE_PROXY)
            ->filter(fn (ProductLocationOffering $offering) => $offering->providerLocationOffering->isSellable())
            ->filter(fn (ProductLocationOffering $offering) => LocationAvailabilityService::resolveTarget($offering->providerLocationOffering) !== null)
            ->mapWithKeys(function (ProductLocationOffering $offering) {
                $location = $offering->providerLocationOffering->locationOption;
                $group = $location->primaryGroup?->name;
                $label = $group ? $group . ' / ' . $location->display_name : $location->display_name;

                return [$offering->id => $label];
            })
            ->all();
    }

    public static function isHavProxyProduct(?int $productId): bool
    {
        return $productId
            ? Product::query()->whereKey($productId)->whereHas('server', fn ($query) => $query->where('extension', 'HAVProxyIPv4DC'))->exists()
            : false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable(query: fn (Builder $query, $search) => $query->whereHas('user', fn (Builder $query) => $query->where('first_name', 'like', "%$search%")->orWhere('last_name', 'like', "%$search%"))),
                TextColumn::make('currency.code')
                    ->label('Currency')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('formattedTotal')
                    ->label('Total'),
                TextColumn::make('updated_at')
                    ->label('Updated At')
                    ->searchable()
                    ->sortable(),
            ])
            ->defaultSort(function (Builder $query): Builder {
                return $query
                    ->orderBy('id', 'desc');
            })
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function (Collection $records, DeleteBulkAction $action): void {
                            $providerServices = $records
                                ->load('services')
                                ->pluck('services')
                                ->flatten()
                                ->filter(fn (Service $service) => $service->status !== Service::STATUS_CANCELLED
                                    && ProviderOperationLifecycleService::usesDeferredOperations($service));
                            if ($providerServices->isEmpty()) {
                                return;
                            }

                            $providerServices->each(fn (Service $service) => TerminateJob::dispatch($service));
                            Notification::make('Provider terminations queued')
                                ->title('Delete these orders again after their services reach cancelled status.')
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
            ServiceRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }
}
