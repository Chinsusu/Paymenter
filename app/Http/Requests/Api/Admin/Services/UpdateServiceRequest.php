<?php

namespace App\Http\Requests\Api\Admin\Services;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Services\Service\ProviderOperationLifecycleService;

class UpdateServiceRequest extends AdminApiRequest
{
    protected $permission = 'services.update';

    public function rules(): array
    {
        $service = $this->route('service');
        $targetProduct = Product::with('server')->find($this->input('product_id', $service?->product_id));
        $currentIsHavProxy = $service && ProviderOperationLifecycleService::usesDeferredOperations($service);
        $targetIsHavProxy = $targetProduct?->server?->extension === 'HAVProxyIPv4DC';

        return [
            'product_id' => [
                'sometimes',
                'required',
                'exists:products,id',
                function ($attribute, $value, $fail) use ($service, $currentIsHavProxy, $targetIsHavProxy) {
                    if ($service && (int) $value !== (int) $service->product_id && ($currentIsHavProxy || $targetIsHavProxy)) {
                        $fail('The product cannot be changed for a provider-snapshotted service.');
                    }
                },
            ],
            'plan_id' => [
                'sometimes',
                'required',
                'exists:plans,id',
                function ($attribute, $value, $fail) {
                    $productId = $this->input('product_id');
                    if ($productId && !Plan::where('id', $value)->where('priceable_type', Product::class)->where('priceable_id', $productId)->exists()) {
                        // Check if the plan belongs to the specified product
                        $fail('The selected plan does not belong to the specified product.');
                    }
                },
            ],
            'user_id' => 'sometimes|required|exists:users,id',
            /**
             * @default 1
             */
            'quantity' => array_filter(['sometimes', 'required', 'integer', 'min:1', ($currentIsHavProxy || $targetIsHavProxy) ? 'max:1' : null]),
            /**
             * @default pending
             */
            'status' => [
                'sometimes',
                'required',
                'in:pending,active,cancelled,suspended',
                function ($attribute, $value, $fail) use ($service, $currentIsHavProxy) {
                    if ($service && $currentIsHavProxy && $value !== $service->status) {
                        $fail('Use provider actions to change the status of this service.');
                    }
                },
            ],
            'expires_at' => 'sometimes|nullable|date|after_or_equal:today',
            /**
             * @example USD
             */
            'currency_code' => 'sometimes|required|string|exists:currencies,code',
            'price' => 'sometimes|required|numeric|min:0',
            'coupon_id' => 'sometimes|nullable|exists:coupons,id',
            'subscription_id' => 'sometimes|nullable|string|max:255',
            'order_id' => 'sometimes|nullable|exists:orders,id',
        ];
    }
}
