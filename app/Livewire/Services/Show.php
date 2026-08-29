<?php

namespace App\Livewire\Services;

use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\Service;
use Exception;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class Show extends Component
{
    public Service $service;

    #[Locked]
    public $buttons = [];

    #[Locked]
    public $views = [];

    #[Locked]
    public $fields = [];

    #[Url('tab', except: false), Locked]
    public $currentView;

    #[Url('cancel', except: false)]
    public bool $showCancel = false;

    public bool $showBillingAgreement = false;

    #[Url('label', except: false)]
    public bool $editLabel = false;

    public ?string $label = null;

    public $selectedMethod;

    public function mount()
    {
        // Only fetch the actions if the service is active
        if ($this->service->status == Service::STATUS_ACTIVE) {
            $actions = [];
            try {
                $actions = ExtensionHelper::getActions($this->service);
            } catch (Exception $e) {
            }
            // separate the actions into buttons and views
            foreach ($actions as $action) {
                if ($action['type'] == 'button') {
                    $this->buttons[] = $action;
                } elseif ($action['type'] == 'view') {
                    $this->views[] = $action;
                } elseif ($action['type'] == 'text') {
                    $this->fields[] = $action;
                }
            }
            $this->currentView = $this->currentView ?? ($this->views[0]['name'] ?? null);
        }
        $this->label = $this->service->label;
    }

    public function updatedShowBillingAgreement()
    {
        $this->selectedMethod = Auth::user()->billingAgreements()->where('id', $this->service->billing_agreement_id)?->first()?->ulid;
    }

    public function updateBillingAgreement()
    {
        $agreement = Auth::user()->billingAgreements()->where('ulid', $this->selectedMethod)->first();
        $this->service->billing_agreement_id = $agreement->id;
        $this->service->save();

        $this->showBillingAgreement = false;
    }

    public function clearBillingAgreement()
    {
        $this->service->billing_agreement_id = null;
        $this->service->save();
        $this->selectedMethod = null;
    }

    public function updateLabel()
    {
        $this->validate([
            'label' => 'nullable|string|max:255',
        ]);

        $this->service->label = $this->label;
        $this->service->save();

        $this->editLabel = false;
        $this->notify('Service label updated successfully', 'success');
    }

    public function changeView($view)
    {
        if (!$view) {
            return;
        }
        if ($this->currentView === $view || !in_array($view, array_column($this->views, 'name'))) {
            return $this->skipRender();
        }
        $this->currentView = $view;
    }

    public function updatedShowCancel($value)
    {
        if (!$this->service->cancellable) {
            $this->notify('This service cannot be cancelled', 'error');
            $this->showCancel = false;

            return;
        }
    }

    public function goto($function)
    {
        // Check if function is allowed
        if (!in_array($function, array_column($this->buttons, 'function'))) {
            $this->notify('This action is not allowed', 'error');

            return;
        }
        $result = ExtensionHelper::callService($this->service, $function);
        // If its a response, return it
        if (!is_string($result)) {
            return $result;
        }
        $this->redirect($result);
    }

    public function refreshProxyDetails()
    {
        $this->service->unsetRelation('properties');
        $this->service->load('properties');

        $this->notify('Proxy details refreshed', 'success');
    }

    public function exportProxyCredentials()
    {
        $details = $this->proxyIpv4DcDetails();

        if (!$details || !$details['has_credentials']) {
            $this->notify('Proxy credentials are not available yet', 'error');

            return null;
        }

        $lines = array_filter([
            'Proxy IPv4 DC credentials',
            'Service: ' . $this->service->label,
            'Status: ' . $details['status_label'],
            'Location: ' . ($details['location'] ?: 'Not set'),
            'Expires: ' . ($details['expires_at'] ?: 'Not set'),
        ]);
        foreach ($details['proxies'] as $index => $proxy) {
            array_push($lines,
                '',
                'Proxy #' . ($index + 1),
                'ID: ' . ($proxy['id'] ?: 'Not set'),
                'Status: ' . $proxy['status_label'],
                'Proxy IP: ' . ($proxy['proxy_ip'] ?: 'Not set'),
                'HTTP: ' . ($proxy['http_endpoint'] ?: 'Unavailable'),
                'SOCKS5: ' . ($proxy['socks_endpoint'] ?: 'Unavailable'),
                'Username: ' . ($proxy['username'] ?: 'Not set'),
                'Password: ' . ($proxy['password'] ?: 'Not set'),
                'Connection URI: ' . ($proxy['connection_uri'] ?: 'Unavailable')
            );
        }

        return response()->streamDownload(function () use ($lines) {
            echo implode(PHP_EOL, $lines) . PHP_EOL;
        }, 'proxy-ipv4-dc-service-' . $this->service->id . '.txt', [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    private function proxyIpv4DcDetails(): ?array
    {
        $this->service->loadMissing(['product.server', 'plan', 'properties']);

        $properties = $this->service->properties->pluck('value', 'key');
        $isProxyService = $this->service->product?->server?->extension === 'HAVProxyIPv4DC'
            || $properties->has('hav_proxy_ipv4_dc_proxy_id')
            || $properties->has('hav_proxy_ipv4_dc_connection_uri');

        if (!$isProxyService) {
            return null;
        }

        $host = (string) ($properties->get('hav_proxy_ipv4_dc_host') ?: $properties->get('hav_proxy_ipv4_dc_outbound_ip', ''));
        $proxyIp = (string) ($properties->get('hav_proxy_ipv4_dc_outbound_ip') ?: $host);
        $username = (string) $properties->get('hav_proxy_ipv4_dc_username', '');
        $password = (string) $properties->get('hav_proxy_ipv4_dc_password', '');
        $httpPort = (int) $properties->get('hav_proxy_ipv4_dc_port_http', 0);
        $socksPort = (int) $properties->get('hav_proxy_ipv4_dc_port_socks', 0);

        $httpEndpoint = $this->proxyEndpoint('http', $host, $httpPort, $username, $password);
        $socksEndpoint = $this->proxyEndpoint('socks5', $host, $socksPort, $username, $password);
        $connectionUri = (string) ($properties->get('hav_proxy_ipv4_dc_connection_uri') ?: $socksEndpoint ?: $httpEndpoint);
        $providerStatus = (string) $properties->get('hav_proxy_ipv4_dc_status', '');
        $baseProxy = [
            'id' => (string) $properties->get('hav_proxy_ipv4_dc_proxy_id', ''),
            'status' => $providerStatus ?: $this->service->status,
            'status_label' => ucfirst(str_replace('_', ' ', $providerStatus ?: $this->service->status)),
            'proxy_ip' => $proxyIp,
            'host' => $host,
            'http_port' => $httpPort,
            'socks_port' => $socksPort,
            'http_endpoint' => $httpEndpoint,
            'socks_endpoint' => $socksEndpoint,
            'username' => $username,
            'password' => $password,
            'connection_uri' => $connectionUri,
            'location' => (string) $properties->get('display_name', ''),
            'external_location_code' => (string) $properties->get('external_location_code', ''),
            'plan' => $this->service->product?->name ?? '',
            'price' => (string) $this->service->formattedPrice,
            'expires_at' => $this->service->expires_at?->format('M d, Y'),
            'note' => $this->service->label,
        ];
        $proxies = $this->proxyRows($properties->get('hav_proxy_ipv4_dc_proxies'), $baseProxy);

        return [
            'has_credentials' => count($proxies) > 0,
            'proxy_count' => count($proxies),
            'proxies' => $proxies,
            'proxy_id' => $baseProxy['id'],
            'status' => $baseProxy['status'],
            'status_label' => $baseProxy['status_label'],
            'proxy_ip' => $baseProxy['proxy_ip'],
            'host' => $baseProxy['host'],
            'http_port' => $baseProxy['http_port'],
            'socks_port' => $baseProxy['socks_port'],
            'http_endpoint' => $baseProxy['http_endpoint'],
            'socks_endpoint' => $baseProxy['socks_endpoint'],
            'username' => $baseProxy['username'],
            'password' => $baseProxy['password'],
            'connection_uri' => $baseProxy['connection_uri'],
            'location' => (string) $properties->get('display_name', ''),
            'external_location_code' => (string) $properties->get('external_location_code', ''),
            'plan' => $this->service->product?->name ?? '',
            'price' => (string) $this->service->formattedPrice,
            'expires_at' => $this->service->expires_at?->format('M d, Y'),
            'service_status' => $this->service->status,
            'label' => $this->service->label,
        ];
    }

    private function proxyEndpoint(string $scheme, string $host, int $port, string $username, string $password): ?string
    {
        if ($host === '' || $port <= 0) {
            return null;
        }

        $auth = '';
        if ($username !== '' || $password !== '') {
            $auth = rawurlencode($username) . ':' . rawurlencode($password) . '@';
        }

        return $scheme . '://' . $auth . $host . ':' . $port;
    }

    private function proxyRows(?string $proxyJson, array $fallbackProxy): array
    {
        $decoded = json_decode($proxyJson ?: '', true);
        $items = is_array($decoded) && array_is_list($decoded) ? $decoded : [];

        if ($items === [] && ($fallbackProxy['id'] || $fallbackProxy['proxy_ip'] || $fallbackProxy['connection_uri'])) {
            $items = [$fallbackProxy];
        }

        return collect($items)
            ->map(fn (array $proxy, int $index) => $this->normalizeProxyRow($proxy, $fallbackProxy, $index))
            ->values()
            ->all();
    }

    private function normalizeProxyRow(array $proxy, array $fallbackProxy, int $index): array
    {
        $host = (string) data_get($proxy, 'host', data_get($proxy, 'outbound_ip', $fallbackProxy['host']));
        $proxyIp = (string) data_get($proxy, 'proxy_ip', data_get($proxy, 'outbound_ip', $host ?: $fallbackProxy['proxy_ip']));
        $username = (string) data_get($proxy, 'username', $fallbackProxy['username']);
        $password = (string) data_get($proxy, 'password', $fallbackProxy['password']);
        $httpPort = (int) data_get($proxy, 'http_port', data_get($proxy, 'port_http', $fallbackProxy['http_port']));
        $socksPort = (int) data_get($proxy, 'socks_port', data_get($proxy, 'port_socks', $fallbackProxy['socks_port']));
        $httpEndpoint = data_get($proxy, 'http_endpoint') ?: $this->proxyEndpoint('http', $host, $httpPort, $username, $password);
        $socksEndpoint = data_get($proxy, 'socks_endpoint') ?: $this->proxyEndpoint('socks5', $host, $socksPort, $username, $password);
        $status = (string) data_get($proxy, 'status', $fallbackProxy['status']);

        return [
            'row_number' => $index + 1,
            'id' => (string) data_get($proxy, 'id', data_get($proxy, 'proxy_id', $fallbackProxy['id'])),
            'status' => $status,
            'status_label' => ucfirst(str_replace('_', ' ', $status)),
            'proxy_ip' => $proxyIp,
            'host' => $host,
            'http_port' => $httpPort,
            'socks_port' => $socksPort,
            'http_endpoint' => $httpEndpoint,
            'socks_endpoint' => $socksEndpoint,
            'username' => $username,
            'password' => $password,
            'connection_uri' => (string) (data_get($proxy, 'connection_uri') ?: $socksEndpoint ?: $httpEndpoint),
            'location' => (string) data_get($proxy, 'location', $fallbackProxy['location']),
            'external_location_code' => (string) data_get($proxy, 'external_location_code', $fallbackProxy['external_location_code']),
            'plan' => (string) data_get($proxy, 'plan', $fallbackProxy['plan']),
            'price' => (string) data_get($proxy, 'price', $fallbackProxy['price']),
            'expires_at' => (string) data_get($proxy, 'expires_at', $fallbackProxy['expires_at']),
            'note' => (string) data_get($proxy, 'note', $fallbackProxy['note']),
        ];
    }

    public function render()
    {
        $view = null;
        $previousView = $this->currentView;

        if ($this->currentView) {
            try {
                // Search array for the current view
                $currentViewObj = $this->views[array_search($this->currentView, array_column($this->views, 'name'))] ?? null;
                if (!$currentViewObj) {
                    throw new Exception('View not found');
                }
                $view = ExtensionHelper::getView($this->service, $currentViewObj);
            } catch (Exception $e) {
                if ($previousView !== $this->views[0]['name'] ?? null) {
                    $this->notify('Got an error while trying to load the view', 'error');
                }
                $this->currentView = $this->views[0]['name'] ?? null;
            }
        }

        return view('services.show', [
            'extensionView' => $view,
            'proxyIpv4DcDetails' => $this->proxyIpv4DcDetails(),
            'relatedInvoices' => $this->service
                ->invoices()
                ->with(['currency', 'items', 'transactions.gateway'])
                ->orderByDesc('invoices.created_at')
                ->get(),
        ])->layoutData([
            'title' => 'Services',
            'sidebar' => true,
        ]);
    }
}
