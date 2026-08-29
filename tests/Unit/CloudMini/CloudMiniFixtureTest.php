<?php

namespace Tests\Unit\CloudMini;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CloudMiniFixtureTest extends TestCase
{
    private const OPENAPI_FIXTURE = 'openapi_endpoint_metadata';

    private const EXPECTED_FIXTURES = [
        'account_200',
        'account_401',
        'action_success',
        'error_200_error_true',
        'error_400_low_balance',
        'error_429_busy',
        'error_500_internal',
        'openapi_endpoint_metadata',
        'order_config_full',
        'order_config_type_nn',
        'order_config_type_proxy',
        'order_config_type_unsupported_full_catalog',
        'order_success',
        'proxy_list_page_1',
        'proxy_list_page_2',
        'residential_list',
    ];

    private const EXPECTED_ENDPOINTS = [
        ['GET', '/account'],
        ['GET', '/order_config'],
        ['GET', '/order'],
        ['POST', '/order'],
        ['GET', '/vps'],
        ['GET', '/proxy'],
        ['GET', '/action'],
        ['POST', '/action'],
        ['POST', '/residential/order'],
        ['GET', '/residential/list'],
    ];

    public function test_fixture_directory_contains_exactly_the_sixteen_documented_fixtures(): void
    {
        $actual = array_map(
            static fn (string $file): string => basename($file, '.json'),
            glob(self::fixtureDirectory() . '/*.json') ?: [],
        );
        sort($actual);
        $expected = self::EXPECTED_FIXTURES;
        sort($expected);

        $this->assertCount(16, $expected, 'The documented CloudMini fixture inventory must contain exactly 16 files.');
        $this->assertSame(
            $expected,
            $actual,
            'The tests/Fixtures/CloudMini directory must contain exactly the 16 documented fixture files, with no missing or extra files.'
        );
    }

    #[DataProvider('fixtureProvider')]
    public function test_fixture_is_valid_json_object(string $name): void
    {
        $fixture = $this->loadFixture($name);

        $this->assertIsArray($fixture, "Fixture {$name} must decode to a JSON object.");
    }

    #[DataProvider('fixtureProvider')]
    public function test_fixture_has_fixture_metadata_with_boolean_confirmed(string $name): void
    {
        $fixture = $this->loadFixture($name);

        $this->assertArrayHasKey('fixture_metadata', $fixture, "Fixture {$name} must carry a fixture_metadata object.");

        $metadata = $fixture['fixture_metadata'];
        $this->assertIsArray($metadata, "Fixture {$name} fixture_metadata must be an object.");
        $this->assertArrayHasKey('confirmed', $metadata, "Fixture {$name} fixture_metadata must carry a confirmed flag.");
        $this->assertIsBool($metadata['confirmed'], "Fixture {$name} fixture_metadata.confirmed must be a boolean.");
    }

    #[DataProvider('responseFixtureProvider')]
    public function test_response_fixture_body_has_exactly_the_envelope_keys(string $name): void
    {
        $body = $this->loadFixture($name)['body'];

        $this->assertIsArray($body, "Fixture {$name} must carry a body object.");

        foreach (['data', 'error', 'msg'] as $key) {
            $this->assertArrayHasKey($key, $body, "Fixture {$name} body must contain the '{$key}' envelope key.");
        }

        $keys = array_keys($body);
        sort($keys);
        $this->assertSame(['data', 'error', 'msg'], $keys, "Fixture {$name} body must contain only the error, msg and data envelope keys.");
    }

