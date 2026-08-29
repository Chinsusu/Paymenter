<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Admin\Services\CreateServiceRequest;
use App\Http\Requests\Api\Admin\Services\DeleteServiceRequest;
use App\Http\Requests\Api\Admin\Services\GetServiceRequest;
use App\Http\Requests\Api\Admin\Services\GetServicesRequest;
use App\Http\Requests\Api\Admin\Services\UpdateServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Jobs\Server\TerminateJob;
use App\Models\ProviderLocationOffering;
use App\Models\Service;
use App\Services\LocationAvailabilityService;
use App\Services\Service\ProviderOperationLifecycleService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\QueryBuilder;

#[Group(name: 'Services', weight: 3)]
class ServiceController extends ApiController
{
    protected const INCLUDES = [
        'order',
        'coupon',
        'user',
        'product',
        'invoices',
        'properties',
    ];

    /**
     * List Services
     */
    #[QueryParameter('per_page', 'How many items to show per page.', type: 'int', default: 15, example: 20)]
    #[QueryParameter('page', 'Which page to show.', type: 'int', example: 2)]
    public function index(GetServicesRequest $request)
    {
        // Fetch services with pagination
        $services = QueryBuilder::for(Service::class)
            ->allowedFilters(['quantity', 'price', 'expires_at', 'subscription_id', 'status'])
            ->allowedIncludes($this->allowedIncludes(self::INCLUDES))
            ->allowedSorts(['id', 'created_at', 'updated_at', 'expires_at', 'status'])
            ->simplePaginate(request('per_page', 15));

        // Return the services as a JSON response
        return ServiceResource::collection($services);
    }

    /**
     * Create a new service
     */
    public function store(CreateServiceRequest $request)
    {
        $service = DB::transaction(function () use ($request) {
            $data = $request->validated();
            $productLocationOfferingId = Arr::pull($data, 'product_location_offering_id');
            $service = Service::create($data);

            if ($service->product?->server?->extension === 'HAVProxyIPv4DC') {
                $service->properties()->create([
                    'key' => 'product_location_offering_id',
                    'name' => 'Product location offering ID',
                    'value' => (string) $productLocationOfferingId,
                ]);
                LocationAvailabilityService::snapshotProductOffering(
                    $service,
                    (int) $productLocationOfferingId,
                    ProviderLocationOffering::SERVICE_PROXY,
                );
            }

            return $service;
        });

        // Return the created service as a JSON response
        return new ServiceResource($service);
    }

    /**
     * Show a specific service
     */
    public function show(GetServiceRequest $request, Service $service)
    {
        $service = QueryBuilder::for(Service::class)
            ->allowedIncludes($this->allowedIncludes(self::INCLUDES))
            ->findOrFail($service->id);

        // Return the service as a JSON response
        return new ServiceResource($service);
    }

    /**
     * Update a specific service
     */
    public function update(UpdateServiceRequest $request, Service $service)
    {
        // Validate and update the service
        $service->update($request->validated());

        // Return the updated service as a JSON response
        return new ServiceResource($service);
    }

    /**
     * Delete a specific service
     */
    public function destroy(DeleteServiceRequest $request, Service $service)
    {
        if (ProviderOperationLifecycleService::usesDeferredOperations($service) && $service->status !== Service::STATUS_CANCELLED) {
            TerminateJob::dispatch($service);

            return response()->json([
                'message' => 'Provider termination was queued. Delete the service after it reaches cancelled status.',
            ], 202);
        }

        // Delete the service
        $service->delete();

        return $this->returnNoContent();
    }
}
