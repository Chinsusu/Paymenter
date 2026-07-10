<?php

namespace Tests\Feature;

use App\Jobs\Server\CreateJob;
use App\Jobs\Server\PollServerOperationJob;
use App\Jobs\Server\TerminateJob;
use App\Livewire\Services\Index as ServiceIndex;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\LocationOption;
use App\Models\ProductLocationOffering;
use App\Models\ProviderLocationOffering;
use App\Models\ProviderLocationTarget;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\HAVProxyIPv4DC\HAVProxyIPv4DC;
use Tests\TestCase;

class HAVProxyIPv4DCTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://provider.example.test';

    public function test_create_server_queues_operation_then_snapshots_proxy_when_polled(): void
    {
        [$extension, $service, $offering, $productOffering] = $this->createProviderContext();

        Http::fake([
            self::BASE_URL . '/api/v3/proxies' => function (Request $request) {
                $this->assertSame('POST', $request->method());
                $this->assertSame('test-key', $request->header('X-API-Key')[0] ?? null);
                $this->assertNotEmpty($request->header('Idempotency-Key')[0] ?? null);
                $this->assertSame('ipv4_dc', $request['kind']);
                $this->assertSame('2b4cc72a-a897-484d-82f8-2e1a65c9be4d', $request['group_id']);
                $this->assertSame('socks5', $request['protocol']);

                return Http::response([
                    'success' => true,
                    'data' => [
                        'resource' => [
                            'id' => 'proxy-1',
                            'group_id' => '2b4cc72a-a897-484d-82f8-2e1a65c9be4d',
                        ],
                        'operation' => [
                            'id' => 'operation-1',
                            'resource_id' => 'proxy-1',
                            'state' => 'accepted',
                        ],
                    ],
                ], 202);
            },
            self::BASE_URL . '/api/v3/operations/operation-1' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 'operation-1',
                    'state' => 'succeeded',
                    'resource_id' => 'proxy-1',
                    'resource_snapshot' => [
                        'id' => 'proxy-1',
                        'status' => 'running',
                        'host' => '203.0.113.10',
                        'outbound_ip' => '203.0.113.10',
                        'port_socks' => 38187,
                        'port_http' => 0,
                        'username' => 'user',
                        'password' => 'pass',
                        'connection_uri' => 'socks5://user:pass@203.0.113.10:38187',
                    ],
                ],
            ]),
        ]);

        $pending = $extension->createServer($service, [
            'protocol' => 'socks5',
            'bandwidth_limit_mb' => 0,
            'speed_limit_mbps' => 10,
        ], [
            'product_location_offering_id' => $productOffering->id,
        ]);

        $this->assertTrue($pending['provider_operation_pending']);
        $this->assertSame('operation-1', $pending['provider_operation_id']);

        $result = $extension->pollOperation(
            $service,
            [],
            $service->properties()->pluck('value', 'key')->all(),
            'create',
            'operation-1',
        );

        $this->assertSame('socks5://user:pass@203.0.113.10:38187', $result['connection_uri']);
        $this->assertSame(ProviderLocationOffering::STOCK_AVAILABLE, $offering->fresh()->stock_state);
        $this->assertDatabaseHas('properties', [
            'model_id' => $service->id,
            'model_type' => $service->getMorphClass(),
            'key' => 'hav_proxy_ipv4_dc_proxy_id',
            'value' => 'proxy-1',
        ]);
        $this->assertDatabaseHas('properties', [
            'model_id' => $service->id,
            'model_type' => $service->getMorphClass(),
            'key' => 'external_location_code',
            'value' => '2b4cc72a-a897-484d-82f8-2e1a65c9be4d',
        ]);
        $connection = $service->properties()->where('key', 'hav_proxy_ipv4_dc_connection_uri')->firstOrFail();
        $this->assertSame('socks5://user:pass@203.0.113.10:38187', $connection->value);
        $this->assertStringStartsWith('encrypted:', (string) DB::table('properties')->where('id', $connection->id)->value('value'));
    }

    public function test_create_server_omits_optional_limits_and_defaults_to_http_and_socks_ports(): void
    {
        [$extension, $service, $offering, $productOffering] = $this->createProviderContext();

        Http::fake([
            self::BASE_URL . '/api/v3/proxies' => function (Request $request) {
                $this->assertSame('default', $request['protocol']);
                $this->assertArrayNotHasKey('speed_limit_mbps', $request->data());
                $this->assertArrayNotHasKey('bandwidth_limit_mb', $request->data());

                return Http::response([
                    'success' => true,
                    'data' => [
                        'resource' => [
                            'id' => 'proxy-1',
                        ],
                        'operation' => [
                            'id' => 'operation-1',
                            'resource_id' => 'proxy-1',
                            'state' => 'accepted',
                        ],
                    ],
                ], 202);
            },
            self::BASE_URL . '/api/v3/operations/operation-1' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 'operation-1',
                    'state' => 'succeeded',
                    'resource_id' => 'proxy-1',
                    'resource_snapshot' => [
                        'id' => 'proxy-1',
                        'status' => 'running',
                        'host' => '203.0.113.10',
                        'outbound_ip' => '203.0.113.10',
                        'port_socks' => 38187,
                        'port_http' => 38188,
                        'username' => 'user',
                        'password' => 'pass',
                        'connection_uri' => 'socks5://user:pass@203.0.113.10:38187',
                    ],
                ],
            ]),
        ]);

        $pending = $extension->createServer($service, [], [
            'product_location_offering_id' => $productOffering->id,
        ]);
        $result = $extension->pollOperation(
            $service,
            [],
            $service->properties()->pluck('value', 'key')->all(),
            'create',
            $pending['provider_operation_id'],
        );

        $this->assertSame(38187, $result['port_socks']);
        $this->assertSame(38188, $result['port_http']);
        $this->assertSame(ProviderLocationOffering::STOCK_AVAILABLE, $offering->fresh()->stock_state);
    }

    public function test_create_server_maps_post_capacity_exhausted_to_out_of_stock(): void
    {
        [$extension, $service, $offering, $productOffering] = $this->createProviderContext();

        Http::fake([
            self::BASE_URL . '/api/v3/proxies' => Http::response([
                'success' => false,
                'error' => [
                    'code' => 'CAPACITY_EXHAUSTED',
                    'message' => 'group has no allocatable units',
                    'retryable' => false,
                ],
            ], 409),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Selected location is out of stock.');

        try {
            $pending = $extension->createServer($service, [
                'protocol' => 'socks5',
            ], [
                'product_location_offering_id' => $productOffering->id,
            ]);
            $extension->pollOperation(
                $service,
                [],
                $service->properties()->pluck('value', 'key')->all(),
                'create',
                $pending['provider_operation_id'],
            );
        } finally {
            $this->assertSame(ProviderLocationOffering::STOCK_UNAVAILABLE, $offering->fresh()->stock_state);
            $this->assertDatabaseMissing('properties', [
                'model_id' => $service->id,
                'model_type' => $service->getMorphClass(),
                'key' => 'hav_proxy_ipv4_dc_proxy_id',
            ]);
        }
    }

    public function test_create_server_maps_async_capacity_failure_to_out_of_stock(): void
    {
        [$extension, $service, $offering, $productOffering] = $this->createProviderContext();

        Http::fake([
            self::BASE_URL . '/api/v3/proxies' => Http::response([
                'success' => true,
                'data' => [
                    'resource' => [
                        'id' => 'proxy-1',
                    ],
                    'operation' => [
                        'id' => 'operation-1',
                        'resource_id' => 'proxy-1',
                        'state' => 'accepted',
                    ],
                ],
            ], 202),
            self::BASE_URL . '/api/v3/operations/operation-1' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 'operation-1',
                    'state' => 'failed',
                    'resource_id' => 'proxy-1',
                    'error_code' => 'CAPACITY_EXHAUSTED',
                    'error_message' => 'node has reached maximum proxy capacity',
                    'retryable' => false,
                ],
            ]),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('CAPACITY_EXHAUSTED retryable=false');

        try {
            $pending = $extension->createServer($service, [
                'protocol' => 'socks5',
            ], [
                'product_location_offering_id' => $productOffering->id,
            ]);
            $extension->pollOperation(
                $service,
                [],
                $service->properties()->pluck('value', 'key')->all(),
                'create',
                $pending['provider_operation_id'],
            );
        } finally {
            $this->assertSame(ProviderLocationOffering::STOCK_UNAVAILABLE, $offering->fresh()->stock_state);
        }
    }

    public function test_terminate_server_polls_delete_operation_and_removes_proxy_properties(): void
    {
        [$extension, $service] = $this->createProviderContext();

        $service->properties()->create([
            'key' => 'hav_proxy_ipv4_dc_proxy_id',
            'name' => 'Proxy ID',
            'value' => 'proxy-1',
        ]);
        $service->properties()->create([
            'key' => 'hav_proxy_ipv4_dc_connection_uri',
            'name' => 'Proxy connection URI',
            'value' => 'socks5://user:pass@203.0.113.10:38187',
        ]);
        $service->properties()->create([
            'key' => 'hav_proxy_ipv4_dc_create_operation_id',
            'name' => 'Create operation ID',
            'value' => 'create-operation-1',
        ]);

        Http::fake([
            self::BASE_URL . '/api/v3/proxies/proxy-1' => Http::response([
                'success' => true,
                'data' => [
                    'operation' => [
                        'id' => 'delete-operation-1',
                        'state' => 'accepted',
                    ],
                ],
            ], 202),
            self::BASE_URL . '/api/v3/operations/delete-operation-1' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 'delete-operation-1',
                    'state' => 'succeeded',
                ],
            ]),
        ]);

        $pending = $extension->terminateServer($service, [], [
            'hav_proxy_ipv4_dc_proxy_id' => 'proxy-1',
        ]);
        $this->assertTrue($pending['provider_operation_pending']);

        $extension->pollOperation(
            $service,
            [],
            $service->properties()->pluck('value', 'key')->all(),
            'delete',
            $pending['provider_operation_id'],
        );

        $this->assertDatabaseMissing('properties', [
            'model_id' => $service->id,
            'model_type' => $service->getMorphClass(),
            'key' => 'hav_proxy_ipv4_dc_proxy_id',
        ]);
        $this->assertDatabaseMissing('properties', [
            'model_id' => $service->id,
            'model_type' => $service->getMorphClass(),
            'key' => 'hav_proxy_ipv4_dc_connection_uri',
        ]);
        $this->assertSame(0, $service->properties()->where('key', 'like', 'hav_proxy_ipv4_dc_%')->count());
    }

    public function test_create_reuses_stored_idempotency_key_after_service_update(): void
    {
        [$extension, $service, , $productOffering] = $this->createProviderContext();
        $keys = [];

        Http::fake([
            self::BASE_URL . '/api/v3/proxies' => function (Request $request) use (&$keys) {
                $keys[] = $request->header('Idempotency-Key')[0] ?? null;

                if (count($keys) === 1) {
                    return Http::response([
                        'success' => false,
                        'error' => [
                            'code' => 'PROVIDER_ERROR',
                            'message' => 'temporary outage',
                            'retryable' => true,
                        ],
                    ], 503);
                }

                return Http::response([
                    'success' => true,
                    'data' => [
                        'resource' => ['id' => 'proxy-1'],
                        'operation' => ['id' => 'operation-1', 'resource_id' => 'proxy-1', 'state' => 'accepted'],
                    ],
                ], 202);
            },
        ]);

        try {
            $extension->createServer($service, [], [
                'product_location_offering_id' => $productOffering->id,
            ]);
            $this->fail('Expected retryable provider failure.');
        } catch (Exception) {
            // The stored key must survive this retryable request failure.
        }

        $service->touch();
        $pending = $extension->createServer($service->fresh(), [], [
            'product_location_offering_id' => $productOffering->id,
        ]);

        $this->assertTrue($pending['provider_operation_pending']);
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
    }

    public function test_delete_requires_an_operation_id_before_local_proxy_state_is_removed(): void
    {
        [$extension, $service] = $this->createProviderContext();
        $service->properties()->create([
            'key' => 'hav_proxy_ipv4_dc_proxy_id',
            'name' => 'Proxy ID',
            'value' => 'proxy-1',
        ]);

        Http::fake([
            self::BASE_URL . '/api/v3/proxies/proxy-1' => Http::response([
                'success' => true,
                'data' => [],
            ], 202),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('delete operation id');

        try {
            $extension->terminateServer($service, [], [
                'hav_proxy_ipv4_dc_proxy_id' => 'proxy-1',
            ]);
        } finally {
            $this->assertDatabaseHas('properties', [
                'model_id' => $service->id,
                'model_type' => $service->getMorphClass(),
                'key' => 'hav_proxy_ipv4_dc_proxy_id',
                'value' => 'proxy-1',
            ]);
        }
    }

    public function test_sync_location_offerings_maps_provider_groups_by_billing_group_id(): void
    {
        [$extension, , , , $provider] = $this->createProviderContext();

        Http::fake([
            self::BASE_URL . '/api/v3/inventory/groups?kind=ipv4_dc' => Http::response([
                'success' => true,
                'data' => [[
                    'id' => '2b4cc72a-a897-484d-82f8-2e1a65c9be4d',
                    'billing_group_id' => '46',
                    'name' => 'FPT-DC',
                    'sell_state' => 'sellable',
                    'allocatable_units' => 10,
                ]],
            ]),
        ]);

        $this->assertSame(1, $extension->syncLocationOfferings($provider));
        $this->assertDatabaseHas('provider_location_targets', [
            'external_location_id' => '46',
            'external_location_code' => '2b4cc72a-a897-484d-82f8-2e1a65c9be4d',
            'external_name' => 'FPT-DC',
        ]);
    }

    public function test_create_job_keeps_service_pending_until_the_provider_operation_succeeds(): void
    {
        [, $service, , $productOffering] = $this->createProviderContext();
        Queue::fake();

        Http::fake([
            self::BASE_URL . '/api/v3/proxies' => Http::response([
                'success' => true,
                'data' => [
                    'resource' => ['id' => 'proxy-1'],
                    'operation' => ['id' => 'operation-1', 'resource_id' => 'proxy-1', 'state' => 'accepted'],
                ],
            ], 202),
        ]);

        $service->properties()->create([
            'key' => 'product_location_offering_id',
            'name' => 'Product location offering ID',
            'value' => (string) $productOffering->id,
        ]);

        (new CreateJob($service, false))->handle();

        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
        Queue::assertPushed(PollServerOperationJob::class, function (PollServerOperationJob $job) use ($service) {
            return $job->serviceId === $service->id && $job->action === 'create' && $job->operationId === 'operation-1';
        });

        Queue::fake();
        Http::fake([
            self::BASE_URL . '/api/v3/operations/operation-1' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 'operation-1',
                    'state' => 'succeeded',
                    'resource_id' => 'proxy-1',
                    'resource_snapshot' => $this->proxySnapshot(),
                ],
            ]),
        ]);

        (new PollServerOperationJob($service->id, 'create', 'operation-1', time() + 60, false))->handle();

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        $connection = $service->properties()->where('key', 'hav_proxy_ipv4_dc_connection_uri')->firstOrFail();
        $this->assertSame('socks5://user:pass@203.0.113.10:38187', $connection->value);
        $this->assertStringStartsWith('encrypted:', (string) DB::table('properties')->where('id', $connection->id)->value('value'));
    }

    public function test_terminate_job_keeps_service_active_until_delete_operation_succeeds(): void
    {
        [, $service] = $this->createProviderContext();
        $service->update(['status' => Service::STATUS_ACTIVE]);
        $service->properties()->create([
            'key' => 'hav_proxy_ipv4_dc_proxy_id',
            'name' => 'Proxy ID',
            'value' => 'proxy-1',
        ]);
        Queue::fake();

        Http::fake([
            self::BASE_URL . '/api/v3/proxies/proxy-1' => Http::response([
                'success' => true,
                'data' => ['operation' => ['id' => 'delete-operation-1', 'state' => 'accepted']],
            ], 202),
        ]);

        (new TerminateJob($service, false))->handle();

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        Queue::assertPushed(PollServerOperationJob::class, function (PollServerOperationJob $job) use ($service) {
            return $job->serviceId === $service->id && $job->action === 'delete';
        });

        Queue::fake();
        Http::fake([
            self::BASE_URL . '/api/v3/operations/delete-operation-1' => Http::response([
                'success' => true,
                'data' => ['id' => 'delete-operation-1', 'state' => 'succeeded'],
            ]),
        ]);

        (new PollServerOperationJob($service->id, 'delete', 'delete-operation-1', time() + 60, false))->handle();

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
    }

    public function test_customer_service_panel_renders_dual_protocol_proxy_credentials(): void
    {
        View::addLocation(base_path('themes/default/views'));

        [, $service] = $this->createProviderContext();
        $service->update(['status' => Service::STATUS_ACTIVE]);
        $service->properties()->createMany([
            [
                'key' => 'hav_proxy_ipv4_dc_proxy_id',
                'name' => 'Proxy ID',
                'value' => 'proxy-1',
            ],
            [
                'key' => 'hav_proxy_ipv4_dc_status',
                'name' => 'Proxy status',
                'value' => 'running',
            ],
            [
                'key' => 'hav_proxy_ipv4_dc_host',
                'name' => 'Proxy host',
                'value' => '203.0.113.10',
            ],
            [
                'key' => 'hav_proxy_ipv4_dc_outbound_ip',
                'name' => 'Proxy outbound IP',
                'value' => '203.0.113.10',
            ],
            [
                'key' => 'hav_proxy_ipv4_dc_port_http',
                'name' => 'HTTP port',
                'value' => '8080',
            ],
            [
                'key' => 'hav_proxy_ipv4_dc_port_socks',
                'name' => 'SOCKS5 port',
                'value' => '1080',
            ],
            [
                'key' => 'hav_proxy_ipv4_dc_username',
                'name' => 'Proxy username',
                'value' => 'user',
            ],
            [
                'key' => 'hav_proxy_ipv4_dc_password',
                'name' => 'Proxy password',
                'value' => 'pass',
            ],
            [
                'key' => 'display_name',
                'name' => 'Location',
                'value' => 'Viet Nam - FPT',
            ],
        ]);

        $this->actingAs($service->user);

        Livewire::test(ServiceShow::class, ['service' => $service->fresh()])
            ->assertSee('Proxy IPv4 DC')
            ->assertSee('Viet Nam - FPT')
            ->assertSee('Proxy IP')
            ->assertSee('Connection')
            ->assertSee('Authentication')
            ->assertSee('HTTP endpoint')
            ->assertSee('SOCKS5 endpoint')
            ->assertSee('203.0.113.10')
            ->assertSee('8080')
            ->assertSee('1080');
    }

    public function test_services_index_renders_proxy_table_with_details_action(): void
    {
        View::addLocation(base_path('themes/default/views'));

        [, $service] = $this->createProviderContext();
        $service->update(['status' => Service::STATUS_ACTIVE]);
        $service->properties()->createMany([
            [
                'key' => 'hav_proxy_ipv4_dc_proxy_id',
                'name' => 'Proxy ID',
                'value' => 'proxy-1',
            ],
            [
                'key' => 'hav_proxy_ipv4_dc_status',
                'name' => 'Proxy status',
                'value' => 'running',
            ],
            [
                'key' => 'hav_proxy_ipv4_dc_host',
                'name' => 'Proxy host',
                'value' => '203.0.113.10',
            ],
            [
                'key' => 'hav_proxy_ipv4_dc_port_http',
                'name' => 'HTTP port',
                'value' => '8080',
            ],
            [
                'key' => 'hav_proxy_ipv4_dc_port_socks',
                'name' => 'SOCKS5 port',
                'value' => '1080',
            ],
            [
                'key' => 'hav_proxy_ipv4_dc_username',
                'name' => 'Proxy username',
                'value' => 'user',
            ],
            [
                'key' => 'hav_proxy_ipv4_dc_password',
                'name' => 'Proxy password',
                'value' => 'pass',
            ],
            [
                'key' => 'display_name',
                'name' => 'Location',
                'value' => 'Viet Nam - FPT',
            ],
        ]);

        $this->actingAs($service->user);

        Livewire::test(ServiceIndex::class)
            ->assertSee('Proxy IP')
            ->assertSee('Proxy Port')
            ->assertSee('User/Pass')
            ->assertSee('Viet Nam - FPT')
            ->assertSee('203.0.113.10')
            ->assertSee('Details')
            ->assertSee(route('services.show', $service), false);
    }

    private function createProviderContext(): array
    {
        $provider = Server::create([
            'name' => 'HAV Proxy IPv4 DC',
            'extension' => 'HAVProxyIPv4DC',
            'type' => 'server',
            'enabled' => true,
        ]);
        $provider->settings()->createMany([
            ['key' => 'base_url', 'type' => 'string', 'value' => self::BASE_URL],
            ['key' => 'api_key', 'type' => 'string', 'value' => 'test-key'],
            ['key' => 'auth_header', 'type' => 'string', 'value' => 'X-API-Key'],
            ['key' => 'user_agent', 'type' => 'string', 'value' => 'HAV-Proxy-IPv4-DC-Paymenter/1.0-test'],
            ['key' => 'http_timeout', 'type' => 'string', 'value' => '15'],
            ['key' => 'poll_interval', 'type' => 'string', 'value' => '1'],
            ['key' => 'poll_timeout', 'type' => 'string', 'value' => '90'],
        ]);

        $location = LocationOption::where('legacy_id', 46)->firstOrFail();
        $offering = ProviderLocationOffering::create([
            'provider_id' => $provider->id,
            'location_option_id' => $location->id,
            'service_type' => ProviderLocationOffering::SERVICE_PROXY,
            'enabled' => true,
            'stock_state' => ProviderLocationOffering::STOCK_AVAILABLE,
        ]);

        ProviderLocationTarget::create([
            'provider_location_offering_id' => $offering->id,
            'external_location_id' => '46',
            'external_location_code' => '2b4cc72a-a897-484d-82f8-2e1a65c9be4d',
            'external_name' => 'FPT-DC',
            'priority' => 10,
            'weight' => 100,
        ]);

        $productData = $this->createProduct([
            'server_id' => $provider->id,
        ]);
        $productOffering = ProductLocationOffering::create([
            'product_id' => $productData->product->id,
            'provider_location_offering_id' => $offering->id,
            'enabled' => true,
        ]);

        $service = Service::factory()->create([
            'user_id' => User::factory(),
            'product_id' => $productData->product->id,
            'plan_id' => $productData->plan->id,
            'status' => Service::STATUS_PENDING,
            'price' => 10.00,
            'currency_code' => 'USD',
        ]);

        return [
            new HAVProxyIPv4DC([
                'base_url' => self::BASE_URL,
                'api_key' => 'test-key',
                'auth_header' => 'X-API-Key',
                'user_agent' => 'HAV-Proxy-IPv4-DC-Paymenter/1.0-test',
                'http_timeout' => 15,
                'poll_interval' => 1,
                'poll_timeout' => 5,
            ]),
            $service,
            $offering,
            $productOffering,
            $provider,
        ];
    }

    private function proxySnapshot(): array
    {
        return [
            'id' => 'proxy-1',
            'status' => 'running',
            'host' => '203.0.113.10',
            'outbound_ip' => '203.0.113.10',
            'port_socks' => 38187,
            'port_http' => 38188,
            'username' => 'user',
            'password' => 'pass',
            'connection_uri' => 'socks5://user:pass@203.0.113.10:38187',
        ];
    }
}