    public function test_openapi_metadata_is_confirmed_and_lists_exactly_the_ten_documented_endpoints(): void
    {
        $name = self::OPENAPI_FIXTURE;
        $fixture = $this->loadFixture($name);

        $metadata = $fixture['fixture_metadata'];
        $this->assertTrue($metadata['confirmed'], "Fixture {$name} is the documented contract and must be confirmed true.");

        $endpoints = $fixture['body']['endpoints'] ?? null;
        $this->assertIsArray($endpoints, "Fixture {$name} must expose an endpoints array.");

        $pairs = [];
        foreach ($endpoints as $index => $endpoint) {
            $this->assertIsArray($endpoint, "Fixture {$name} endpoint {$index} must be an object.");
            $this->assertIsString($endpoint['method'] ?? null, "Fixture {$name} endpoint {$index} must have a string method.");
            $this->assertIsString($endpoint['path'] ?? null, "Fixture {$name} endpoint {$index} must have a string path.");
            $pairs[] = [$endpoint['method'], $endpoint['path']];
        }

        $this->assertSame(
            self::EXPECTED_ENDPOINTS,
            $pairs,
            "Fixture {$name} must document exactly the 10 documented method/path pairs, in the documented order."
        );
    }

    #[DataProvider('responseFixtureProvider')]
    public function test_non_openapi_fixture_is_unconfirmed_and_documents_assumptions(string $name): void
    {
        $metadata = $this->loadFixture($name)['fixture_metadata'];

        $this->assertFalse($metadata['confirmed'], "Fixture {$name} must be confirmed false until verified against the provider.");

        $this->assertArrayHasKey('assumptions', $metadata, "Fixture {$name} must document its assumptions.");

        $assumptions = $metadata['assumptions'];
        $this->assertIsArray($assumptions, "Fixture {$name} assumptions must be an array.");
        $this->assertNotEmpty($assumptions, "Fixture {$name} is unconfirmed, so it must document at least one assumption.");

        foreach ($assumptions as $index => $assumption) {
            $this->assertIsString($assumption, "Fixture {$name} assumption {$index} must be a string.");
            $this->assertNotSame('', $assumption, "Fixture {$name} assumption {$index} must not be empty.");
        }
    }

    public function test_error_fixtures_map_the_documented_http_statuses(): void
    {
        $expected = [
            ['error_400_low_balance', 400],
            ['account_401', 401],
            ['error_429_busy', 429],
            ['error_500_internal', 500],
            ['error_200_error_true', 200],
        ];

        foreach ($expected as [$name, $status]) {
            $fixture = $this->loadFixture($name);
            $metadata = $fixture['fixture_metadata'];

            $this->assertArrayHasKey('http_status', $metadata, "Fixture {$name} must document its http_status.");
            $this->assertSame($status, $metadata['http_status'], "Fixture {$name} must document HTTP status {$status}.");
            $this->assertTrue($fixture['body']['error'], "Fixture {$name} must report error=true for HTTP status {$status}.");
            $this->assertNull($fixture['body']['data'], "Fixture {$name} must carry null data for HTTP status {$status}.");
        }

        $openapi = $this->loadFixture(self::OPENAPI_FIXTURE);
        $failures = $openapi['body']['documented_http_failures'] ?? null;
        $this->assertIsArray($failures, 'openapi_endpoint_metadata must document its known HTTP failures.');

        $keys = array_keys($failures);
        sort($keys);
        $this->assertSame([400, 401, 429, 500], $keys, 'openapi_endpoint_metadata must document exactly the 400, 401, 429 and 500 HTTP failures.');

        foreach ($failures as $status => $description) {
            $this->assertIsString($description, "openapi_endpoint_metadata failure {$status} must have a string description.");
            $this->assertNotSame('', $description, "openapi_endpoint_metadata failure {$status} description must not be empty.");
        }
    }

    #[DataProvider('responseFixtureProvider')]
    public function test_response_fixture_msg_is_fake_diagnostic_text(string $name): void
    {
        $body = $this->loadFixture($name)['body'];

        $this->assertIsString($body['msg'], "Fixture {$name} body.msg must be a string.");

        if ($body['error'] === true) {
            $this->assertNotSame('', $body['msg'], "Fixture {$name} reports error=true, so msg must carry a diagnostic message.");
        } else {
            $this->assertSame('', $body['msg'], "Fixture {$name} reports error=false, so msg must be empty.");
        }

        if ($body['msg'] !== '') {
            $this->assertStringStartsWith('FAKE', $body['msg'], "Fixture {$name} msg is a fake error message and must use the FAKE prefix.");
        }
    }

