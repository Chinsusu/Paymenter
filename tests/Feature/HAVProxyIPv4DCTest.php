<?php

namespace Tests\Feature;

use App\Models\LocationOption;
use App\Models\ProductLocationOffering;
use App\Models\ProviderLocationOffering;
use App\Models\ProviderLocationTarget;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Paymenter\Extensions\Servers\HAVProxyIPv4DC\HAVProxyIPv4DC;
use Paymenter\Extensions\Servers\HAVProxyIPv4DC\V3Client;
use Tests\TestCase;

class HAVProxyIPv4DCTest extends TestCase
{
    use RefreshDatabase;

    public function test_v3_client_sends_base_url_auth_and_idempotency_headers(): void
    {
        Http::fake([
            'https://edge.example.com/api/v3/proxies' => Http::response([
                'resource' => ['id' => 'proxy_1'],
            ], 202),
        ]);

        $client = new V3Client([
            'key' => 'primary',
            'base_url' => 'https://edge.example.com/api/v3',
            'api_token' => 'secret-token',
        ]);

        $client->createProxy([
            'kind' => 'ipv4_dc',
            'group_id' => 'group_us',
        ], 'idem-1');

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://edge.example.com/api/v3/proxies'
                && $request->hasHeader('Authorization', 'Bearer secret-token')
                && $request->hasHeader('Idempotency-Key', 'idem-1')
                && $request['kind'] === 'ipv4_dc'
                && $request['group_id'] === 'group_us';
        });
    }

    public function test_sync_location_targets_maps_inventory_groups_to_location_catalog(): void
    {
        Http::fake([
            'https://edge.example.com/api/v3/inventory/groups*' => Http::response([
                'data' => [
                    [
                        'id' => 'group_us',
                        'billing_group_id' => 'billing-us',
                        'name' => 'US Pool',
                        'status' => 'available',
                        'available_count' => 12,
                    ],
                ],
            ]),
        ]);

        $provider = $this->createProvider();
        $extension = $this->extension([
            'location_map' => [
                'billing-us' => 'united-states',
            ],
        ]);

        $stats = $extension->syncLocationTargets($provider);
        $location = LocationOption::where('code', 'united-states')->firstOrFail();
        $offering = ProviderLocationOffering::where('provider_id', $provider->id)
            ->where('location_option_id', $location->id)
            ->where('service_type', ProviderLocationOffering::SERVICE_PROXY)
            ->firstOrFail();

        $this->assertSame(1, $stats['created']);
        $this->assertSame(ProviderLocationOffering::STOCK_AVAILABLE, $offering->stock_state);
        $this->assertDatabaseHas('provider_location_targets', [
            'provider_location_offering_id' => $offering->id,
            'external_location_id' => 'group_us',
            'external_location_code' => 'billing-us',
            'external_name' => 'US Pool',
        ]);
        $this->assertSame('primary', $offering->targets()->first()->raw_payload['base_url_key']);
    }

    public function test_sync_location_targets_keeps_same_external_code_per_base_url_profile(): void
    {
        Http::fake([
            'https://edge-a.example.com/api/v3/inventory/groups*' => Http::response([
                'data' => [
                    [
                        'id' => 'group_us_a',
                        'billing_group_id' => 'billing-us',
                        'name' => 'US Pool A',
                        'status' => 'available',
                    ],
                ],
            ]),
            'https://edge-b.example.com/api/v3/inventory/groups*' => Http::response([
                'data' => [
                    [
                        'id' => 'group_us_b',
                        'billing_group_id' => 'billing-us',
                        'name' => 'US Pool B',
                        'status' => 'available',
                    ],
                ],
            ]),
        ]);

        $provider = $this->createProvider();
        $extension = $this->extension([
            'base_url_profiles' => [
                [
                    'key' => 'primary',
                    'base_url' => 'https://edge-a.example.com',
                    'api_token' => 'secret-token',
                ],
                [
                    'key' => 'secondary',
                    'base_url' => 'https://edge-b.example.com',
                    'api_token' => 'secret-token',
                ],
            ],
            'location_map' => [
                'billing-us' => 'united-states',
            ],
        ]);

        $extension->syncLocationTargets($provider);

        $location = LocationOption::where('code', 'united-states')->firstOrFail();
        $offering = ProviderLocationOffering::where('provider_id', $provider->id)
            ->where('location_option_id', $location->id)
            ->where('service_type', ProviderLocationOffering::SERVICE_PROXY)
            ->firstOrFail();
        $targets = $offering->targets()->get();

        $this->assertCount(2, $targets);
        $this->assertSame(['primary', 'secondary'], $targets->pluck('raw_payload.base_url_key')->sort()->values()->all());
        $this->assertSame(['group_us_a', 'group_us_b'], $targets->pluck('external_location_id')->sort()->values()->all());
    }

    public function test_sync_location_targets_disables_stale_targets_and_marks_offering_unavailable(): void
    {
        Http::fake([
            'https://edge.example.com/api/v3/inventory/groups*' => Http::sequence()
                ->push([
                    'data' => [
                        [
                            'id' => 'group_us',
                            'billing_group_id' => 'billing-us',
                            'name' => 'US Pool',
                            'status' => 'available',
                        ],
                    ],
                ])
                ->push([
                    'data' => [],
                ]),
        ]);

        $provider = $this->createProvider();
        $extension = $this->extension([
            'location_map' => [
                'billing-us' => 'united-states',
            ],
        ]);

        $extension->syncLocationTargets($provider);
        $stats = $extension->syncLocationTargets($provider);

        $this->assertSame(1, $stats['disabled_targets']);
        $this->assertSame(1, $stats['unavailable_offerings']);
        $this->assertDatabaseHas('provider_location_targets', [
            'external_location_code' => 'billing-us',
            'status' => ProviderLocationTarget::STATUS_DISABLED,
        ]);
        $this->assertDatabaseHas('provider_location_offerings', [
            'provider_id' => $provider->id,
            'service_type' => ProviderLocationOffering::SERVICE_PROXY,
            'stock_state' => ProviderLocationOffering::STOCK_UNAVAILABLE,
        ]);
    }

    public function test_checkout_config_only_exposes_enabled_product_proxy_locations(): void
    {
        [$provider, $product, $productOffering] = $this->createMappedProductOffering();
        $unavailable = $this->createProviderOffering($provider, 'canada', ProviderLocationOffering::STOCK_UNAVAILABLE);

        ProductLocationOffering::create([
            'product_id' => $product->id,
            'provider_location_offering_id' => $unavailable->id,
            'enabled' => true,
        ]);

        $config = $this->extension()->getCheckoutConfig($product);

        $this->assertCount(1, $config);
        $this->assertArrayHasKey($productOffering->id, $config[0]['options']);
        $this->assertCount(1, $config[0]['options']);
    }

    public function test_checkout_config_blocks_checkout_when_no_locations_are_available(): void
    {
        $provider = $this->createProvider();
        $product = $this->createProduct([
            'server_id' => $provider->id,
        ])->product;

        $config = $this->extension()->getCheckoutConfig($product);

        $this->assertCount(1, $config);
        $this->assertSame('Location', $config[0]['label']);
        $this->assertTrue($config[0]['required']);
        $this->assertNull($config[0]['default']);
        $this->assertSame([], $config[0]['options']);
    }

    public function test_create_server_rejects_tampered_location_from_another_product(): void
    {
        [$provider, , $productOffering] = $this->createMappedProductOffering();
        $otherProduct = $this->createProduct([
            'server_id' => $provider->id,
        ])->product;
        $service = $this->createService($otherProduct);

        $this->expectExceptionMessage('Selected location is not enabled for this product.');

        $this->extension()->createServer($service, [
            'protocol' => 'socks5',
        ], [
            'product_location_offering_id' => $productOffering->id,
        ]);
    }

    public function test_create_server_provisions_async_and_snapshots_location(): void
    {
        [$provider, $product, $productOffering] = $this->createMappedProductOffering();
        $service = $this->createService($product);

        Http::fake([
            'https://edge.example.com/api/v3/proxies' => Http::response([
                'operation' => ['id' => 'op_create'],
                'resource' => ['id' => 'proxy_1'],
            ], 202),
            'https://edge.example.com/api/v3/operations/op_create' => Http::response([
                'status' => 'succeeded',
                'resource' => ['id' => 'proxy_1'],
            ]),
            'https://edge.example.com/api/v3/proxies/proxy_1' => Http::response([
                'data' => [
                    'id' => 'proxy_1',
                    'kind' => 'ipv4_dc',
                    'node_id' => 'node_1',
                    'status' => 'active',
                    'host' => 'proxy.example.com',
                    'outbound_ip' => '198.51.100.10',
                    'port_socks' => 1080,
                    'port_http' => 8080,
                    'username' => 'user',
                    'password' => 'pass',
                    'connection_uri' => 'socks5://user:pass@proxy.example.com:1080',
                ],
            ]),
        ]);

        $result = $this->extension()->createServer($service, [
            'protocol' => 'socks5',
            'speed_limit_mbps' => 100,
        ], [
            'product_location_offering_id' => $productOffering->id,
        ]);

        $this->assertSame('proxy_1', $result['id']);
        $this->assertDatabaseHas('properties', [
            'model_id' => $service->id,
            'model_type' => $service->getMorphClass(),
            'key' => 'hav_proxy_id',
            'value' => 'proxy_1',
        ]);
        $this->assertDatabaseHas('properties', [
            'model_id' => $service->id,
            'model_type' => $service->getMorphClass(),
            'key' => 'display_name',
            'value' => 'United States',
        ]);
        $this->assertDatabaseHas('properties', [
            'model_id' => $service->id,
            'model_type' => $service->getMorphClass(),
            'key' => 'external_location_code',
            'value' => 'billing-us',
        ]);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://edge.example.com/api/v3/proxies'
                && $request['kind'] === 'ipv4_dc'
                && $request['group_id'] === 'group_us'
                && $request['protocol'] === 'socks5'
                && $request['speed_limit_mbps'] === 100;
        });
    }

    public function test_lifecycle_actions_stop_start_and_delete_proxy(): void
    {
        [, $product] = $this->createMappedProductOffering();
        $service = $this->createService($product);
        $service->properties()->create([
            'key' => 'hav_proxy_id',
            'name' => 'HAV Proxy ID',
            'value' => 'proxy_1',
        ]);
        $service->properties()->create([
            'key' => 'hav_proxy_base_url_key',
            'name' => 'HAV Proxy Base URL Key',
            'value' => 'primary',
        ]);

        Http::fake([
            'https://edge.example.com/api/v3/proxies/proxy_1/actions/stop' => Http::response([
                'operation' => ['id' => 'op_stop'],
            ], 202),
            'https://edge.example.com/api/v3/operations/op_stop' => Http::response([
                'status' => 'succeeded',
            ]),
            'https://edge.example.com/api/v3/proxies/proxy_1/actions/start' => Http::response([
                'operation' => ['id' => 'op_start'],
            ], 202),
            'https://edge.example.com/api/v3/operations/op_start' => Http::response([
                'status' => 'succeeded',
            ]),
            'https://edge.example.com/api/v3/proxies/proxy_1' => Http::response([
                'operation' => ['id' => 'op_delete'],
            ], 202),
            'https://edge.example.com/api/v3/operations/op_delete' => Http::response([
                'status' => 'succeeded',
            ]),
        ]);

        $extension = $this->extension();
        $extension->suspendServer($service, [], [
            'hav_proxy_id' => 'proxy_1',
            'hav_proxy_base_url_key' => 'primary',
        ]);
        $extension->unsuspendServer($service, [], [
            'hav_proxy_id' => 'proxy_1',
            'hav_proxy_base_url_key' => 'primary',
        ]);
        $extension->terminateServer($service, [], [
            'hav_proxy_id' => 'proxy_1',
            'hav_proxy_base_url_key' => 'primary',
        ]);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'https://edge.example.com/api/v3/proxies/proxy_1/actions/stop');
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'https://edge.example.com/api/v3/proxies/proxy_1/actions/start');
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === 'https://edge.example.com/api/v3/proxies/proxy_1');
        $this->assertDatabaseHas('properties', [
            'model_id' => $service->id,
            'model_type' => $service->getMorphClass(),
            'key' => 'hav_proxy_status',
            'value' => 'deleted',
        ]);
    }

    private function extension(array $overrides = []): HAVProxyIPv4DC
    {
        return new HAVProxyIPv4DC(array_merge([
            'base_url_profiles' => [
                [
                    'key' => 'primary',
                    'label' => 'Primary',
                    'base_url' => 'https://edge.example.com',
                    'api_token' => 'secret-token',
                    'enabled' => true,
                ],
            ],
            'default_auth_header' => 'Authorization',
            'user_agent' => 'HAV-Proxy-IPv4-DC-Paymenter/1.0',
            'timeout_seconds' => 5,
            'poll_interval_seconds' => 0,
            'poll_timeout_seconds' => 5,
            'location_map' => [],
        ], $overrides));
    }

    private function createProvider(): Server
    {
        return Server::create([
            'name' => 'HAV Proxy IPv4 DC',
            'extension' => 'HAVProxyIPv4DC',
            'type' => 'server',
            'enabled' => true,
        ]);
    }

    private function createMappedProductOffering(): array
    {
        $provider = $this->createProvider();
        $product = $this->createProduct([
            'server_id' => $provider->id,
        ])->product;
        $offering = $this->createProviderOffering($provider, 'united-states');
        $productOffering = ProductLocationOffering::create([
            'product_id' => $product->id,
            'provider_location_offering_id' => $offering->id,
            'enabled' => true,
        ]);

        return [$provider, $product, $productOffering, $offering];
    }

    private function createProviderOffering(Server $provider, string $locationCode, string $stockState = ProviderLocationOffering::STOCK_AVAILABLE): ProviderLocationOffering
    {
        $location = LocationOption::where('code', $locationCode)->firstOrFail();
        $offering = ProviderLocationOffering::create([
            'provider_id' => $provider->id,
            'location_option_id' => $location->id,
            'service_type' => ProviderLocationOffering::SERVICE_PROXY,
            'enabled' => true,
            'stock_state' => $stockState,
        ]);

        ProviderLocationTarget::create([
            'provider_location_offering_id' => $offering->id,
            'external_location_id' => 'group_us',
            'external_location_code' => 'billing-us',
            'external_name' => 'US Pool',
            'raw_payload' => [
                'target_type' => 'group',
                'base_url_key' => 'primary',
                'group_id' => 'group_us',
            ],
            'status' => ProviderLocationTarget::STATUS_ACTIVE,
        ]);

        return $offering;
    }

    private function createService($product): Service
    {
        return Service::factory()->create([
            'product_id' => $product->id,
            'plan_id' => $product->plans()->first()->id,
            'user_id' => User::factory(),
            'status' => Service::STATUS_PENDING,
        ]);
    }
}
