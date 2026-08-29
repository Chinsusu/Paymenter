@php
    $proxyCount = $proxy['proxy_count'] ?? count($proxy['proxies']);
    $primaryProxy = $proxy['proxies'][0] ?? null;
    $statusClass = match ($proxy['service_status']) {
        'active' => 'border-green-500/30 bg-green-500/10 text-green-500',
        'suspended' => 'border-orange-500/30 bg-orange-500/10 text-orange-500',
        'cancelled' => 'border-red-500/30 bg-red-500/10 text-red-500',
        default => 'border-yellow-500/30 bg-yellow-500/10 text-yellow-500',
    };
    $copyAll = $primaryProxy ? implode(PHP_EOL, [
        'Proxy IPv4 DC credentials',
        'Proxy IP: ' . ($primaryProxy['proxy_ip'] ?: 'Not set'),
        'HTTP: ' . ($primaryProxy['http_endpoint'] ?: 'Unavailable'),
        'SOCKS5: ' . ($primaryProxy['socks_endpoint'] ?: 'Unavailable'),
        'Username: ' . ($primaryProxy['username'] ?: 'Not set'),
        'Password: ' . ($primaryProxy['password'] ?: 'Not set'),
        'Location: ' . ($primaryProxy['location'] ?: 'Not set'),
        'Plan: ' . ($primaryProxy['plan'] ?: 'Not set'),
        'Expires: ' . ($primaryProxy['expires_at'] ?: 'Not set'),
        'Status: ' . ($primaryProxy['status_label'] ?: 'Unknown'),
    ]) : '';
@endphp

<section
    class="bg-background-secondary border border-neutral p-4 md:p-6 rounded-lg mt-2"
    x-data="{
        showPassword: false,
        copiedKey: null,
        async copy(value, key) {
            if (!value) return;

            try {
                await navigator.clipboard.writeText(value);
                this.copiedKey = key;
                Alpine.store('notifications').addNotification([{ message: 'Copied to clipboard', type: 'success', timeout: 1400 }]);
                setTimeout(() => this.copiedKey = null, 1400);
            } catch (error) {
                Alpine.store('notifications').addNotification([{ message: 'Clipboard access failed', type: 'error' }]);
            }
        },
    }"