    public function test_ids_and_credentials_use_the_fake_prefix(): void
    {
        $keys = ['id', 'order_id', 'resource_id', 'username', 'password'];

        foreach (self::EXPECTED_FIXTURES as $name) {
            $fixture = $this->loadFixture($name);
            $values = [];
            $this->collectStringValuesByKeys($fixture, $keys, $values);

            foreach ($values as $key => $keyValues) {
                foreach ($keyValues as $value) {
                    $this->assertStringStartsWith('FAKE', $value, "Fixture {$name} field '{$key}' must use the FAKE prefix, got: {$value}");
                }
            }
        }
    }

    public function test_ips_are_rfc5737_documentation_addresses_only(): void
    {
        foreach (self::EXPECTED_FIXTURES as $name) {
            $fixture = $this->loadFixture($name);
            $values = [];
            $this->collectStringValuesByKeys($fixture, ['ip'], $values);

            foreach ($values['ip'] ?? [] as $ip) {
                $this->assertTrue(
                    $this->isRfc5737Ipv4($ip),
                    "Fixture {$name} uses IP {$ip}, which is outside the RFC 5737 documentation ranges 192.0.2.0/24, 198.51.100.0/24 and 203.0.113.0/24."
                );
            }
        }
    }

    public function test_order_config_type_nn_returns_a_single_internal_normal_item(): void
    {
        $name = 'order_config_type_nn';
        $data = $this->loadFixture($name)['body']['data'];

        $this->assertIsArray($data, "Fixture {$name} data must be an array.");
        $this->assertCount(1, $data, "Fixture {$name} must return exactly one item for the type=nn query.");

        $item = $data[0];
        $this->assertIsArray($item, "Fixture {$name} item must be an object.");
        $this->assertSame('normal', $item['type'] ?? null, "Fixture {$name} item must have the internal type 'normal' (documented catalog inconsistency).");
    }

    public function test_unsupported_order_filter_returns_the_full_catalog(): void
    {
        $full = $this->loadFixture('order_config_full');
        $filtered = $this->loadFixture('order_config_type_unsupported_full_catalog');

        $this->assertSame(
            $full['body']['data'],
            $filtered['body']['data'],
            'Fixture order_config_type_unsupported_full_catalog body.data must equal order_config_full body.data for the unsupported filter.'
        );
    }

    public function test_proxy_list_pagination_pages_items_and_keeps_integer_fields(): void
    {
        $first = $this->loadFixture('proxy_list_page_1')['body']['data'];
        $second = $this->loadFixture('proxy_list_page_2')['body']['data'];

        $this->assertSame(1, $first['page'] ?? null, 'Fixture proxy_list_page_1 must be page 1.');
        $this->assertTrue($first['has_more'] ?? null, 'Fixture proxy_list_page_1 must set has_more=true.');
        $this->assertCount(2, $first['items'] ?? [], 'Fixture proxy_list_page_1 must contain 2 items.');

        $this->assertSame(2, $second['page'] ?? null, 'Fixture proxy_list_page_2 must be page 2.');
        $this->assertFalse($second['has_more'] ?? null, 'Fixture proxy_list_page_2 must set has_more=false.');
        $this->assertCount(1, $second['items'] ?? [], 'Fixture proxy_list_page_2 must contain 1 item.');

        $ids = [];
        foreach (['proxy_list_page_1' => $first, 'proxy_list_page_2' => $second] as $name => $page) {
            $items = $page['items'] ?? null;
            $this->assertIsArray($items, "Fixture {$name} must expose an items array.");

            foreach ($items as $index => $item) {
                $this->assertIsArray($item, "Fixture {$name} item {$index} must be an object.");
                $this->assertArrayHasKey('id', $item, "Fixture {$name} item {$index} must have an id.");
                $ids[] = $item['id'];
                $this->assertIsInt($item['https_port'] ?? null, "Fixture {$name} item {$index} must have an integer https_port.");
                $this->assertIsInt($item['socks_port'] ?? null, "Fixture {$name} item {$index} must have an integer socks_port.");
                $this->assertIsInt($item['price'] ?? null, "Fixture {$name} item {$index} must have an integer price.");
            }
        }

        $this->assertCount(3, $ids, 'The two proxy pages must contain 3 items in total.');
        $this->assertCount(3, array_unique($ids), 'Proxy item ids must be globally unique across both pages.');
    }

