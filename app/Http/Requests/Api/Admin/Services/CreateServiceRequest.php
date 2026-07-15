<?php

namespace App\Http\Requests\Api\Admin\Services;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductLocationOffering;
use App\Models\ProviderLocationOffering;
use App\Services\LocationAvailabilityService;
use Illuminate\Validation\Rule;

class CreateServiceRequest extends AdminApiRequest
{
    protected $permission = 'services.create';

    public function rules(): array
    {
        $product = Product::with('server')->find($this->input('product_id'));
        $isHavProxy = $product?->server?->extension === 'HAVProxyIPv4DC';

        return [
            'product_id' => 'required|exists:products,id',
            'plan_id' => [
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
            'user_id' => 'required|exists:users,id',
            /**
             * @default 1
             */
            'quantity' => array_filter(['required', 'integer', 'min:1', $isHavProxy ? 'max:1' : null]),
            'product_location_offering_id' => [
                Rule::requiredIf($isHavProxy),
                'nullable',
                'integer',
                Rule::exists('product_location_offerings', 'id')->where(fn ($query) => $query
                    ->where('product_id', $this->input('product_id'))
                    ->where('enabled', true)),
                function ($attribute, $value, $fail) use ($isHavProxy, $product) {
                    if (!$isHavProxy || !$value) {
                        return;
                    }

                    $offering = ProductLocationOffering::with('providerLocationOffering')
                        ->whereKey($value)
                        ->where('product_id', $product->id)
                        ->where('enabled', true)
                        ->first();
                    $providerOffering = $offering?->providerLocationOffering;
                    if (
                        !$providerOffering
                        || $providerOffering->provider_id !== $product->server_id
                        || $providerOffering->service_type !== ProviderLocationOffering::SERVICE_PROXY
                        || !$providerOffering->isSellable()
                        || !LocationAvailabilityService::resolveTarget($providerOffering)
                    ) {
                        $fail('The selected proxy location is unavailable.');
                    }
                },
            ],
            /**
             * @default pending
             */
            'status' => [
                'required',
                'in:pending,active,cancelled,suspended',
                function ($attribute, $value, $fail) use ($isHavProxy) {
                    if ($isHavProxy && $value !== 'pending') {
                        $fail('HAV Proxy IPv4 DC services must be created in pending status.');
                    }
                },
            ],
            'expires_at' => 'nullable|date|after_or_equal:today',
            /**
             * @example USD
             */
            'currency_code' => 'required|string|exists:currencies,code',
            'price' => 'required|numeric|min:0',
            'coupon_id' => 'nullable|exists:coupons,id',
            'subscription_id' => 'nullable|string|max:255',
            'order_id' => 'nullable|exists:orders,id',
        ];
    }

    public function prepareForValidation()
    {
        $this->mergeIfMissing([
            'quantity' => 1, // Default quantity to 1 if not provided
            'status' => 'pending', // Default status to 'pending' if not provided
        ]);
    }
}
