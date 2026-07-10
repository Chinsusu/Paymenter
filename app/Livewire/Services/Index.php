<?php

namespace App\Livewire\Services;

use App\Livewire\Component;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public $status = null;

    public function render()
    {
        $query = Auth::user()
            ->services()
            ->with(['plan', 'product.server', 'properties'])
            ->orderBy('created_at', 'desc');

        if ($this->status) {
            $query->where('status', $this->status);
        }

        $services = $query->paginate(10);
        $serviceRows = $services->getCollection()
            ->map(fn (Service $service) => $this->serviceRow($service))
            ->values();

        return view('services.index', [
            'services' => $services,
            'serviceRows' => $serviceRows,
        ])->layoutData([
            'title' => 'Services',
            'sidebar' => true,
        ]);
    }

    private function serviceRow(Service $service): array
    {
        $properties = $service->properties->pluck('value', 'key');
        $baseProxy = [
            'id' => (string) $properties->get('hav_proxy_ipv4_dc_proxy_id', ''),
            'status' => (string) ($properties->get('hav_proxy_ipv4_dc_status') ?: $service->status),
            'proxy_ip' => (string) ($properties->get('hav_proxy_ipv4_dc_outbound_ip') ?: $properties->get('hav_proxy_ipv4_dc_host', '')),
            'host' => (string) $properties->get('hav_proxy_ipv4_dc_host', ''),
            'http_port' => (int) $properties->get('hav_proxy_ipv4_dc_port_http', 0),
            'socks_port' => (int) $properties->get('hav_proxy_ipv4_dc_port_socks', 0),
            'username' => (string) $properties->get('hav_proxy_ipv4_dc_username', ''),
            'password' => (string) $properties->get('hav_proxy_ipv4_dc_password', ''),
            'connection_uri' => (string) $properties->get('hav_proxy_ipv4_dc_connection_uri', ''),
            'location' => (string) $properties->get('display_name', ''),
        ];
        $decoded = json_decode($properties->get('hav_proxy_ipv4_dc_proxies', ''), true);
        $items = is_array($decoded) && array_is_list($decoded) ? $decoded : [];

        if ($items === [] && ($baseProxy['id'] || $baseProxy['proxy_ip'] || $baseProxy['connection_uri'])) {
            $items = [$baseProxy];
        }

        $proxyRows = collect($items)
            ->map(fn (array $proxy, int $index) => $this->normalizeProxyRow($service, $proxy, $baseProxy, $index))
            ->values();

        return $this->normalizeServiceRow($service, $proxyRows->first(), $proxyRows->count());
    }

    private function normalizeServiceRow(Service $service, ?array $proxy, int $proxyCount): array
    {
        $status = $proxy['status'] ?? $service->status;

        return [
            'key' => (string) $service->id,
            'service_id' => $service->id,
            'service_label' => $service->label,
            'service_url' => route('services.show', $service),
            'proxy_count' => $proxyCount,
            'has_proxy' => $proxy !== null,
            'id' => $proxy['id'] ?? '',
            'status' => $status,
            'status_label' => ucfirst(str_replace('_', ' ', $status)),
            'proxy_ip' => $proxy['proxy_ip'] ?? '',
            'http_port' => $proxy['http_port'] ?? 0,
            'socks_port' => $proxy['socks_port'] ?? 0,
            'username' => $proxy['username'] ?? '',
            'password' => $proxy['password'] ?? '',
            'http_endpoint' => $proxy['http_endpoint'] ?? null,
            'socks_endpoint' => $proxy['socks_endpoint'] ?? null,
            'connection_uri' => $proxy['connection_uri'] ?? '',
            'location' => $proxy['location'] ?? '',
            'plan' => $service->product?->name ?? '',
            'expires_at' => $service->expires_at?->format('M d, Y') ?? '',
        ];
    }

    private function normalizeProxyRow(Service $service, array $proxy, array $fallback, int $index): array
    {
        $host = (string) data_get($proxy, 'host', data_get($proxy, 'outbound_ip', $fallback['host']));
        $proxyIp = (string) data_get($proxy, 'proxy_ip', data_get($proxy, 'outbound_ip', $host ?: $fallback['proxy_ip']));
        $username = (string) data_get($proxy, 'username', $fallback['username']);
        $password = (string) data_get($proxy, 'password', $fallback['password']);
        $httpPort = (int) data_get($proxy, 'http_port', data_get($proxy, 'port_http', $fallback['http_port']));
        $socksPort = (int) data_get($proxy, 'socks_port', data_get($proxy, 'port_socks', $fallback['socks_port']));
        $status = (string) data_get($proxy, 'status', $fallback['status']);

        return [
            'key' => $service->id . '-' . ($index + 1),
            'service_id' => $service->id,
            'service_label' => $service->label,
            'service_url' => route('services.show', $service),
            'id' => (string) data_get($proxy, 'id', data_get($proxy, 'proxy_id', $fallback['id'])),
            'status' => $status,
            'status_label' => ucfirst(str_replace('_', ' ', $status)),
            'proxy_ip' => $proxyIp,
            'http_port' => $httpPort,
            'socks_port' => $socksPort,
            'username' => $username,
            'password' => $password,
            'http_endpoint' => $this->proxyEndpoint('http', $host, $httpPort, $username, $password),
            'socks_endpoint' => $this->proxyEndpoint('socks5', $host, $socksPort, $username, $password),
            'connection_uri' => (string) data_get($proxy, 'connection_uri', $fallback['connection_uri']),
            'location' => (string) data_get($proxy, 'location', $fallback['location']),
            'plan' => $service->product?->name ?? '',
            'expires_at' => (string) data_get($proxy, 'expires_at', $service->expires_at?->format('M d, Y') ?? ''),
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
}