    public function test_residential_list_is_a_fake_package_with_a_120_day_interval(): void
    {
        $name = 'residential_list';
        $data = $this->loadFixture($name)['body']['data'];

        $this->assertIsArray($data, "Fixture {$name} data must be an array of packages.");
        $this->assertNotEmpty($data, "Fixture {$name} must contain at least one package.");

        $timezone = new DateTimeZone('UTC');

        foreach ($data as $index => $package) {
            $this->assertIsArray($package, "Fixture {$name} package {$index} must be an object.");
            $this->assertSame('rota.cloudmini.net', $package['host'] ?? null, "Fixture {$name} package {$index} must be bound to rota.cloudmini.net.");
            $this->assertSame(8080, $package['port'] ?? null, "Fixture {$name} package {$index} must use port 8080.");
            $this->assertIsInt($package['bandwidth_total'] ?? null, "Fixture {$name} package {$index} must have an integer bandwidth_total.");
            $this->assertIsInt($package['bandwidth_used'] ?? null, "Fixture {$name} package {$index} must have an integer bandwidth_used.");
            $this->assertStringStartsWith('FAKE', (string) ($package['username'] ?? ''), "Fixture {$name} package {$index} username must use the FAKE prefix.");
            $this->assertStringStartsWith('FAKE', (string) ($package['password'] ?? ''), "Fixture {$name} package {$index} password must use the FAKE prefix.");

            $startDate = (string) ($package['start_date'] ?? '');
            $endDate = (string) ($package['end_date'] ?? '');
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $startDate, "Fixture {$name} package {$index} start_date must be a Y-m-d date.");
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $endDate, "Fixture {$name} package {$index} end_date must be a Y-m-d date.");

            try {
                $start = new DateTimeImmutable($startDate, $timezone);
                $end = new DateTimeImmutable($endDate, $timezone);
            } catch (Exception $exception) {
                $this->fail("Fixture {$name} package {$index} dates are not valid calendar dates: {$exception->getMessage()}");
            }

            $seconds = $end->getTimestamp() - $start->getTimestamp();
            $this->assertSame(120 * 86400, $seconds, "Fixture {$name} package {$index} end_date must be exactly 120 days after start_date.");
        }
    }

    public static function fixtureProvider(): array
    {
        $cases = [];
        foreach (self::EXPECTED_FIXTURES as $name) {
            $cases[] = [$name];
        }

        return $cases;
    }

    public static function responseFixtureProvider(): array
    {
        $cases = [];
        foreach (array_diff(self::EXPECTED_FIXTURES, [self::OPENAPI_FIXTURE]) as $name) {
            $cases[] = [$name];
        }

        return $cases;
    }

    private static function fixtureDirectory(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/CloudMini';
    }

    private function loadFixture(string $name): array
    {
        $path = self::fixtureDirectory() . '/' . $name . '.json';

        $this->assertFileExists($path, "Fixture file {$name}.json is missing from tests/Fixtures/CloudMini.");

        $content = file_get_contents($path);
        $this->assertNotFalse($content, "Fixture file {$name}.json could not be read.");

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->fail("Fixture {$name}.json is not valid JSON: {$exception->getMessage()}");
        }

        $this->assertIsArray($decoded, "Fixture {$name}.json must decode to a JSON object.");

        return $decoded;
    }

    private function isRfc5737Ipv4(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== $ip) {
            return false;
        }

        $octets = explode('.', $ip);

        return in_array($octets[0] . '.' . $octets[1] . '.' . $octets[2], ['192.0.2', '198.51.100', '203.0.113'], true);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $keys
     * @param  array<string, array<int, string>>  $values
     */
    private function collectStringValuesByKeys(array $node, array $keys, array &$values): void
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->collectStringValuesByKeys($value, $keys, $values);
            } elseif (is_string($value) && in_array($key, $keys, true) && $value !== '') {
                $values[$key][] = $value;
            }
        }
    }
}
