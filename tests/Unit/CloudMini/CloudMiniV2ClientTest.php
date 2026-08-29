<?php

namespace Tests\Unit\CloudMini;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use Paymenter\Extensions\Servers\CloudMini\CloudMiniApiException;
use Paymenter\Extensions\Servers\CloudMini\CloudMiniV2Client;
use Tests\TestCase;

class CloudMiniV2ClientTest extends TestCase
{
    private const BASE_URL = 'https://client.cloudmini.net/api/v2';

    private const TOKEN = 'FAKE-TEST-TOKEN';

    /**
     * @var list<Request>
     */
    private array $recorded = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorded = [];

        Http::preventStrayRequests();
    }

    public function test_order_config_without_type_requests_exact_normalized_endpoint_and_returns_fixture_data(): void
    {
        $body = $this->fixtureBody('order_config_full');

        $this->fakeBodies([
            self::BASE_URL . '/order_config' => $body,
        ]);

        $data = $this->client()->orderConfig();

        $this->assertSame($body['data'], $data, 'orderConfig() must return the order_config_full fixture data.');

        $requests = $this->requests();
        $this->assertCount(1, $requests, 'orderConfig() must send exactly one request.');

        $request = $requests[0];
        $this->assertSame('GET', $request->method());
        $this->assertSame(
            self::BASE_URL . '/order_config',
            (string) $request->url(),
            'The trailing-slash base URL must be normalized and no query string may be added.'
        );
        $this->assertAcceptJson($request);
        $this->assertNoAuthorization($request);
        $this->assertTokenFreeUrls();
    }

    public function test_order_config_with_type_sends_type_query_and_still_sends_no_authorization(): void
    {
        $body = $this->fixtureBody('order_config_type_proxy');

        $this->fakeBodies([
            self::BASE_URL . '/order_config?type=proxy' => $body,
        ]);

        $data = $this->client()->orderConfig('proxy');

        $this->assertSame($body['data'], $data, 'orderConfig("proxy") must return the order_config_type_proxy fixture data.');

        $requests = $this->requests();
        $this->assertCount(1, $requests, 'orderConfig("proxy") must send exactly one request.');

        $request = $requests[0];
        $this->assertSame('GET', $request->method());
        $this->assertSame(
            self::BASE_URL . '/order_config?type=proxy',
            (string) $request->url(),
            'orderConfig("proxy") must send the exact type=proxy query.'
        );
        $this->assertAcceptJson($request);
        $this->assertNoAuthorization($request);
        $this->assertTokenFreeUrls();
    }

    public function test_account_requests_exact_endpoint_with_exact_token_authorization(): void
    {
        $body = $this->fixtureBody('account_200');

        $this->fakeBodies([
            self::BASE_URL . '/account' => $body,
        ]);

        $data = $this->client()->account();

        $this->assertSame($body['data'], $data, 'account() must return the account_200 fixture data.');

        $requests = $this->requests();
        $this->assertCount(1, $requests, 'account() must send exactly one request.');

        $request = $requests[0];
        $this->assertSame('GET', $request->method());
        $this->assertSame(self::BASE_URL . '/account', (string) $request->url());
        $this->assertAcceptJson($request);
        $this->assertExactAuthorization($request);
        $this->assertTokenFreeUrls();
    }

    public function test_find_order_sends_get_order_with_id_query_and_returns_fake_data(): void
    {
        $body = [
            'error' => false,
            'msg' => '',
            'data' => [
                'order_id' => 'FAKE-ORD-000042',
                'status' => 'paid',
                'price' => 150000,
            ],
        ];

        $this->fakeBodies([
            self::BASE_URL . '/order?id=99' => $body,
        ]);

        $data = $this->client()->findOrder(99);

        $this->assertSame($body['data'], $data, 'findOrder() must return the fake order data.');

        $requests = $this->requests();
        $this->assertCount(1, $requests, 'findOrder() must send exactly one request.');

        $request = $requests[0];
        $this->assertSame('GET', $request->method());
        $this->assertSame(
            self::BASE_URL . '/order?id=99',
            (string) $request->url(),
            'findOrder() must send GET /order with the id query.'
        );
        $this->assertAcceptJson($request);
        $this->assertExactAuthorization($request);
        $this->assertTokenFreeUrls();
    }

    public function test_create_order_posts_exact_form_payload_once_and_returns_order_success(): void
    {
        $body = $this->fixtureBody('order_success');
        $payload = [
            'type' => 'normal',
            'region' => 'California',
            'plan' => '1 vCPU / 1 GB / 10 GB',
            'os' => 'Ubuntu 22.04',
            'amount' => '2',
        ];

        $this->fakeBodies([
            self::BASE_URL . '/order' => $body,
        ]);

        $data = $this->client()->createOrder($payload);

        $this->assertSame($body['data'], $data, 'createOrder() must return the order_success fixture data.');

        $requests = $this->requests();
        $this->assertCount(1, $requests, 'createOrder() must send exactly one POST.');

        $request = $requests[0];
        $this->assertSame('POST', $request->method());
        $this->assertSame(self::BASE_URL . '/order', (string) $request->url());
        $this->assertAcceptJson($request);
        $this->assertExactAuthorization($request);
        $this->assertExactFormPayload($request, $payload);
        $this->assertTokenFreeUrls();
    }

    public function test_vps_sends_page_one_get_and_returns_direct_list(): void
    {
        $body = [
            'error' => false,
            'msg' => '',
            'data' => [
                [
                    'id' => 'FAKE-VPS-000001',
                    'ip' => '192.0.2.21',
                    'cpu' => '2',
                    'ram' => '4 GB',
                    'disk' => '40 GB',
                    'price' => 150000,
                ],
                [
                    'id' => 'FAKE-VPS-000002',
                    'ip' => '198.51.100.42',
                    'cpu' => '4',
                    'ram' => '8 GB',
                    'disk' => '80 GB',
                    'price' => 260000,
                ],
            ],
        ];

        $this->fakeBodies([
            self::BASE_URL . '/vps?page=1' => $body,
        ]);

        $data = $this->client()->vps();

        $this->assertSame($body['data'], $data, 'vps() must return the direct list without pagination.');

        $requests = $this->requests();
        $this->assertCount(1, $requests, 'vps() must send exactly one GET.');

        $request = $requests[0];
        $this->assertSame('GET', $request->method());
        $this->assertSame(self::BASE_URL . '/vps?page=1', (string) $request->url());
        $this->assertAcceptJson($request);
        $this->assertExactAuthorization($request);
        $this->assertTokenFreeUrls();
    }

    public function test_proxies_sends_page_one_then_page_two_and_returns_all_items_in_order(): void
    {
        $pageOne = $this->fixtureBody('proxy_list_page_1');
        $pageTwo = $this->fixtureBody('proxy_list_page_2');

        $this->fakeBodies([
            self::BASE_URL . '/proxy?page=1' => $pageOne,
            self::BASE_URL . '/proxy?page=2' => $pageTwo,
        ]);

        $items = $this->client()->proxies();

        $expected = array_merge($pageOne['data']['items'], $pageTwo['data']['items']);
        $this->assertSame($expected, $items, 'proxies() must return all items from both pages, in order.');

        $this->assertCount(3, $items, 'The two proxy pages must contribute exactly 3 items.');
        $this->assertSame(
            ['FAKE-PRX-000001', 'FAKE-PRX-000002', 'FAKE-PRX-000003'],
            array_column($items, 'id'),
            'Proxy item ids must be in page order.'
        );
        $this->assertCount(3, array_unique(array_column($items, 'id')), 'Proxy item ids must be unique.');

        $requests = $this->requests();
        $this->assertCount(2, $requests, 'proxies() must send exactly two GETs, one per page.');

        $first = $requests[0];
        $this->assertSame('GET', $first->method());
        $this->assertSame(self::BASE_URL . '/proxy?page=1', (string) $first->url());
        $this->assertAcceptJson($first);
        $this->assertExactAuthorization($first);

        $second = $requests[1];
        $this->assertSame('GET', $second->method());
        $this->assertSame(self::BASE_URL . '/proxy?page=2', (string) $second->url());
        $this->assertAcceptJson($second);
        $this->assertExactAuthorization($second);

        $this->assertTokenFreeUrls();
    }

    public function test_available_actions_sends_get_action_and_returns_list(): void
    {
        $body = [
            'error' => false,
            'msg' => '',
            'data' => ['renew', 'restart', 'reset', 'delete'],
        ];

        $this->fakeBodies([
            self::BASE_URL . '/action' => $body,
        ]);

        $actions = $this->client()->availableActions();

        $this->assertSame($body['data'], $actions, 'availableActions() must return the action list.');
        $this->assertTrue(array_is_list($actions), 'availableActions() must return a plain list.');

        $requests = $this->requests();
        $this->assertCount(1, $requests, 'availableActions() must send exactly one request.');

        $request = $requests[0];
        $this->assertSame('GET', $request->method());
        $this->assertSame(self::BASE_URL . '/action', (string) $request->url());
        $this->assertAcceptJson($request);
        $this->assertExactAuthorization($request);
        $this->assertTokenFreeUrls();
    }

    public function test_perform_action_posts_exact_form_payload_once_and_returns_action_success(): void
    {
        $body = $this->fixtureBody('action_success');
        $payload = [
            'action' => 'renew',
            'resource_id' => 'FAKE-RES-000007',
        ];

        $this->fakeBodies([
            self::BASE_URL . '/action' => $body,
        ]);

        $data = $this->client()->performAction($payload);

        $this->assertSame($body['data'], $data, 'performAction() must return the action_success fixture data.');

        $requests = $this->requests();
        $this->assertCount(1, $requests, 'performAction() must send exactly one POST.');

        $request = $requests[0];
        $this->assertSame('POST', $request->method());
        $this->assertSame(self::BASE_URL . '/action', (string) $request->url());
        $this->assertAcceptJson($request);
        $this->assertExactAuthorization($request);
        $this->assertExactFormPayload($request, $payload);
        $this->assertTokenFreeUrls();
    }

    public function test_create_residential_order_posts_exact_form_payload_once_and_returns_fake_data(): void
    {
        $body = [
            'error' => false,
            'msg' => '',
            'data' => [
                'order_id' => 'FAKE-ORD-000003',
                'resource_id' => 'FAKE-RSL-000002',
                'price' => 90000,
            ],
        ];
        $payload = [
            'type' => 'residential',
            'region' => 'US',
            'port' => '8080',
            'days' => '120',
        ];

        $this->fakeBodies([
            self::BASE_URL . '/residential/order' => $body,
        ]);

        $data = $this->client()->createResidentialOrder($payload);

        $this->assertSame($body['data'], $data, 'createResidentialOrder() must return the fake residential order data.');

        $requests = $this->requests();
        $this->assertCount(1, $requests, 'createResidentialOrder() must send exactly one POST.');

        $request = $requests[0];
        $this->assertSame('POST', $request->method());
        $this->assertSame(self::BASE_URL . '/residential/order', (string) $request->url());
        $this->assertAcceptJson($request);
        $this->assertExactAuthorization($request);
        $this->assertExactFormPayload($request, $payload);
        $this->assertTokenFreeUrls();
    }

    public function test_residential_list_sends_page_one_get_and_returns_direct_list(): void
    {
        $body = $this->fixtureBody('residential_list');

        $this->fakeBodies([
            self::BASE_URL . '/residential/list?page=1' => $body,
        ]);

        $data = $this->client()->residentialList();

        $this->assertSame($body['data'], $data, 'residentialList() must return the residential_list fixture data as a direct list.');
        $this->assertTrue(array_is_list($data), 'residentialList() must return a plain list.');

        $requests = $this->requests();
        $this->assertCount(1, $requests, 'residentialList() must send exactly one GET.');

        $request = $requests[0];
        $this->assertSame('GET', $request->method());
        $this->assertSame(self::BASE_URL . '/residential/list?page=1', (string) $request->url());
        $this->assertAcceptJson($request);
        $this->assertExactAuthorization($request);
        $this->assertTokenFreeUrls();
    }

    public function test_constructor_rejects_non_https_base_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('baseUrl must use the https scheme.');

        new CloudMiniV2Client('http://client.cloudmini.net/api/v2', self::TOKEN);
    }

    public function test_constructor_rejects_base_url_with_userinfo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('baseUrl must not contain user or password information.');

        new CloudMiniV2Client('https://fake-user:fake-pass@client.cloudmini.net/api/v2', self::TOKEN);
    }

    public function test_constructor_rejects_base_url_with_query_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('baseUrl must not contain a query string.');

        new CloudMiniV2Client('https://client.cloudmini.net/api/v2?region=us', self::TOKEN);
    }

    public function test_constructor_rejects_base_url_with_fragment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('baseUrl must not contain a fragment.');

        new CloudMiniV2Client('https://client.cloudmini.net/api/v2#token', self::TOKEN);
    }

    public function test_constructor_rejects_blank_token(): void
    {
        foreach (['', '   '] as $token) {
            try {
                new CloudMiniV2Client(self::BASE_URL, $token);
                $this->fail('Constructor must reject the blank token ' . var_export($token, true) . '.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'token must be non-empty and must not contain CR or LF characters.',
                    $exception->getMessage(),
                    'The blank token rejection must be explicit.'
                );
            }
        }
    }

    public function test_constructor_rejects_nonpositive_settings(): void
    {
        $cases = [
            [0, 10, 3, 0, 'timeout must be a positive number of seconds.'],
            [30, 0, 3, 0, 'connectTimeout must be a positive number of seconds.'],
            [30, 10, 0, 0, 'maxGetAttempts must be a positive integer.'],
            [30, 10, 3, -1, 'getRetryDelayMs must be a non-negative number of milliseconds.'],
        ];

        foreach ($cases as [$timeout, $connectTimeout, $maxGetAttempts, $getRetryDelayMs, $message]) {
            try {
                new CloudMiniV2Client(self::BASE_URL, self::TOKEN, $timeout, $connectTimeout, $maxGetAttempts, $getRetryDelayMs);
                $this->fail(
                    "Constructor must reject timeout={$timeout}, connectTimeout={$connectTimeout}, "
                    . "maxGetAttempts={$maxGetAttempts}, getRetryDelayMs={$getRetryDelayMs}."
                );
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($message, $exception->getMessage(), 'The rejected setting must name its exact reason.');
            }
        }
    }

    public function test_get_500_retries_and_then_succeeds(): void
    {
        $internalError = ['error' => true, 'msg' => 'FAKE: Internal error.', 'data' => null];
        $valid = [
            'error' => false,
            'msg' => 'FAKE: Account retrieved.',
            'data' => [
                'id' => 'FAKE-ACC-000042',
                'plan' => 'fake-plan',
                'balance' => 1500,
            ],
        ];

        $this->fakeSequence(self::BASE_URL . '/account', [
            [$internalError, 500],
            [$internalError, 500],
            [$valid, 200],
        ]);

        $data = $this->client()->account();

        $this->assertSame($valid['data'], $data, 'account() must return the data of the third (successful) attempt.');
        $this->assertCount(3, $this->requests(), 'GET 500 must be retried until the third attempt succeeds.');

        foreach ($this->requests() as $index => $request) {
            $this->assertSame('GET', $request->method(), 'Attempt ' . ($index + 1) . ' must be a GET.');
            $this->assertSame(self::BASE_URL . '/account', (string) $request->url(), 'Every retry must hit the same URL.');
        }
    }

    public function test_terminal_get_500_makes_exactly_three_requests_and_throws_retryable_status_500(): void
    {
        $internalError = ['error' => true, 'msg' => 'FAKE: Internal error.', 'data' => null];

        $this->fakeSequence(self::BASE_URL . '/account', [
            [$internalError, 500],
            [$internalError, 500],
            [$internalError, 500],
        ]);

        $exception = $this->clientException(fn () => $this->client()->account());

        $this->assertSame(500, $exception->status, 'Terminal GET 500 must expose http status 500.');
        $this->assertTrue($exception->retryable, 'Terminal GET 500 must be marked retryable.');
        $this->assertFalse($exception->ambiguous, 'Terminal GET 500 must not be ambiguous.');
        $this->assertSame('http_error', $exception->safeContext['reason'] ?? null, 'Terminal GET 500 must carry the http_error reason.');
        $this->assertCount(3, $this->requests(), 'Terminal GET 500 must make exactly maxGetAttempts=3 requests.');
    }

    public function test_get_429_retries_and_then_succeeds(): void
    {
        $rateLimited = ['error' => true, 'msg' => 'FAKE: Too many requests.', 'data' => null];
        $valid = [
            'error' => false,
            'msg' => 'FAKE: Account retrieved.',
            'data' => [
                'id' => 'FAKE-ACC-000042',
                'plan' => 'fake-plan',
                'balance' => 1500,
            ],
        ];

        $this->fakeSequence(self::BASE_URL . '/account', [
            [$rateLimited, 429],
            [$valid, 200],
        ]);

        $data = $this->client()->account();

        $this->assertSame($valid['data'], $data, 'account() must return the data of the second (successful) attempt.');
        $this->assertCount(2, $this->requests(), 'GET 429 must be retried once and then succeed.');
    }

    public function test_get_400_makes_one_request_and_preserves_status_with_no_retry(): void
    {
        $this->fakeSequence(self::BASE_URL . '/order?id=99', [
            [['error' => true, 'msg' => 'FAKE: Invalid order id.', 'data' => null], 400],
        ]);

        $exception = $this->clientException(fn () => $this->client()->findOrder(99));

        $this->assertSame(400, $exception->status, 'GET 400 must preserve http status 400.');
        $this->assertFalse($exception->retryable, 'GET 400 must not be retryable.');
        $this->assertFalse($exception->ambiguous, 'GET 400 must not be ambiguous.');
        $this->assertCount(1, $this->requests(), 'GET 400 must make exactly one request.');
    }

    public function test_get_401_makes_one_request_and_preserves_status_with_no_retry(): void
    {
        $this->fakeSequence(self::BASE_URL . '/account', [
            [['error' => true, 'msg' => 'FAKE: Invalid or missing token.', 'data' => null], 401],
        ]);

        $exception = $this->clientException(fn () => $this->client()->account());

        $this->assertSame(401, $exception->status, 'GET 401 must preserve http status 401.');
        $this->assertFalse($exception->retryable, 'GET 401 must not be retryable.');
        $this->assertFalse($exception->ambiguous, 'GET 401 must not be ambiguous.');
        $this->assertCount(1, $this->requests(), 'GET 401 must make exactly one request.');
    }

    public function test_malformed_2xx_get_retries_and_then_succeeds(): void
    {
        $valid = [
            'error' => false,
            'msg' => 'FAKE: Order retrieved.',
            'data' => [
                'order_id' => 'FAKE-ORD-000042',
                'status' => 'paid',
                'price' => 150000,
            ],
        ];

        $this->fakeSequence(self::BASE_URL . '/order?id=99', [
            ['FAKE: not json at all', 200],
            ['{definitely-not-json', 200],
            [$valid, 200],
        ]);

        $data = $this->client()->findOrder(99);

        $this->assertSame($valid['data'], $data, 'findOrder() must return the data of the third (valid) attempt.');
        $this->assertCount(3, $this->requests(), 'A malformed 2xx body must be retried up to maxGetAttempts.');
    }

    public function test_valid_200_envelope_with_error_true_makes_one_request_and_throws_status_200(): void
    {
        $this->fakeSequence(self::BASE_URL . '/account', [
            [['error' => true, 'msg' => 'FAKE: Invalid or missing token.', 'data' => null], 200],
        ]);

        $exception = $this->clientException(fn () => $this->client()->account());

        $this->assertSame(200, $exception->status, 'A 200 envelope with error=true must throw with status 200.');
        $this->assertFalse($exception->retryable, 'A 200 envelope with error=true must not be retryable.');
        $this->assertFalse($exception->ambiguous, 'A 200 envelope with error=true must not be ambiguous.');
        $this->assertCount(1, $this->requests(), 'A 200 envelope with error=true must make exactly one request.');
    }

    public function test_get_redirect_302_makes_one_request_and_throws_status_302(): void
    {
        $this->fakeSequence(self::BASE_URL . '/account', [
            ['FAKE: redirect body', 302, ['Location' => 'https://client.cloudmini.net/login']],
        ]);

        $exception = $this->clientException(fn () => $this->client()->account());

        $this->assertSame(302, $exception->status, 'A GET 302 redirect must throw with status 302.');
        $this->assertFalse($exception->retryable, 'A GET 302 redirect must not be retryable.');
        $this->assertFalse($exception->ambiguous, 'A GET 302 redirect must not be ambiguous.');
        $this->assertCount(1, $this->requests(), 'A GET 302 redirect must make exactly one request.');
    }

    public function test_create_order_429_makes_one_request_and_throws_retryable_status_429(): void
    {
        $payload = [
            'type' => 'normal',
            'region' => 'FAKE-REGION',
            'plan' => 'FAKE-PLAN',
            'os' => 'FAKE-OS',
            'amount' => '1',
        ];

        $this->fakeSequence(self::BASE_URL . '/order', [
            [['error' => true, 'msg' => 'FAKE: Too many requests.', 'data' => null], 429],
        ]);

        $exception = $this->clientException(fn () => $this->client()->createOrder($payload));

        $this->assertSame(429, $exception->status, 'A POST 429 must preserve http status 429.');
        $this->assertTrue($exception->retryable, 'A POST 429 must be marked retryable.');
        $this->assertFalse($exception->ambiguous, 'A POST 429 must not be ambiguous.');
        $this->assertCount(1, $this->requests(), 'A POST 429 must make exactly one request.');

        $request = $this->requests()[0];
        $this->assertSame('POST', $request->method(), 'A POST 429 attempt must be a POST.');
        $this->assertSame(self::BASE_URL . '/order', (string) $request->url(), 'A POST 429 attempt must hit the exact /order URL.');
    }

    public function test_create_order_500_makes_one_request_and_throws_ambiguous_status_500(): void
    {
        $payload = [
            'type' => 'normal',
            'region' => 'FAKE-REGION',
            'plan' => 'FAKE-PLAN',
            'os' => 'FAKE-OS',
            'amount' => '1',
        ];

        $this->fakeSequence(self::BASE_URL . '/order', [
            [['error' => true, 'msg' => 'FAKE: Internal error.', 'data' => null], 500],
        ]);

        $exception = $this->clientException(fn () => $this->client()->createOrder($payload));

        $this->assertSame(500, $exception->status, 'A POST 500 must preserve http status 500.');
        $this->assertFalse($exception->retryable, 'A POST 500 must not be retryable.');
        $this->assertTrue($exception->ambiguous, 'A POST 500 must be ambiguous because the order may have been created.');
        $this->assertCount(1, $this->requests(), 'A POST 500 must make exactly one request.');

        $request = $this->requests()[0];
        $this->assertSame('POST', $request->method(), 'A POST 500 attempt must be a POST.');
        $this->assertSame(self::BASE_URL . '/order', (string) $request->url(), 'A POST 500 attempt must hit the exact /order URL.');
    }

    public function test_create_order_500_keeps_provider_message_and_payload_secrets_out_of_exception(): void
    {
        $payload = [
            'username' => 'FAKE-USER-CANARY',
            'password' => 'FAKE-PASSWORD-CANARY',
        ];

        $formBody = http_build_query($payload);
        $jsonBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $providerMessage = self::TOKEN . self::BASE_URL . 'FAKE-USER-CANARY' . 'FAKE-PASSWORD-CANARY' . $formBody . $jsonBody;

        $this->fakeSequence(self::BASE_URL . '/order', [
            [['error' => true, 'msg' => $providerMessage, 'data' => null], 500],
        ]);

        $exception = $this->clientException(fn () => $this->client()->createOrder($payload));

        $combined = $exception->getMessage() . json_encode($exception->safeContext, JSON_THROW_ON_ERROR);

        $secrets = [
            self::TOKEN,
            self::BASE_URL,
            'FAKE-USER-CANARY',
            'FAKE-PASSWORD-CANARY',
            $formBody,
            $jsonBody,
            $providerMessage,
        ];

        foreach ($secrets as $index => $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $combined,
                "The exception message and safe context must not leak secret #{$index}."
            );
        }

        $this->assertSame('POST', $exception->method, 'A POST 500 must report method POST.');
        $this->assertSame('/order', $exception->path, 'A POST 500 must report path /order.');
        $this->assertSame(500, $exception->status, 'A POST 500 must preserve http status 500.');
        $this->assertTrue($exception->ambiguous, 'A POST 500 must be ambiguous because the order may have been created.');
        $this->assertFalse($exception->retryable, 'A POST 500 must not be retryable.');
        $this->assertCount(1, $this->requests(), 'A POST 500 must make exactly one request.');
    }

    public function test_create_order_400_makes_one_request_and_preserves_status_with_no_retry(): void
    {
        $payload = [
            'type' => 'normal',
            'region' => 'FAKE-REGION',
            'plan' => 'FAKE-PLAN',
            'os' => 'FAKE-OS',
            'amount' => '1',
        ];

        $this->fakeSequence(self::BASE_URL . '/order', [
            [['error' => true, 'msg' => 'FAKE: Invalid order payload.', 'data' => null], 400],
        ]);

        $exception = $this->clientException(fn () => $this->client()->createOrder($payload));

        $this->assertSame(400, $exception->status, 'A POST 400 must preserve http status 400.');
        $this->assertFalse($exception->retryable, 'A POST 400 must not be retryable.');
        $this->assertFalse($exception->ambiguous, 'A POST 400 must not be ambiguous.');
        $this->assertCount(1, $this->requests(), 'A POST 400 must make exactly one request.');

        $request = $this->requests()[0];
        $this->assertSame('POST', $request->method(), 'A POST 400 attempt must be a POST.');
        $this->assertSame(self::BASE_URL . '/order', (string) $request->url(), 'A POST 400 attempt must hit the exact /order URL.');
    }

    public function test_create_order_401_makes_one_request_and_preserves_status_with_no_retry(): void
    {
        $payload = [
            'type' => 'normal',
            'region' => 'FAKE-REGION',
            'plan' => 'FAKE-PLAN',
            'os' => 'FAKE-OS',
            'amount' => '1',
        ];

        $this->fakeSequence(self::BASE_URL . '/order', [
            [['error' => true, 'msg' => 'FAKE: Invalid or missing token.', 'data' => null], 401],
        ]);

        $exception = $this->clientException(fn () => $this->client()->createOrder($payload));

        $this->assertSame(401, $exception->status, 'A POST 401 must preserve http status 401.');
        $this->assertFalse($exception->retryable, 'A POST 401 must not be retryable.');
        $this->assertFalse($exception->ambiguous, 'A POST 401 must not be ambiguous.');
        $this->assertCount(1, $this->requests(), 'A POST 401 must make exactly one request.');

        $request = $this->requests()[0];
        $this->assertSame('POST', $request->method(), 'A POST 401 attempt must be a POST.');
        $this->assertSame(self::BASE_URL . '/order', (string) $request->url(), 'A POST 401 attempt must hit the exact /order URL.');
    }

    public function test_create_order_malformed_200_makes_one_request_and_throws_ambiguous_status_200(): void
    {
        $payload = [
            'type' => 'normal',
            'region' => 'FAKE-REGION',
            'plan' => 'FAKE-PLAN',
            'os' => 'FAKE-OS',
            'amount' => '1',
        ];

        $this->fakeSequence(self::BASE_URL . '/order', [
            ['FAKE: not json at all', 200],
        ]);

        $exception = $this->clientException(fn () => $this->client()->createOrder($payload));

        $this->assertSame(200, $exception->status, 'A malformed 200 POST body must throw with status 200.');
        $this->assertFalse($exception->retryable, 'A malformed 200 POST body must not be retryable.');
        $this->assertTrue($exception->ambiguous, 'A malformed 200 POST body must be ambiguous because the order may have been created.');
        $this->assertCount(1, $this->requests(), 'A malformed 200 POST body must make exactly one request.');

        $request = $this->requests()[0];
        $this->assertSame('POST', $request->method(), 'A malformed 200 POST attempt must be a POST.');
        $this->assertSame(self::BASE_URL . '/order', (string) $request->url(), 'A malformed 200 POST attempt must hit the exact /order URL.');
    }

    public function test_create_order_valid_200_envelope_with_error_true_makes_one_request_and_throws_status_200(): void
    {
        $payload = [
            'type' => 'normal',
            'region' => 'FAKE-REGION',
            'plan' => 'FAKE-PLAN',
            'os' => 'FAKE-OS',
            'amount' => '1',
        ];

        $this->fakeSequence(self::BASE_URL . '/order', [
            [['error' => true, 'msg' => 'FAKE: Invalid order payload.', 'data' => null], 200],
        ]);

        $exception = $this->clientException(fn () => $this->client()->createOrder($payload));

        $this->assertSame(200, $exception->status, 'A 200 envelope with error=true must throw with status 200.');
        $this->assertFalse($exception->retryable, 'A 200 envelope with error=true must not be retryable.');
        $this->assertFalse($exception->ambiguous, 'A 200 envelope with error=true must not be ambiguous.');
        $this->assertCount(1, $this->requests(), 'A 200 envelope with error=true must make exactly one request.');

        $request = $this->requests()[0];
        $this->assertSame('POST', $request->method(), 'A 200 envelope error POST attempt must be a POST.');
        $this->assertSame(self::BASE_URL . '/order', (string) $request->url(), 'A 200 envelope error POST attempt must hit the exact /order URL.');
    }

    public function test_create_order_302_makes_one_request_and_throws_ambiguous_status_302(): void
    {
        $payload = [
            'type' => 'normal',
            'region' => 'FAKE-REGION',
            'plan' => 'FAKE-PLAN',
            'os' => 'FAKE-OS',
            'amount' => '1',
        ];

        $this->fakeSequence(self::BASE_URL . '/order', [
            ['FAKE: redirect body', 302, ['Location' => 'https://client.cloudmini.net/login']],
        ]);

        $exception = $this->clientException(fn () => $this->client()->createOrder($payload));

        $this->assertSame(302, $exception->status, 'A POST 302 redirect must throw with status 302.');
        $this->assertFalse($exception->retryable, 'A POST 302 redirect must not be retryable.');
        $this->assertTrue($exception->ambiguous, 'A POST 302 redirect must be ambiguous because the order may have been created.');
        $this->assertCount(1, $this->requests(), 'A POST 302 redirect must make exactly one request.');

        $request = $this->requests()[0];
        $this->assertSame('POST', $request->method(), 'A POST 302 attempt must be a POST.');
        $this->assertSame(self::BASE_URL . '/order', (string) $request->url(), 'A POST 302 attempt must hit the exact /order URL.');
    }

    public function test_create_order_failed_connection_makes_one_request_and_throws_ambiguous_null_status(): void
    {
        $payload = [
            'type' => 'normal',
            'region' => 'FAKE-REGION',
            'plan' => 'FAKE-PLAN',
            'os' => 'FAKE-OS',
            'amount' => '1',
        ];

        Http::fake([self::BASE_URL . '/order' => Http::failedConnection('FAKE-CONNECTION-CANARY')]);

        $exception = $this->clientException(fn () => $this->client()->createOrder($payload));

        $this->assertNull($exception->status, 'A failed POST connection must throw with a null http status.');
        $this->assertFalse($exception->retryable, 'A failed POST connection must not be retryable.');
        $this->assertTrue($exception->ambiguous, 'A failed POST connection must be ambiguous because the order may have been created.');

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' && (string) $request->url() === self::BASE_URL . '/order';
        });
        Http::assertSentCount(1);
    }

    public function test_proxies_page_mismatch_throws_unexpected_payload(): void
    {
        $this->fakeSequence(self::BASE_URL . '/proxy?page=1', [
            [[
                'error' => false,
                'msg' => 'FAKE: proxy page.',
                'data' => [
                    'page' => 2,
                    'has_more' => false,
                    'items' => [],
                ],
            ], 200],
        ]);

        $exception = $this->clientException(fn () => $this->client()->proxies());

        $this->assertSame(
            'unexpected_payload',
            $exception->safeContext['reason'] ?? null,
            'A page number mismatch must throw the unexpected_payload reason.'
        );
        $this->assertFalse($exception->retryable, 'An unexpected_payload exception must not be retryable.');
        $this->assertFalse($exception->ambiguous, 'An unexpected_payload exception must not be ambiguous.');
        $this->assertCount(1, $this->requests(), 'A page mismatch must fail fast after the first request.');
    }

    public function test_proxies_page_100_with_has_more_makes_one_request_and_throws_page_limit_reached(): void
    {
        $this->fakeSequence(self::BASE_URL . '/proxy?page=100', [
            [[
                'error' => false,
                'msg' => 'FAKE: proxy page 100.',
                'data' => [
                    'page' => 100,
                    'has_more' => true,
                    'items' => [
                        [
                            'id' => 'FAKE-PRX-000100',
                            'ip' => '203.0.113.7',
                            'country' => 'US',
                            'port' => '8080',
                        ],
                    ],
                ],
            ], 200],
        ]);

        $exception = $this->clientException(fn () => $this->client()->proxies(['page' => 100]));

        $this->assertSame(
            'page_limit_reached',
            $exception->safeContext['reason'] ?? null,
            'Starting at page 100 with has_more=true must throw page_limit_reached.'
        );
        $this->assertSame(100, $exception->safeContext['max_pages'] ?? null, 'The page limit exception must document max_pages=100.');
        $this->assertFalse($exception->retryable, 'A page_limit_reached exception must not be retryable.');
        $this->assertFalse($exception->ambiguous, 'A page_limit_reached exception must not be ambiguous.');
        $this->assertCount(1, $this->requests(), 'The page limit must be enforced on the first request at page 100.');
    }

    /**
     * Build the client from a trailing-slash base URL with retry delay 0,
     * proving URL normalization while keeping the tests free of sleeps.
     */
    private function client(): CloudMiniV2Client
    {
        return new CloudMiniV2Client(self::BASE_URL . '/', self::TOKEN, 30, 10, 3, 0);
    }

    /**
     * Load the sanitized fixture body array from tests/Fixtures/CloudMini.
     */
    private function fixtureBody(string $name): array
    {
        $path = dirname(__DIR__, 2) . '/Fixtures/CloudMini/' . $name . '.json';

        $this->assertFileExists($path, "Fixture {$name} must exist in tests/Fixtures/CloudMini.");

        $content = file_get_contents($path);
        $this->assertNotFalse($content, "Fixture {$name} could not be read.");

        try {
            $fixture = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->fail("Fixture {$name} is not valid JSON: {$exception->getMessage()}");
        }

        $this->assertIsArray($fixture, "Fixture {$name} must decode to a JSON object.");

        $body = $fixture['body'] ?? null;
        $this->assertIsArray($body, "Fixture {$name} must carry a body object.");

        return $body;
    }

    /**
     * Fake every request, recording it and answering only for the exact
     * URLs the test maps. Any unexpected URL fails the test inside the
     * callback and no request ever leaves the process.
     *
     * @param  array<string, array>  $bodies  Exact request URL => response body array
     */
    private function fakeBodies(array $bodies): void
    {
        $this->recorded = [];

        Http::fake(function (Request $request) use ($bodies) {
            $this->recorded[] = $request;

            $url = (string) $request->url();
            $this->assertArrayHasKey($url, $bodies, "Unexpected request URL: {$url}");
            $this->assertStringNotContainsString(self::TOKEN, $url, 'Request URL must never contain the token.');

            return Http::response($bodies[$url]);
        });
    }

    /**
     * Fake a fixed sequence of responses for one exact URL, recording every
     * request. A request beyond the sequence fails the test, which enforces
     * exact request counts. No request ever leaves the process.
     *
     * @param  array<int, array{0: mixed, 1: int, 2?: array<string, string>}>  $sequence  Ordered [body, status, headers?] responses
     */
    private function fakeSequence(string $url, array $sequence): void
    {
        $this->recorded = [];
        $calls = 0;

        Http::fake(function (Request $request) use ($url, $sequence, &$calls) {
            $this->recorded[] = $request;

            $this->assertSame($url, (string) $request->url(), "Unexpected request URL: {$request->url()}.");
            $this->assertStringNotContainsString(self::TOKEN, (string) $request->url(), 'Request URL must never contain the token.');

            if (!array_key_exists($calls, $sequence)) {
                $this->fail('Expected at most ' . count($sequence) . ' request(s) to ' . $url . ', but the ' . ($calls + 1) . '-th was made.');
            }

            $entry = $sequence[$calls];
            $calls++;

            return Http::response($entry[0], $entry[1], $entry[2] ?? []);
        });
    }

    /**
     * Run the client call and return the thrown CloudMiniApiException,
     * failing the test when no CloudMiniApiException is thrown.
     */
    private function clientException(callable $call): CloudMiniApiException
    {
        try {
            $call();
        } catch (CloudMiniApiException $exception) {
            return $exception;
        }

        $this->fail('Expected CloudMiniApiException was not thrown.');
    }

    /**
     * @return list<Request>
     */
    private function requests(): array
    {
        return $this->recorded;
    }

    private function assertAcceptJson(Request $request): void
    {
        $this->assertSame(
            'application/json',
            $request->header('Accept')[0] ?? null,
            'The request must accept application/json.'
        );
    }

    private function assertNoAuthorization(Request $request): void
    {
        $this->assertSame(
            [],
            $request->header('Authorization'),
            'The request must not carry an Authorization header.'
        );
    }

    private function assertExactAuthorization(Request $request): void
    {
        $this->assertSame(
            'Token ' . self::TOKEN,
            $request->header('Authorization')[0] ?? null,
            'The request must carry the exact Token FAKE-TEST-TOKEN authorization header.'
        );
    }

    private function assertExactFormPayload(Request $request, array $payload): void
    {
        $this->assertStringStartsWith(
            'application/x-www-form-urlencoded',
            (string) ($request->header('Content-Type')[0] ?? null),
            'The POST must use the x-www-form-urlencoded content type.'
        );

        $this->assertSame(
            http_build_query($payload),
            (string) $request->body(),
            'The wire body must be exactly the form-encoded payload.'
        );
    }

    private function assertTokenFreeUrls(): void
    {
        foreach ($this->recorded as $index => $request) {
            $this->assertStringNotContainsString(
                self::TOKEN,
                (string) $request->url(),
                "Recorded request {$index} URL must not contain the token."
            );
        }
    }
}
