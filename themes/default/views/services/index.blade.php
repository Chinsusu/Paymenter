<div class="container mt-14 space-y-4">
    <x-navigation.breadcrumb />

    @if ($services->total() > 0)
    <section
        class="bg-background-secondary border border-neutral rounded-lg overflow-hidden"
        x-data="{
            rows: @js($serviceRows),
            selected: [],
            query: '',
            protocol: 'all',
            copiedKey: null,
            showPasswords: false,
            get filteredRows() {
                const query = this.query.trim().toLowerCase();

                return this.rows.filter((proxy) => {
                    const protocolMatch = this.protocol === 'all'
                        || (this.protocol === 'http' && Number(proxy.http_port) > 0)
                        || (this.protocol === 'socks5' && Number(proxy.socks_port) > 0);

                    if (!protocolMatch) return false;
                    if (!query) return true;

                    return [
                        proxy.service_label,
                        proxy.proxy_ip,
                        proxy.username,
                        proxy.location,
                        proxy.plan,
                        proxy.status_label,
                    ].filter(Boolean).some((value) => String(value).toLowerCase().includes(query));
                });
            },
            get filteredKeys() {
                return this.filteredRows.map((proxy) => proxy.key);
            },
            get allVisibleSelected() {
                return this.filteredKeys.length > 0 && this.filteredKeys.every((key) => this.selected.includes(key));
            },
            toggleVisible() {
                if (this.allVisibleSelected) {
                    this.selected = this.selected.filter((key) => !this.filteredKeys.includes(key));
                    return;
                }

                this.selected = [...new Set([...this.selected, ...this.filteredKeys])];
            },
            selectedRows() {
                return this.rows.filter((proxy) => this.selected.includes(proxy.key));
            },
            proxyText(proxy) {
                return [
                    `Service: ${proxy.service_label || 'Not set'}`,
                    `Proxy IP: ${proxy.proxy_ip || 'Not set'}`,
                    `HTTP: ${proxy.http_endpoint || 'Unavailable'}`,
                    `SOCKS5: ${proxy.socks_endpoint || 'Unavailable'}`,
                    `Username: ${proxy.username || 'Not set'}`,
                    `Password: ${proxy.password || 'Not set'}`,
                    `Location: ${proxy.location || 'Not set'}`,
                    `Plan: ${proxy.plan || 'Not set'}`,
                    `Expires: ${proxy.expires_at || 'Not set'}`,
                    `Status: ${proxy.status_label || 'Unknown'}`,
                ].join('\n');
            },
            selectedText() {
                return this.selectedRows().map((proxy, index) => `Service #${index + 1}\n${this.proxyText(proxy)}`).join('\n\n');
            },
            async copy(proxy, key) {
                try {
                    await navigator.clipboard.writeText(this.proxyText(proxy));
                    this.copiedKey = key;
                    Alpine.store('notifications').addNotification([{ message: 'Copied to clipboard', type: 'success', timeout: 1400 }]);
                    setTimeout(() => this.copiedKey = null, 1400);
                } catch (error) {
                    Alpine.store('notifications').addNotification([{ message: 'Clipboard access failed', type: 'error' }]);
                }
            },
            async copySelected() {
                const text = this.selectedText();
                if (!text) return;

                try {
                    await navigator.clipboard.writeText(text);
                    this.copiedKey = 'selected';
                    Alpine.store('notifications').addNotification([{ message: 'Copied selected services', type: 'success', timeout: 1400 }]);
                    setTimeout(() => this.copiedKey = null, 1400);
                } catch (error) {
                    Alpine.store('notifications').addNotification([{ message: 'Clipboard access failed', type: 'error' }]);
                }
            },
            exportSelected() {
                const text = this.selectedText();
                if (!text) return;

                const blob = new Blob([text + '\n'], { type: 'text/plain;charset=utf-8' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = 'hav-digital-services.txt';
                link.click();
                URL.revokeObjectURL(url);
            },
            exportVisible() {
                const text = this.filteredRows.map((proxy, index) => `Service #${index + 1}\n${this.proxyText(proxy)}`).join('\n\n');
                if (!text) return;

                const blob = new Blob([text + '\n'], { type: 'text/plain;charset=utf-8' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = 'hav-digital-visible-services.txt';
                link.click();
                URL.revokeObjectURL(url);
            },
            statusClass(status) {
                status = String(status || '').toLowerCase();
                if (status === 'running' || status === 'active') return 'border-green-500/30 bg-green-500/10 text-green-500';
                if (status === 'suspended' || status === 'limited') return 'border-orange-500/30 bg-orange-500/10 text-orange-500';
                if (status === 'stopped' || status === 'cancelled' || status === 'failed') return 'border-red-500/30 bg-red-500/10 text-red-500';

                return 'border-yellow-500/30 bg-yellow-500/10 text-yellow-500';
            },
        }"
    >
        <div class="flex flex-col gap-2 border-b border-neutral p-4 md:flex-row md:items-center md:justify-between">
            <div>
                <h1 class="text-2xl font-semibold">Services</h1>
                <p class="mt-1 text-sm text-base/60">All services. Proxy credentials appear when provisioning is complete.</p>
            </div>
            <div class="text-sm text-base/60">
                10 services per page
            </div>
        </div>

        <div class="grid gap-4 border-b border-neutral p-4 lg:grid-cols-[minmax(180px,240px)_minmax(240px,1fr)_auto] lg:items-end">
            <label class="flex flex-col gap-1 text-sm font-semibold">
                <span class="text-base/60">Protocol</span>
                <select
                    class="min-h-11 rounded-md border border-neutral bg-background px-3 py-2 text-sm outline-none focus:border-primary"
                    x-model="protocol"
                    aria-label="Filter services by protocol">
                    <option value="all">HTTP + SOCKS5</option>
                    <option value="http">HTTP</option>
                    <option value="socks5">SOCKS5</option>
                </select>
            </label>

            <label class="flex flex-col gap-1 text-sm font-semibold">
                <span class="text-base/60">Search</span>
                <input
                    type="search"
                    class="min-h-11 rounded-md border border-neutral bg-background px-3 py-2 text-sm outline-none focus:border-primary"
                    placeholder="IP, user, location..."
                    x-model.debounce.250ms="query"
                    aria-label="Search services">
            </label>

            <div class="flex flex-wrap gap-2">
                <button type="button"
                    class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral bg-background px-3 py-2 text-sm font-semibold hover:bg-background-secondary disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="selected.length === 0"
                    @click="copySelected"
                    aria-label="Copy selected services">
                    <x-ri-file-copy-line class="size-4" />
                    <span x-text="copiedKey === 'selected' ? 'Copied' : `Copy selected (${selected.length})`"></span>
                </button>
                <button type="button"
                    class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral bg-background px-3 py-2 text-sm font-semibold hover:bg-background-secondary disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="selected.length === 0"
                    @click="exportSelected"
                    aria-label="Export selected services">
                    <x-ri-download-2-line class="size-4" />
                    <span>Export selected</span>
                </button>
                <button type="button"
                    class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral bg-background px-3 py-2 text-sm font-semibold hover:bg-background-secondary"
                    wire:click="$refresh"
                    aria-label="Refresh service list">
                    <x-ri-refresh-line class="size-4" />
                    <span>Refresh</span>
                </button>
                <button type="button"
                    class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral bg-background px-3 py-2 text-sm font-semibold hover:bg-background-secondary"
                    @click="exportVisible"
                    aria-label="Export visible services">
                    <x-ri-download-cloud-2-line class="size-4" />
                    <span>Export file</span>
                </button>
            </div>
        </div>

        <div class="hidden lg:block">
            <div class="grid min-h-14 grid-cols-[48px_minmax(130px,1.1fr)_minmax(150px,1fr)_minmax(170px,1.2fr)_minmax(160px,1fr)_120px_120px_150px] items-center gap-4 border-b border-neutral px-4 text-xs font-semibold uppercase text-base/50">
                <div>
                    <input type="checkbox" class="size-4 rounded border-neutral" :checked="allVisibleSelected" @change="toggleVisible" aria-label="Select visible services">
                </div>
                <div>Service / Proxy IP</div>
                <div>Proxy Port</div>
                <div>User/Pass</div>
                <div>Region/Plan</div>
                <div>Expires</div>
                <div>Status</div>
                <div class="text-right">Actions</div>
            </div>

            @foreach ($serviceRows as $proxy)
            <div class="grid min-h-20 grid-cols-[48px_minmax(130px,1.1fr)_minmax(150px,1fr)_minmax(170px,1.2fr)_minmax(160px,1fr)_120px_120px_150px] items-center gap-4 border-b border-neutral px-4 py-3 last:border-b-0 hover:bg-background-secondary/70"
                x-show="filteredKeys.includes(@js($proxy['key']))">
                <div>
                    <input type="checkbox" class="size-4 rounded border-neutral" value="{{ $proxy['key'] }}" x-model="selected" aria-label="Select service {{ $proxy['service_label'] }}">
                </div>
                <div class="min-w-0">
                    <p class="break-words text-sm font-semibold">{{ $proxy['service_label'] }}</p>
                    <p class="mt-1 break-all font-mono text-xs text-base/70">{{ $proxy['proxy_ip'] ?: 'Proxy pending' }}</p>
                    @if ($proxy['id'])
                    <p class="mt-1 break-all text-xs text-base/50">{{ $proxy['id'] }}</p>
                    @endif
                </div>
                <div class="min-w-0 space-y-1 text-sm">
                    <p class="break-all font-mono">HTTP: {{ $proxy['http_port'] > 0 ? $proxy['http_port'] : 'Unavailable' }}</p>
                    <p class="break-all font-mono">SOCKS5: {{ $proxy['socks_port'] > 0 ? $proxy['socks_port'] : 'Unavailable' }}</p>
                </div>
                <div class="min-w-0 text-sm">
                    <p class="break-all font-mono">{{ $proxy['username'] ?: 'Not provisioned' }}</p>
                    <p class="break-all font-mono text-base/70" x-text="@js($proxy['has_proxy']) ? (showPasswords ? @js($proxy['password'] ?: 'Not set') : '************') : 'Not provisioned'"></p>
                </div>
                <div class="min-w-0 text-sm">
                    <p class="break-words font-semibold">{{ $proxy['location'] ?: 'Not selected' }}</p>
                    <p class="mt-1 break-words text-xs text-base/50">{{ $proxy['plan'] }}</p>
                    @if ($proxy['proxy_count'] > 1)
                    <p class="mt-1 text-xs text-base/50">{{ $proxy['proxy_count'] }} proxies in this service</p>
                    @endif
                </div>
                <div class="text-sm">{{ $proxy['expires_at'] ?: 'Not set' }}</div>
                <div>
                    <span class="inline-flex rounded-md border px-2.5 py-1 text-xs font-semibold" :class="statusClass(@js($proxy['status']))">
                        {{ $proxy['status_label'] }}
                    </span>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button"
                        class="inline-flex min-h-11 items-center justify-center rounded-md border border-neutral px-3 py-2 text-sm font-semibold hover:bg-background-secondary disabled:cursor-not-allowed disabled:opacity-50"
                        @click="showPasswords = !showPasswords"
                        @disabled(!$proxy['has_proxy'])
                        :aria-pressed="showPasswords.toString()"
                        aria-label="Toggle proxy passwords">
                        <span x-text="showPasswords ? 'Hide' : 'Show'"></span>
                    </button>
                    <button type="button"
                        class="inline-flex min-h-11 items-center justify-center rounded-md border border-neutral px-3 py-2 text-sm font-semibold hover:bg-background-secondary disabled:cursor-not-allowed disabled:opacity-50"
                        @click="copy(@js($proxy), @js($proxy['key']))"
                        @disabled(!$proxy['has_proxy'])
                        aria-label="Copy service {{ $proxy['service_label'] }}">
                        <x-ri-file-copy-line class="size-4" />
                        <span class="sr-only">Copy</span>
                    </button>
                    <a href="{{ $proxy['service_url'] }}" wire:navigate
                        class="inline-flex min-h-11 items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/80">
                        Details
                    </a>
                </div>
            </div>
            @endforeach
        </div>

        <div class="space-y-3 p-4 lg:hidden">
            @foreach ($serviceRows as $proxy)
            <div class="rounded-md border border-neutral bg-background p-4" x-show="filteredKeys.includes(@js($proxy['key']))">
                <div class="flex items-start gap-3">
                    <input type="checkbox" class="mt-1 size-4 rounded border-neutral" value="{{ $proxy['key'] }}" x-model="selected" aria-label="Select service {{ $proxy['service_label'] }}">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="break-words text-sm font-semibold">{{ $proxy['service_label'] }}</p>
                                <p class="mt-1 break-all font-mono text-xs text-base/70">{{ $proxy['proxy_ip'] ?: 'Proxy pending' }}</p>
                            </div>
                            <span class="inline-flex rounded-md border px-2.5 py-1 text-xs font-semibold" :class="statusClass(@js($proxy['status']))">
                                {{ $proxy['status_label'] }}
                            </span>
                        </div>
                        <dl class="mt-3 grid gap-3 text-sm">
                            <div>
                                <dt class="text-xs font-semibold uppercase text-base/50">Proxy Port</dt>
                                <dd class="mt-1 font-mono">
                                    HTTP: {{ $proxy['http_port'] > 0 ? $proxy['http_port'] : 'Unavailable' }}<br>
                                    SOCKS5: {{ $proxy['socks_port'] > 0 ? $proxy['socks_port'] : 'Unavailable' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase text-base/50">User/Pass</dt>
                                <dd class="mt-1 break-all font-mono">
                                    {{ $proxy['username'] ?: 'Not provisioned' }}<br>
                                    <span x-text="@js($proxy['has_proxy']) ? (showPasswords ? @js($proxy['password'] ?: 'Not set') : '************') : 'Not provisioned'"></span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase text-base/50">Region/Plan</dt>
                                <dd class="mt-1 break-words">
                                    {{ $proxy['location'] ?: 'Not selected' }}<br>
                                    <span class="text-base/60">{{ $proxy['plan'] }}</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase text-base/50">Expires</dt>
                                <dd class="mt-1">{{ $proxy['expires_at'] ?: 'Not set' }}</dd>
                            </div>
                        </dl>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button"
                                class="inline-flex min-h-11 items-center justify-center rounded-md border border-neutral px-3 py-2 text-sm font-semibold hover:bg-background-secondary disabled:cursor-not-allowed disabled:opacity-50"
                                @click="showPasswords = !showPasswords"
                                @disabled(!$proxy['has_proxy'])
                                :aria-pressed="showPasswords.toString()"
                                aria-label="Toggle proxy passwords">
                                <span x-text="showPasswords ? 'Hide password' : 'Show password'"></span>
                            </button>
                            <button type="button"
                                class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral px-3 py-2 text-sm font-semibold hover:bg-background-secondary disabled:cursor-not-allowed disabled:opacity-50"
                                @click="copy(@js($proxy), @js($proxy['key']))"
                                @disabled(!$proxy['has_proxy'])
                                aria-label="Copy service {{ $proxy['service_label'] }}">
                                <x-ri-file-copy-line class="size-4" />
                                <span x-text="copiedKey === @js($proxy['key']) ? 'Copied' : 'Copy'"></span>
                            </button>
                            <a href="{{ $proxy['service_url'] }}" wire:navigate
                                class="inline-flex min-h-11 items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/80">
                                Details
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </section>

    {{ $services->links() }}
    @else
    @forelse ($services as $service)
    <a href="{{ route('services.show', $service) }}" wire:navigate>
        <div class="bg-background-secondary hover:bg-background-secondary/80 border border-neutral p-4 rounded-lg mb-4">
            <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-3">
                    <div class="bg-secondary/10 p-2 rounded-lg">
                        <x-ri-instance-line class="size-5 text-secondary" />
                    </div>
                    <span class="font-medium">{{ $service->label }}</span>
                </div>
                <div class="size-5 rounded-md p-0.5
                    @if ($service->status == 'active') text-success bg-success/20
                    @elseif($service->status == 'suspended' || $service->status == 'cancelled') text-inactive bg-inactive/20
                    @else text-warning bg-warning/20
                    @endif">
                    @if ($service->status == 'active')
                        <x-ri-checkbox-circle-fill />
                    @elseif($service->status == 'suspended' || $service->status == 'cancelled')
                        <x-ri-forbid-fill />
                    @elseif($service->status == 'pending')
                        <x-ri-error-warning-fill />
                    @endif
                </div>
            </div>
            <div class="text-base text-sm flex gap-1">
                {{
                    in_array($service->plan->type, ['recurring']) ?  __('services.every_period', [
                    'period' => $service->plan->billing_period > 1 ? $service->plan->billing_period : '',
                    'unit' => trans_choice(__('services.billing_cycles.' . $service->plan->billing_unit),
                    $service->plan->billing_period)
                    ]) : '' }}
                    @if($service->expires_at && $service->expires_at > now())
                    -  {{ __('services.renews_in') }}
                    <x-tooltip :message="$service->expires_at->format('M d, Y')">
                        {{ $service->expires_at->longAbsoluteDiffForHumans() }}
                    </x-tooltip>
                    @endif
            </div>
        </div>
    </a>
    @empty
    <div class="bg-background-secondary border border-neutral p-4 rounded-lg">
        <p class="text-base text-sm">{{ __('services.no_services') }}</p>
    </div>
    @endforelse
    @endif
</div>
