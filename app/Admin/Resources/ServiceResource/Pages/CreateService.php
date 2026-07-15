<?php

namespace App\Admin\Resources\ServiceResource\Pages;

use App\Admin\Resources\ServiceResource;
use App\Models\ProviderLocationOffering;
use App\Services\LocationAvailabilityService;
use Filament\Resources\Pages\CreateRecord;

class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    private ?int $productLocationOfferingId = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->productLocationOfferingId = isset($data['product_location_offering_id'])
            ? (int) $data['product_location_offering_id']
            : null;
        unset($data['product_location_offering_id']);

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->record->product?->server?->extension !== 'HAVProxyIPv4DC') {
            return;
        }

        $this->record->properties()->create([
            'key' => 'product_location_offering_id',
            'name' => 'Product location offering ID',
            'value' => (string) $this->productLocationOfferingId,
        ]);
        LocationAvailabilityService::snapshotProductOffering(
            $this->record,
            (int) $this->productLocationOfferingId,
            ProviderLocationOffering::SERVICE_PROXY,
        );
    }
}