>
    <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-xl font-semibold">Proxy IPv4 DC</h2>
                <span class="inline-flex items-center rounded-md border border-neutral bg-background px-2.5 py-1 text-xs font-semibold">
                    {{ $proxyCount }} {{ Str::plural('proxy', $proxyCount) }}
                </span>
                <span class="inline-flex items-center rounded-md border px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">
                    {{ $proxy['status_label'] }}
                </span>
            </div>
            <p class="mt-1 text-sm text-base/60 break-words">
                {{ $proxy['location'] ?: 'Location is not set' }}
                @if ($proxy['external_location_code'])
                    <span class="text-base/40">({{ $proxy['external_location_code'] }})</span>
                @endif
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="button"
                class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral bg-background px-3 py-2 text-sm font-semibold hover:bg-background-secondary"
                wire:click="refreshProxyDetails"
                wire:loading.attr="disabled"
                wire:target="refreshProxyDetails"
                aria-label="Refresh proxy details">
                <x-ri-refresh-line class="size-4" />
                <span>Refresh</span>
            </button>

            @if ($primaryProxy)
            <button type="button"
                class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral bg-background px-3 py-2 text-sm font-semibold hover:bg-background-secondary"
                @click="copy(@js($copyAll), 'all')"
                aria-label="Copy all proxy credentials">
                <x-ri-file-copy-line class="size-4" />
                <span x-text="copiedKey === 'all' ? 'Copied' : 'Copy all'"></span>
            </button>

            <button type="button"
                class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral bg-background px-3 py-2 text-sm font-semibold hover:bg-background-secondary"
                wire:click="exportProxyCredentials"
                aria-label="Export proxy credentials">
                <x-ri-download-2-line class="size-4" />
                <span>Export</span>
            </button>
            @endif
        </div>
    </div>

    @if (!$primaryProxy)
        <div class="mt-5 rounded-md border border-neutral bg-background p-5">
            <h3 class="font-semibold">Proxy credentials are being prepared</h3>
            <p class="mt-1 text-sm text-base/60">Refresh this service after provisioning finishes.</p>
        </div>
    @else
        <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-md border border-neutral bg-background p-4">
                <p class="text-xs font-semibold uppercase text-base/50">Proxy IP</p>
                <p class="mt-2 break-all font-mono text-sm font-semibold">{{ $primaryProxy['proxy_ip'] ?: 'Not set' }}</p>
            </div>
            <div class="rounded-md border border-neutral bg-background p-4">
                <p class="text-xs font-semibold uppercase text-base/50">Location</p>
                <p class="mt-2 break-words text-sm font-semibold">{{ $primaryProxy['location'] ?: 'Not set' }}</p>
            </div>
            <div class="rounded-md border border-neutral bg-background p-4">
                <p class="text-xs font-semibold uppercase text-base/50">Plan</p>
                <p class="mt-2 break-words text-sm font-semibold">{{ $primaryProxy['plan'] ?: 'Not set' }}</p>
                <p class="text-xs text-base/50">{{ $primaryProxy['price'] ?: $proxy['price'] }}</p>
            </div>
            <div class="rounded-md border border-neutral bg-background p-4">
                <p class="text-xs font-semibold uppercase text-base/50">Expires</p>
                <p class="mt-2 text-sm font-semibold">{{ $primaryProxy['expires_at'] ?: 'Not set' }}</p>
            </div>
        </div>

        <div class="mt-5 grid gap-4 xl:grid-cols-2">
            <div class="rounded-md border border-neutral bg-background p-4">
                <h3 class="font-semibold">Connection</h3>
                <dl class="mt-4 grid gap-4">
                    <div class="grid gap-2 md:grid-cols-[140px_minmax(0,1fr)_auto] md:items-center">
                        <dt class="text-sm font-semibold text-base/60">HTTP endpoint</dt>
                        <dd class="min-w-0 break-all font-mono text-sm">{{ $primaryProxy['http_endpoint'] ?: 'Unavailable' }}</dd>
                        @if ($primaryProxy['http_endpoint'])
                        <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral px-3 py-2 text-sm font-semibold hover:bg-background-secondary" @click="copy(@js($primaryProxy['http_endpoint']), 'http')">
                            <x-ri-file-copy-line class="size-4" />
                            <span x-text="copiedKey === 'http' ? 'Copied' : 'Copy'"></span>
                        </button>
                        @endif
                    </div>
                    <div class="grid gap-2 md:grid-cols-[140px_minmax(0,1fr)_auto] md:items-center">
                        <dt class="text-sm font-semibold text-base/60">SOCKS5 endpoint</dt>
                        <dd class="min-w-0 break-all font-mono text-sm">{{ $primaryProxy['socks_endpoint'] ?: 'Unavailable' }}</dd>
                        @if ($primaryProxy['socks_endpoint'])
                        <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral px-3 py-2 text-sm font-semibold hover:bg-background-secondary" @click="copy(@js($primaryProxy['socks_endpoint']), 'socks')">
                            <x-ri-file-copy-line class="size-4" />
                            <span x-text="copiedKey === 'socks' ? 'Copied' : 'Copy'"></span>
                        </button>
                        @endif
                    </div>
                    <div class="grid gap-2 md:grid-cols-[140px_minmax(0,1fr)_auto] md:items-center">
                        <dt class="text-sm font-semibold text-base/60">Proxy ID</dt>
                        <dd class="min-w-0 break-all font-mono text-sm">{{ $primaryProxy['id'] ?: 'Not set' }}</dd>
                        @if ($primaryProxy['id'])
                        <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral px-3 py-2 text-sm font-semibold hover:bg-background-secondary" @click="copy(@js($primaryProxy['id']), 'id')">
                            <x-ri-file-copy-line class="size-4" />
                            <span x-text="copiedKey === 'id' ? 'Copied' : 'Copy'"></span>
                        </button>
                        @endif
                    </div>
                </dl>
            </div>

            <div class="rounded-md border border-neutral bg-background p-4">
                <h3 class="font-semibold">Authentication</h3>
                <dl class="mt-4 grid gap-4">
                    <div class="grid gap-2 md:grid-cols-[120px_minmax(0,1fr)_auto] md:items-center">
                        <dt class="text-sm font-semibold text-base/60">Username</dt>
                        <dd class="min-w-0 break-all font-mono text-sm">{{ $primaryProxy['username'] ?: 'Not set' }}</dd>
                        @if ($primaryProxy['username'])
                        <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral px-3 py-2 text-sm font-semibold hover:bg-background-secondary" @click="copy(@js($primaryProxy['username']), 'username')">
                            <x-ri-file-copy-line class="size-4" />
                            <span x-text="copiedKey === 'username' ? 'Copied' : 'Copy'"></span>
                        </button>
                        @endif
                    </div>
                    <div class="grid gap-2 md:grid-cols-[120px_minmax(0,1fr)_auto] md:items-center">
                        <dt class="text-sm font-semibold text-base/60">Password</dt>
                        <dd class="min-w-0 break-all font-mono text-sm">
                            <span x-text="showPassword ? @js($primaryProxy['password'] ?: 'Not set') : '************'"></span>
                        </dd>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="inline-flex min-h-11 items-center rounded-md border border-neutral px-3 py-2 text-sm font-semibold hover:bg-background-secondary" @click="showPassword = !showPassword" :aria-pressed="showPassword.toString()">
                                <span x-text="showPassword ? 'Hide' : 'Show'"></span>
                            </button>
                            @if ($primaryProxy['password'])
                            <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral px-3 py-2 text-sm font-semibold hover:bg-background-secondary" @click="copy(@js($primaryProxy['password']), 'password')">
                                <x-ri-file-copy-line class="size-4" />
                                <span x-text="copiedKey === 'password' ? 'Copied' : 'Copy'"></span>
                            </button>
                            @endif
                        </div>
                    </div>
                    <div class="grid gap-2 md:grid-cols-[120px_minmax(0,1fr)_auto] md:items-center">
                        <dt class="text-sm font-semibold text-base/60">Status</dt>
                        <dd>
                            <span class="inline-flex rounded-md border px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">
                                {{ $primaryProxy['status_label'] ?: $proxy['status_label'] }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    @endif
</section>
