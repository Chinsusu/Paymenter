<?php

namespace Tests\Feature;

use App\Jobs\Server\CreateJob;
use App\Jobs\Server\PollServerOperationJob;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Livewire\Services\Index as ServiceIndex;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\LocationOption;
use App\Models\ProductLocationOffering;
use App\Models\ProviderLocationOffering;
use App\Models\ProviderLocationTarget;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\ProviderOperationLifecycleService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\HAVProxyIPv4DC\HAVProxyIPv4DC;
use Paymenter\Extensions\Servers\HAVProxyIPv4DC\V3ProviderException;
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
            self::BASE_URL . '/api/v3/operations/operation-1' => function () {
                return Http::response([
                    'success' => true,
                    'data' => [
                        'id' => 'operation-1',
                        'action' => 'create',
                        'proxy_kind' => 'ipv4_dc',
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
                ]);
            },
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
        $offering->update(['stock_state' => ProviderLocationOffering::STOCK_LIMITED]);

        $result = $extension->pollOperation(
            $service,
            [],
            $service->properties()->pluck('value', 'key')->all(),
            'create',
            'operation-1',
        );

        $this->assertSame([], $result);
        $this->assertSame('create', $service->properties()->where('key', 'hav_proxy_ipv4_dc_pending_action')->value('value'));
        $extension->finalizeOperation($service, 'create', 'operation-1');
        $this->assertNull($service->properties()->where('key', 'hav_proxy_ipv4_dc_pending_action')->value('value'));
        $this->assertSame(ProviderLocationOffering::STOCK_LIMITED, $offering->fresh()->stock_state);
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
        $this->assertDatabaseHas('properties', [
            'model_id' => $service->id,
            'model_type' => $service->getMorphClass(),
            'key' => 'provider_server_id',
        ]);
        $this->assertDatabaseHas('properties', [
            'model_id' => $service->id,
            'model_type' => $service->getMorphClass(),
            'key' => 'provider_extension',
            'value' => 'HAVProxyIPv4DC',
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
            self::BASE_URL . '/api/v3/operations/operation-1' => function () use ($service) {
                return Http::response([
                    'success' => true,
                    'data' => [
                        'id' => 'operation-1',
                        'action' => 'create',
                        'proxy_kind' => 'ipv4_dc',
                        'external_ref' => $service->properties()->where('key', 'hav_proxy_ipv4_dc_external_ref')->value('value'),
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
                        ],
                    ],
                ]);
            },
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
        $extension->finalizeOperation($service, 'create', $pending['provider_operation_id']);

        $this->assertSame([], $result);
        $this->assertSame('38187', $service->properties()->where('key', 'hav_proxy_ipv4_dc_port_socks')->value('value'));
        $this->assertSame('38188', $service->properties()->where('key', 'hav_proxy_ipv4_dc_port_http')->value('value'));
        $this->assertSame(
            'socks5://user:pass@203.0.113.10:38187',
            $service->properties()->where('key', 'hav_proxy_ipv4_dc_connection_uri')->firstOrFail()->value,
        );
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
            self::BASE_URL . '/api/v3/operations/operation-1' => function () use ($service) {
                return Http::response([
                    'success' => true,
                    'data' => [
                        'id' => 'operation-1',
                        'action' => 'create',
                        'proxy_kind' => 'ipv4_dc',
                        'external_ref' => $service->properties()->where('key', 'hav_proxy_ipv4_dc_external_ref')->value('value'),
                        'state' => 'failed',
                        'resource_id' => 'proxy-1',
                        'error_code' => 'CAPACITY_EXHAUSTED',
                        'error_message' => 'node has reached maximum proxy capacity',
                        'retryable' => false,
                    ],
                ]);
            },
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
                    'action' => 'delete',
                    'proxy_kind' => 'ipv4_dc',
                    'resource_id' => 'proxy-1',
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

        $this->assertDatabaseHas('properties', [
            'model_id' => $service->id,
            'model_type' => $service->getMorphClass(),
            'key' => 'hav_proxy_ipv4_dc_proxy_id',
            'value' => 'proxy-1',
        ]);
        $extension->finalizeOperation($service, 'delete', $pending['provider_operation_id']);

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
            $this->assertSame('delete', $service->properties()->where('key', 'hav_proxy_ipv4_dc_pending_action')->value('value'));
            $this->assertNotNull($service->properties()->where('key', 'hav_proxy_ipv4_dc_delete_idempotency_key')->value('value'));
        }
    }

    public function test_ambiguous_create_response_replays_the_same_idempotency_key_and_external_reference(): void
    {
        [$extension, $service, , $productOffering] = $this->createProviderContext();
        $keys = [];
        $references = [];

        Http::fake([
            self::BASE_URL . '/api/v3/proxies' => function (Request $request) use (&$keys, &$references) {
                $keys[] = $request->header('Idempotency-Key')[0] ?? null;
                $references[] = $request['external_ref'];

                if (count($keys) === 1) {
                    return Http::response(['success' => true, 'data' => []], 202);
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
            $extension->createServer($service, [], ['product_location_offering_id' => $productOffering->id]);
            $this->fail('Expected an ambiguous provider response.');
        } catch (V3ProviderException $exception) {
            $this->assertSame('AMBIGUOUS_PROVIDER_RESPONSE', $exception->providerCode);
            $this->assertTrue($exception->retryable);
        }

        $pending = $extension->createServer($service->fresh(), [], ['product_location_offering_id' => $productOffering->id]);

        $this->assertSame('operation-1', $pending['provider_operation_id']);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame($references[0], $references[1]);
    }

    public function test_same_pending_action_repairs_missing_reconciliation_deadlines(): void
    {
        [$extension, $service, , $productOffering] = $this->createProviderContext();
        $service->properties()->create([
            'key' => 'hav_proxy_ipv4_dc_pending_action',
            'name' => 'Pending provider action',
            'value' => 'create',
        ]);

        Http::fake([
            self::BASE_URL . '/api/v3/proxies' => Http::response([
                'success' => true,
                'data' => [
                    'resource' => ['id' => 'proxy-1'],
                    'operation' => ['id' => 'operation-1', 'resource_id' => 'proxy-1', 'state' => 'accepted'],
                ],
            ], 202),
        ]);

        $extension->createServer($service, [], ['product_location_offering_id' => $productOffering->id]);

        $this->assertGreaterThan(time(), (int) $service->properties()->where('key', 'hav_proxy_ipv4_dc_operation_deadline_at')->value('value'));
        $this->assertGreaterThan(time(), (int) $service->properties()->where('key', 'hav_proxy_ipv4_dc_reconcile_until_at')->value('value'));
    }

    public function test_stale_same_action_completion_cannot_clear_a_new_operation_generation(): void
    {
        [$extension, $service, , $productOffering] = $this->createProviderContext();

        Http::fake([
            self::BASE_URL . '/api/v3/proxies' => Http::sequence()
                ->push([
                    'success' => true,
                    'data' => [
                        'resource' => ['id' => 'proxy-1'],
                        'operation' => ['id' => 'operation-1', 'resource_id' => 'proxy-1', 'state' => 'accepted'],
                    ],
                ], 202)
                ->push([
                    'success' => true,
                    'data' => [
                        'resource' => ['id' => 'proxy-2'],
                        'operation' => ['id' => 'operation-2', 'resource_id' => 'proxy-2', 'state' => 'accepted'],
                    ],
                ], 202),
        ]);

        $first = $extension->createServer($service, [], ['product_location_offering_id' => $productOffering->id]);
        $extension->finalizeOperation($service, 'create', $first['provider_operation_id']);
        $service->properties()->where('key', 'hav_proxy_ipv4_dc_proxy_id')->delete();
        $second = $extension->createServer($service, [], ['product_location_offering_id' => $productOffering->id]);

        try {
            $extension->finalizeOperation($service, 'create', $first['provider_operation_id']);
            $this->fail('Expected stale operation finalization to be rejected.');
        } catch (V3ProviderException $exception) {
            $this->assertSame('STALE_OPERATION', $exception->providerCode);
        }

        $this->assertSame('operation-2', $second['provider_operation_id']);
        $this->assertSame('operation-2', $service->properties()->where('key', 'hav_proxy_ipv4_dc_pending_operation_id')->value('value'));
    }

    public function test_stale_completion_rolls_back_the_local_lifecycle_transition(): void
    {
        [$extension, $service] = $this->createProviderContext();
        $service->update(['status' => Service::STATUS_SUSPENDED]);
        $service->properties()->create([
            'key' => 'hav_proxy_ipv4_dc_proxy_id',
            'name' => 'Proxy ID',
            'value' => 'proxy-1',
        ]);

        Http::fake([
            self::BASE_URL . '/api/v3/proxies/proxy-1/actions/start' => Http::response([
                'success' => true,
                'data' => [
                    'operation' => ['id' => 'operation-2', 'resource_id' => 'proxy-1', 'state' => 'accepted'],
                ],
            ], 202),
        ]);

        $pending = $extension->unsuspendServer(
            $service,
            [],
            $service->properties()->pluck('value', 'key')->all(),
        );

        try {
            ProviderOperationLifecycleService::complete($service, 'start', false, [], 'operation-1');
            $this->fail('Expected stale lifecycle completion to fail.');
        } catch (V3ProviderException $exception) {
            $this->assertSame('STALE_OPERATION', $exception->providerCode);
        }

        $this->assertSame('operation-2', $pending['provider_operation_id']);
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);
        $this->assertSame('operation-2', $service->properties()->where('key', 'hav_proxy_ipv4_dc_pending_operation_id')->value('value'));
    }

    public function test_immediate_cancellation_recovers_ambiguous_create_then_deletes_proxy(): void
    {
        [$extension, $service, , $productOffering] = $this->createProviderContext();

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response(['success' => true, 'data' => []], 202);
            }

            if ($request->method() === 'DELETE') {
                return Http::response([
                    'success' => false,
                    'error' => ['code' => 'PROXY_NOT_FOUND', 'message' => 'already deleted', 'retryable' => false],
                ], 404);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response([
                'success' => true,
                'data' => [[
                    'id' => 'proxy-1',
                    'kind' => 'ipv4_dc',
                    'external_ref' => $query['external_ref'] ?? null,
                    'status' => 'running',
                    'host' => '203.0.113.10',
                    'port_socks' => 1080,
                    'port_http' => 8080,
                    'username' => 'user',
                    'password' => 'pass',
                ]],
            ]);
        });

        try {
            $extension->createServer($service, [], ['product_location_offering_id' => $productOffering->id]);
            $this->fail('Expected an ambiguous create response.');
        } catch (V3ProviderException $exception) {
            $this->assertSame('AMBIGUOUS_PROVIDER_RESPONSE', $exception->providerCode);
        }

        DB::table('service_cancellations')->insert([
            'service_id' => $service->id,
            'reason' => 'cancel ambiguous create',
            'type' => 'immediate',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new TerminateJob($service, false))->handle();

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(0, $service->properties()->where('key', 'like', 'hav_proxy_ipv4_dc_%')->count());
    }

    public function test_operation_without_exact_ownership_fields_requires_manual_recovery(): void
    {
        [$extension, $service, , $productOffering] = $this->createProviderContext();

        Http::fake([
            self::BASE_URL . '/api/v3/proxies' => Http::response([
                'success' => true,
                'data' => [
                    'resource' => ['id' => 'proxy-1'],
                    'operation' => ['id' => 'operation-1', 'resource_id' => 'proxy-1', 'state' => 'accepted'],
                ],
            ], 202),
            self::BASE_URL . '/api/v3/operations/operation-1' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 'operation-1',
                    'resource_id' => 'proxy-1',
                    'state' => 'succeeded',
                    'resource_snapshot' => $this->proxySnapshot(),
                ],
            ]),
        ]);

        $pending = $extension->createServer($service, [], ['product_location_offering_id' => $productOffering->id]);

        try {
            $extension->pollOperation(
                $service,
                [],
                $service->properties()->pluck('value', 'key')->all(),
                'create',
                $pending['provider_operation_id'],
            );
            $this->fail('Expected provider operation ownership validation to fail.');
        } catch (V3ProviderException $exception) {
            $this->assertSame('OPERATION_MISMATCH', $exception->providerCode);
            $this->assertFalse($exception->retryable);
        }

        $this->assertSame('manual', $service->properties()->where('key', 'hav_proxy_ipv4_dc_recovery_state')->value('value'));
    }

    public function test_sync_location_offerings_maps_provider_groups_by_billing_group_id(): void
    {
        [$extension, , $offering, , $provider] = $this->createProviderContext();
        $offering->update(['enabled' => false]);

        Http::fake([
            self::BASE_URL . '/api/v3/inventory/groups?kind=ipv4_dc' => Http::sequence()
                ->push([
                    'success' => true,
                    'data' => [[
                        'id' => '2b4cc72a-a897-484d-82f8-2e1a65c9be4d',
                        'billing_group_id' => '46',
                        'name' => 'FPT-DC',
                        'sell_state' => 'sellable',
                        'allocatable_units' => 10,
                    ]],
                ])
                ->push([
                    'success' => true,
                    'data' => [[
                        'id' => '2b4cc72a-a897-484d-82f8-2e1a65c9be4d',
                        'billing_group_id' => '46',
                        'name' => 'FPT-DC',
                        'sell_state' => 'maintenance',
                        'allocatable_units' => null,
                    ]],
                ])
                ->push([
                    'success' => true,
                    'data' => [],
                ]),
        ]);

        $this->assertSame(1, $extension->syncLocationOfferings($provider));
        $this->assertDatabaseHas('provider_location_targets', [
            'external_location_id' => '46',
            'external_location_code' => '2b4cc72a-a897-484d-82f8-2e1a65c9be4d',
            'external_name' => 'FPT-DC',
        ]);
        $this->assertFalse($offering->fresh()->enabled);

        $this->assertSame(1, $extension->syncLocationOfferings($provider));
        $this->assertSame(ProviderLocationOffering::STOCK_UNAVAILABLE, $offering->fresh()->stock_state);

        $this->assertSame(0, $extension->syncLocationOfferings($provider));
        $this->assertSame(ProviderLocationOffering::STOCK_UNAVAILABLE, $offering->fresh()->stock_state);
        $this->assertSame(
            ProviderLocationTarget::STATUS_DISABLED,
            $offering->targets()->firstOrFail()->fresh()->status,
        );
    }

    public function test_checkout_stays_fail_closed_when_location_stock_is_not_sellable(): void
    {
        [$extension, $service, $offering] = $this->createProviderContext();

        foreach ([ProviderLocationOffering::STOCK_UNKNOWN, ProviderLocationOffering::STOCK_UNAVAILABLE] as $stockState) {
            $offering->update(['stock_state' => $stockState]);
            $config = $extension->getCheckoutConfig($service->product);

            $this->assertCount(1, $config);
            $this->assertTrue($config[0]['required']);
            $this->assertSame([], $config[0]['options']);
        }
    }

    public function test_stop_start_stop_rotates_idempotency_keys(): void
    {
        [$extension, $service] = $this->createProviderContext();
        $service->properties()->create([
            'key' => 'hav_proxy_ipv4_dc_proxy_id',
            'name' => 'Proxy ID',
            'value' => 'proxy-1',
        ]);
        $keys = [];
        $operations = [];

        Http::fake(function (Request $request) use (&$keys, &$operations) {
            if (str_contains($request->url(), '/actions/')) {
                $action = str_ends_with($request->url(), '/start') ? 'start' : 'stop';
                $operationId = 'operation-' . (count($operations) + 1);
                $keys[] = $request->header('Idempotency-Key')[0] ?? null;
                $operations[$operationId] = $action;

                return Http::response([
                    'success' => true,
                    'data' => ['operation' => ['id' => $operationId, 'state' => 'accepted']],
                ], 202);
            }

            $operationId = basename(parse_url($request->url(), PHP_URL_PATH));
            $action = $operations[$operationId];

            return Http::response([
                'success' => true,
                'data' => [
                    'id' => $operationId,
                    'action' => $action,
                    'proxy_kind' => 'ipv4_dc',
                    'resource_id' => 'proxy-1',
                    'state' => 'succeeded',
                    'desired_status' => $action === 'start' ? 'running' : 'stopped',
                ],
            ]);
        });

        foreach (['stop', 'start', 'stop'] as $action) {
            $service->update([
                'status' => $action === 'start' ? Service::STATUS_SUSPENDED : Service::STATUS_ACTIVE,
            ]);
            $properties = $service->properties()->pluck('value', 'key')->all();
            $pending = $action === 'start'
                ? $extension->unsuspendServer($service, [], $properties)
                : $extension->suspendServer($service, [], $properties);
            $extension->pollOperation(
                $service,
                [],
                $service->properties()->pluck('value', 'key')->all(),
                $action,
                $pending['provider_operation_id'],
            );
            $extension->finalizeOperation($service, $action, $pending['provider_operation_id']);
        }

        $this->assertCount(3, array_unique($keys));
        $this->assertSame(0, $service->properties()->where('key', 'like', '%_idempotency_key')->count());
    }

    public function test_conflicting_action_is_blocked_while_create_is_pending(): void
    {
        [$extension, $service, , $productOffering] = $this->createProviderContext();

        Http::fake([
            self::BASE_URL . '/api/v3/proxies' => Http::response([
                'success' => true,
                'data' => [
                    'resource' => ['id' => 'proxy-1'],
                    'operation' => ['id' => 'operation-1', 'resource_id' => 'proxy-1', 'state' => 'accepted'],
                ],
            ], 202),
        ]);

        $extension->createServer($service, [], [
            'product_location_offering_id' => $productOffering->id,
        ]);

        try {
            $extension->terminateServer($service, [], $service->properties()->pluck('value', 'key')->all());
            $this->fail('Expected the conflicting delete operation to be blocked.');
        } catch (V3ProviderException $exception) {
            $this->assertSame('OPERATION_IN_PROGRESS', $exception->providerCode);
            $this->assertTrue($exception->retryable);
        }
    }

    public function test_missing_create_operation_is_reconciled_by_external_reference(): void
    {
        [$extension, $service, , $productOffering] = $this->createProviderContext();

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response([
                    'success' => true,
                    'data' => [
                        'resource' => ['id' => 'proxy-1'],
                        'operation' => ['id' => 'operation-1', 'resource_id' => 'proxy-1', 'state' => 'accepted'],
                    ],
                ], 202);
            }

            if (str_contains($request->url(), '/operations/')) {
                return Http::response([
                    'success' => false,
                    'error' => ['code' => 'NOT_FOUND', 'message' => 'operation expired', 'retryable' => false],
                ], 404);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response([
                'success' => true,
                'data' => [[
                    'id' => 'proxy-1',
                    'kind' => 'ipv4_dc',
                    'status' => 'running',
                    'external_ref' => $query['external_ref'] ?? null,
                    'host' => '203.0.113.10',
                    'port_socks' => 1080,
                    'port_http' => 8080,
                    'username' => 'user',
                    'password' => 'pass',
                ]],
            ]);
        });

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

        $this->assertSame([], $result);
        $this->assertSame('proxy-1', $service->properties()->where('key', 'hav_proxy_ipv4_dc_proxy_id')->value('value'));
        $this->assertSame('create', $service->properties()->where('key', 'hav_proxy_ipv4_dc_pending_action')->value('value'));
        $extension->finalizeOperation($service, 'create', $pending['provider_operation_id']);
        $this->assertDatabaseMissing('properties', [
            'model_id' => $service->id,
            'model_type' => $service->getMorphClass(),
            'key' => 'hav_proxy_ipv4_dc_pending_action',
        ]);
    }

    public function test_reconciliation_requeues_a_lost_poll_job(): void
    {
        [, $service] = $this->createProviderContext();
        $service->properties()->createMany([
            ['key' => 'hav_proxy_ipv4_dc_pending_action', 'name' => 'Pending action', 'value' => 'create'],
            ['key' => 'hav_proxy_ipv4_dc_pending_operation_id', 'name' => 'Pending operation', 'value' => 'operation-1'],
        ]);
        Queue::fake();

        $this->assertSame(0, Artisan::call('provider:reconcile-operations', ['--service' => $service->id]));
        Queue::assertPushed(PollServerOperationJob::class, fn (PollServerOperationJob $job) => $job->serviceId === $service->id);
        $this->assertGreaterThan(time(), (int) $service->properties()->where('key', 'hav_proxy_ipv4_dc_reconcile_until_at')->value('value'));
    }

    public function test_reconciliation_adopts_a_lost_legacy_create_operation(): void
    {
        [, $service, , $productOffering] = $this->createProviderContext();
        $service->properties()->createMany([
            [
                'key' => 'product_location_offering_id',
                'name' => 'Product location offering ID',
                'value' => (string) $productOffering->id,
            ],
            [
                'key' => 'hav_proxy_ipv4_dc_create_operation_id',
                'name' => 'Create operation ID',
                'value' => 'legacy-operation-1',
            ],
        ]);
        Queue::fake();

        $this->assertSame(0, Artisan::call('provider:reconcile-operations', ['--service' => $service->id]));

        $this->assertSame('create', $service->properties()->where('key', 'hav_proxy_ipv4_dc_pending_action')->value('value'));
        $this->assertSame('legacy-operation-1', $service->properties()->where('key', 'hav_proxy_ipv4_dc_pending_operation_id')->value('value'));
        $this->assertSame(
            sprintf('paymenter-service-%s', $service->id),
            $service->properties()->where('key', 'hav_proxy_ipv4_dc_external_ref')->value('value'),
        );
        Queue::assertPushed(PollServerOperationJob::class, fn (PollServerOperationJob $job) => $job->operationId === 'legacy-operation-1');

        Http::fake([
            self::BASE_URL . '/api/v3/operations/legacy-operation-1' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 'legacy-operation-1',
                    'action' => 'create',
                    'proxy_kind' => 'ipv4_dc',
                    'external_ref' => sprintf('paymenter-service-%s', $service->id),
                    'resource_id' => 'proxy-1',
                    'state' => 'succeeded',
                    'resource_snapshot' => $this->proxySnapshot(),
                ],
            ]),
        ]);

        (new PollServerOperationJob(
            $service->id,
            'create',
            'legacy-operation-1',
            time() + 60,
            false,
        ))->handle();

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertNull($service->properties()->where('key', 'hav_proxy_ipv4_dc_pending_action')->value('value'));
        $this->assertSame(
            'socks5://user:pass@203.0.113.10:38187',
            $service->properties()->where('key', 'hav_proxy_ipv4_dc_connection_uri')->firstOrFail()->value,
        );
    }

    public function test_all_provider_jobs_share_one_service_lock(): void
    {
        [, $service] = $this->createProviderContext();
        $jobs = [
            new CreateJob($service, false),
            new SuspendJob($service, false),
            new UnsuspendJob($service, false),
            new TerminateJob($service, false),
            new PollServerOperationJob($service->id, 'create', 'operation-1', time() + 60, false),
        ];

        $lockKeys = collect($jobs)->map(function ($job) {
            $middleware = $job->middleware()[0];

            return $middleware->getLockKey($job);
        });

        $this->assertCount(1, $lockKeys->unique());
        $this->assertTrue(collect($jobs)->every(fn ($job) => $job->uniqueFor === 300));
    }

    public function test_cancelled_service_cannot_be_reactivated_by_stale_completion(): void
    {
        [, $service] = $this->createProviderContext();
        $service->update(['status' => Service::STATUS_CANCELLED]);

        ProviderOperationLifecycleService::activate($service, false);

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
    }

    public function test_provider_snapshot_does_not_enable_deferred_lifecycle_for_other_extensions(): void
    {
        [, $service, , , $provider] = $this->createProviderContext();
        $provider->update(['extension' => 'OtherProvider']);
        $service->properties()->createMany([
            ['key' => 'provider_server_id', 'name' => 'Provider server ID', 'value' => (string) $provider->id],
            ['key' => 'provider_extension', 'name' => 'Provider extension', 'value' => 'OtherProvider'],
        ]);

        $this->assertFalse(ProviderOperationLifecycleService::usesDeferredOperations($service));
    }

    public function test_queued_create_does_not_call_provider_after_service_is_cancelled(): void
    {
        [, $service] = $this->createProviderContext();
        $service->update(['status' => Service::STATUS_CANCELLED]);
        Http::fake();

        (new CreateJob($service, false))->handle();

        Http::assertNothingSent();
    }

    public function test_create_completion_after_immediate_cancellation_is_finalized_then_queued_for_delete(): void
    {
        [$extension, $service, , $productOffering] = $this->createProviderContext();

        Http::fake([
            self::BASE_URL . '/api/v3/proxies' => Http::response([
                'success' => true,
                'data' => [
                    'resource' => ['id' => 'proxy-1'],
                    'operation' => ['id' => 'operation-1', 'resource_id' => 'proxy-1', 'state' => 'accepted'],
                ],
            ], 202),
            self::BASE_URL . '/api/v3/operations/operation-1' => function () use ($service) {
                return Http::response([
                    'success' => true,
                    'data' => [
                        'id' => 'operation-1',
                        'action' => 'create',
                        'proxy_kind' => 'ipv4_dc',
                        'external_ref' => $service->properties()->where('key', 'hav_proxy_ipv4_dc_external_ref')->value('value'),
                        'resource_id' => 'proxy-1',
                        'state' => 'succeeded',
                        'resource_snapshot' => $this->proxySnapshot(),
                    ],
                ]);
            },
        ]);

        $pending = $extension->createServer($service, [], ['product_location_offering_id' => $productOffering->id]);
        DB::table('service_cancellations')->insert([
            'service_id' => $service->id,
            'reason' => 'test cancellation race',
            'type' => 'immediate',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Queue::fake();

        (new PollServerOperationJob($service->id, 'create', $pending['provider_operation_id'], time() + 60, false))->handle();

        $this->assertSame('delete', $service->properties()->where('key', 'hav_proxy_ipv4_dc_pending_action')->value('value'));
        $this->assertNotNull($service->properties()->where('key', 'hav_proxy_ipv4_dc_delete_idempotency_key')->value('value'));
        $this->assertSame('proxy-1', $service->properties()->where('key', 'hav_proxy_ipv4_dc_proxy_id')->value('value'));
        Queue::assertPushed(TerminateJob::class, fn (TerminateJob $job) => $job->service->is($service));
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
            self::BASE_URL . '/api/v3/operations/operation-1' => function () use ($service) {
                return Http::response([
                    'success' => true,
                    'data' => [
                        'id' => 'operation-1',
                        'action' => 'create',
                        'proxy_kind' => 'ipv4_dc',
                        'external_ref' => $service->properties()->where('key', 'hav_proxy_ipv4_dc_external_ref')->value('value'),
                        'state' => 'succeeded',
                        'resource_id' => 'proxy-1',
                        'resource_snapshot' => $this->proxySnapshot(),
                    ],
                ]);
            },
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
                'data' => [
                    'id' => 'delete-operation-1',
                    'action' => 'delete',
                    'proxy_kind' => 'ipv4_dc',
                    'resource_id' => 'proxy-1',
                    'state' => 'succeeded',
                ],
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
