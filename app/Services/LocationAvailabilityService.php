<?php

namespace App\Services;

use App\Models\LocationOption;
use App\Models\Product;
use App\Models\ProductLocationOffering;
use App\Models\ProviderLocationOffering;
use App\Models\ProviderLocationTarget;
use App\Models\Service;
use Exception;
use Illuminate\Database\Eloquent\Collection;

class LocationAvailabilityService
{
    public static function forProvider(int $providerId, ?string $serviceType = null, bool $enabledOnly = true): Collection
    {
        return ProviderLocationOffering::query()
            ->with(['locationOption.primaryGroup', 'targets'])
            ->where('provider_id', $providerId)
            ->when($serviceType, fn ($query) => $query->where('service_type', $serviceType))
            ->when($enabledOnly, fn ($query) => $query->where('enabled', true))
            ->whereHas('locationOption', fn ($query) => $query->where('status', LocationOption::STATUS_ACTIVE))
            ->orderBy('service_type')
            ->get()
            ->sortBy([
                fn (ProviderLocationOffering $offering) => $offering->locationOption->primaryGroup?->sort_order ?? PHP_INT_MAX,
                fn (ProviderLocationOffering $offering) => $offering->locationOption->sort_order,
                fn (ProviderLocationOffering $offering) => $offering->locationOption->display_name,
            ])
            ->values();
    }

    public static function forProduct(int|Product $product, ?string $serviceType = null, bool $enabledOnly = true): Collection
    {
        $productId = $product instanceof Product ? $product->id : $product;

        return ProductLocationOffering::query()
            ->with(['providerLocationOffering.locationOption.primaryGroup', 'providerLocationOffering.targets'])
            ->where('product_id', $productId)
            ->when($enabledOnly, fn ($query) => $query->where('enabled', true))
            ->whereHas('providerLocationOffering', function ($query) use ($serviceType, $enabledOnly) {
                $query
                    ->when($serviceType, fn ($query) => $query->where('service_type', $serviceType))
                    ->when($enabledOnly, fn ($query) => $query->where('enabled', true))
                    ->whereHas('locationOption', fn ($query) => $query->where('status', LocationOption::STATUS_ACTIVE));
            })
            ->orderBy('sort_order')
            ->get()
            ->sortBy([
                fn (ProductLocationOffering $offering) => $offering->providerLocationOffering->locationOption->primaryGroup?->sort_order ?? PHP_INT_MAX,
                fn (ProductLocationOffering $offering) => $offering->sort_order,
                fn (ProductLocationOffering $offering) => $offering->providerLocationOffering->locationOption->display_name,
            ])
            ->values();
    }

    public static function resolveTarget(int|ProviderLocationOffering $providerLocationOffering): ?ProviderLocationTarget
    {
        $providerLocationOfferingId = $providerLocationOffering instanceof ProviderLocationOffering
            ? $providerLocationOffering->id
            : $providerLocationOffering;

        return ProviderLocationTarget::query()
            ->where('provider_location_offering_id', $providerLocationOfferingId)
            ->where('status', ProviderLocationTarget::STATUS_ACTIVE)
            ->orderByDesc('priority')
            ->orderByDesc('weight')
            ->orderBy('id')
            ->first();
    }

    public static function snapshotSelection(Service $service, int|ProviderLocationOffering $providerLocationOffering): array
    {
        $offering = $providerLocationOffering instanceof ProviderLocationOffering
            ? $providerLocationOffering->loadMissing('locationOption', 'targets')
            : ProviderLocationOffering::with(['locationOption', 'targets'])->findOrFail($providerLocationOffering);

        $target = self::resolveTarget($offering);

        $snapshot = [
            'location_option_id' => (string) $offering->location_option_id,
            'provider_location_offering_id' => (string) $offering->id,
            'external_location_code' => (string) ($target?->external_location_code ?? $target?->external_location_id ?? ''),
            'display_name' => $offering->locationOption->display_name,
        ];

        foreach ($snapshot as $key => $value) {
            $service->properties()->updateOrCreate([
                'key' => $key,
            ], [
                'name' => str_replace('_', ' ', $key),
                'value' => $value,
            ]);
        }

        return $snapshot;
    }

    /**
     * Freeze the selected provider target while the customer order is created.
     * Provisioning must not depend on mutable product mappings after payment.
     */
    public static function snapshotProductOffering(Service $service, int $productLocationOfferingId, ?string $serviceType = null): array
    {
        $productOffering = ProductLocationOffering::query()
            ->with(['providerLocationOffering.locationOption', 'providerLocationOffering.targets'])
            ->whereKey($productLocationOfferingId)
            ->where('product_id', $service->product_id)
            ->where('enabled', true)
            ->first();

        if (!$productOffering) {
            throw new Exception('Selected location is not enabled for this product.');
        }

        $providerOffering = $productOffering->providerLocationOffering;
        if (!$providerOffering || $providerOffering->provider_id !== $service->product->server_id) {
            throw new Exception('Selected location belongs to a different provider.');
        }
        if ($serviceType && $providerOffering->service_type !== $serviceType) {
            throw new Exception('Selected location is not available for this service type.');
        }
        if (!$providerOffering->enabled || $providerOffering->stock_state === ProviderLocationOffering::STOCK_UNAVAILABLE) {
            throw new Exception('Selected location is out of stock.');
        }

        $target = self::resolveTarget($providerOffering);
        if (!$target?->external_location_code) {
            throw new Exception('Selected location is missing provider group mapping.');
        }

        return self::snapshotSelection($service, $providerOffering);
    }

    public static function checkoutOptionsForProduct(int|Product $product, ?string $serviceType = null): array
    {
        return self::forProduct($product, $serviceType)
            ->mapWithKeys(function (ProductLocationOffering $productOffering) {
                $providerOffering = $productOffering->providerLocationOffering;
                $location = $providerOffering->locationOption;
                $group = $location->primaryGroup?->name ?? 'Other';

                return [
                    $productOffering->id => [
                        'label' => $location->display_name,
                        'group' => $group,
                        'provider_location_offering_id' => $providerOffering->id,
                        'price_delta' => $productOffering->price_delta,
                    ],
                ];
            })
            ->all();
    }
}
