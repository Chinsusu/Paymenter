<?php

namespace App\Admin\Resources\OrderResource\Pages;

use App\Admin\Resources\OrderResource;
use App\Models\Invoice;
use App\Models\ProviderLocationOffering;
use App\Services\LocationAvailabilityService;
use Filament\Resources\Pages\CreateRecord;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected function afterCreate(): void
    {
        $this->storeServiceCheckoutProperties();

        $invoice = new Invoice([
            'user_id' => $this->record->user_id,
            'currency_code' => $this->record->currency_code,
            'due_at' => now()->addDays(7),
        ]);
        $invoice->save();

        foreach ($this->record->services as $service) {
            $invoice->items()->create([
                'description' => $service->description,
                'price' => $service->price,
                'quantity' => $service->quantity,
                'reference_id' => $service->id,
                'reference_type' => get_class($service),
            ]);
        }

    }

    private function storeServiceCheckoutProperties(): void
    {
        $servicesData = collect($this->data['services'] ?? [])->values();
        $services = $this->record->services()->orderBy('id')->get()->values();

        foreach ($services as $index => $service) {
            $serviceData = $servicesData->get($index, []);
            $productLocationOfferingId = $serviceData['product_location_offering_id'] ?? null;

            if (!$productLocationOfferingId) {
                continue;
            }

            $service->properties()->updateOrCreate([
                'key' => 'product_location_offering_id',
            ], [
                'name' => 'Product location offering ID',
                'value' => (string) $productLocationOfferingId,
            ]);

            if ($service->product?->server?->extension === 'HAVProxyIPv4DC') {
                LocationAvailabilityService::snapshotProductOffering(
                    $service,
                    (int) $productLocationOfferingId,
                    ProviderLocationOffering::SERVICE_PROXY,
                );
            }
        }
    }
}
