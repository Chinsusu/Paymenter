<div class="container mt-14">
    @if($invoice = $service->invoices()->where('status', 'pending')->first())
    <div class="w-full mb-4">
        <div class="bg-yellow-600/20 border-l-4 border-yellow-500 text-yellow-300 p-4 rounded-lg">
            <p class="font-medium">
                ⚠️ {{ __('services.outstanding_invoice') }}
                <a href="{{ route('invoices.show', $invoice)}}"
                    class="underline hover:text-yellow-100 underline-offset-2">{{ __('services.view_and_pay') }}</a>.
            </p>
        </div>
    </div>
    @endif
    @php
        $primaryProxy = $proxyIpv4DcDetails['proxies'][0] ?? null;
        $copyAll = $primaryProxy ? implode(PHP_EOL, [
            'Service: ' . $service->label,
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

    <div
        class="bg-background-secondary border border-neutral rounded-lg mt-2 overflow-hidden"
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
        <div class="flex flex-col gap-2 border-b border-neutral p-4 md:flex-row md:items-center md:justify-between">
            <div>
                <h1 class="text-2xl font-semibold">{{ __('services.services') }}</h1>
                <p class="mt-1 text-sm text-base/60">Service, proxy, invoices, and payments in one view.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if($primaryProxy)
                <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral bg-background px-3 py-2 text-sm font-semibold hover:bg-background-secondary" @click="copy(@js($copyAll), 'all')">
                    <x-ri-file-copy-line class="size-4" />
                    <span x-text="copiedKey === 'all' ? 'Copied' : 'Copy all'"></span>
                </button>
                <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral bg-background px-3 py-2 text-sm font-semibold hover:bg-background-secondary" wire:click="exportProxyCredentials">
                    <x-ri-download-2-line class="size-4" />
                    <span>Export</span>
                </button>
                @endif
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px]">
                <thead class="border-b border-neutral bg-background">
                    <tr class="text-left text-xs font-semibold uppercase text-base/50">
                        <th class="w-48 p-4">Group</th>
                        <th class="p-4">Information</th>
                        <th class="w-72 p-4 text-right">Action / Payment</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral">
                    <tr>
                        <td class="p-4 align-top font-semibold">{{ __('services.product_details') }}</td>
                        <td class="p-4 align-top">
                            <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                                <div>@include('services.partials.label')</div>
                                <div><span class="text-base/60">{{ __('services.price') }}:</span> {{ $service->formattedPrice }}</div>
                                @if($service->plan->type == 'recurring')
                                <div><span class="text-base/60">{{ __('services.billing_cycle') }}:</span> {{ __('services.every_period', [
                                    'period' => $service->plan->billing_period > 1 ? $service->plan->billing_period : '',
                                    'unit' => trans_choice(__('services.billing_cycles.' . $service->plan->billing_unit), $service->plan->billing_period)
                                ]) }}</div>
                                @endif
                                <div><span class="text-base/60">{{ __('services.renews_on') }}:</span> {{ $service->expires_at?->format('M d, Y') ?? 'Not set' }}</div>
                                <div>
                                    <span class="text-base/60">{{ __('services.status') }}:</span>
                                    <span class="font-semibold @if ($service->status == 'active') text-green-500 @elseif($service->status == 'cancelled') text-red-500 @else text-orange-500 @endif">
                                        {{ $service->cancellation && $service->status == 'active' ? __('services.statuses.cancellation_pending') : __('services.statuses.' . $service->status) }}
                                    </span>
                                </div>
                                @foreach ($fields as $field)
                                <div><span class="text-base/60">{{ $field['label'] }}:</span> {{ $field['text'] }}</div>
                                @endforeach
                            </div>
                            <div class="mt-3">@include('services.partials.billing-agreement')</div>
                        </td>
                        <td class="p-4 align-top text-right">
                            <div class="flex flex-wrap justify-end gap-2">
                                @if($service->upgradable)
                                <a href="{{ route('services.upgrade', $service->id) }}">
                                    <x-button.primary class="h-fit !w-fit">{{ __('services.upgrade') }}</x-button.primary>
                                </a>
                                @endif
                                @if($service->upgrade()->where('status', 'pending')->exists())
                                <x-button.primary class="h-fit !w-fit" @click="Alpine.store('notifications').addNotification([{message: '{{ __('services.upgrade_pending') }}', type: 'error'}])">
                                    {{ __('services.upgrade') }}
                                </x-button.primary>
                                @endif
                                @if($service->cancellable)
                                <x-button.danger class="h-fit !w-fit" wire:click="$set('showCancel', true)">
                                    <span wire:loading.remove wire:target="$set('showCancel', true)">{{ __('services.cancel') }}</span>
                                    <x-loading target="$set('showCancel', true)" />
                                </x-button.danger>
                                @endif
                                @foreach ($buttons as $button)
                                    @if (isset($button['function']))
                                    <x-button.primary class="h-fit !w-fit" wire:click="goto('{{ $button['function'] }}')">{{ $button['label'] }}</x-button.primary>
                                    @else
                                    <a href="{{ $button['url'] }}" @if(!empty($button['target'])) target="{{ $button['target'] }}" @endif @if(($button['target'] ?? null) === '_blank') rel="noopener noreferrer" @endif>
                                        <x-button.primary class="h-fit !w-fit">{{ $button['label'] }}</x-button.primary>
                                    </a>
                                    @endif
                                @endforeach
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td class="p-4 align-top font-semibold">Proxy IPv4 DC</td>
                        <td class="p-4 align-top">
                            @if($primaryProxy)
                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                <div><span class="text-base/60">IP:</span> <span class="font-mono">{{ $primaryProxy['proxy_ip'] ?: 'Not set' }}</span></div>
                                <div><span class="text-base/60">Location:</span> {{ $primaryProxy['location'] ?: 'Not set' }}</div>
                                <div><span class="text-base/60">Plan:</span> {{ $primaryProxy['plan'] ?: 'Not set' }}</div>
                                <div><span class="text-base/60">Expires:</span> {{ $primaryProxy['expires_at'] ?: 'Not set' }}</div>
                                <div><span class="text-base/60">Status:</span> <span class="font-semibold text-green-500">{{ $primaryProxy['status_label'] ?: $proxyIpv4DcDetails['status_label'] }}</span></div>
                                <div><span class="text-base/60">Proxy ID:</span> <span class="font-mono break-all">{{ $primaryProxy['id'] ?: 'Not set' }}</span></div>
                            </div>
                            @else
                            <span class="text-base/60">Proxy credentials are being prepared.</span>
                            @endif
                        </td>
                        <td class="p-4 align-top text-right">
                            <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral bg-background px-3 py-2 text-sm font-semibold hover:bg-background-secondary" wire:click="refreshProxyDetails">
                                <x-ri-refresh-line class="size-4" />
                                Refresh
                            </button>
                        </td>
                    </tr>

                    @if($primaryProxy)
                    <tr>
                        <td class="p-4 align-top font-semibold">Connection</td>
                        <td class="p-4 align-top">
                            <div class="grid gap-3">
                                <div class="grid gap-2 md:grid-cols-[120px_minmax(0,1fr)]">
                                    <span class="text-base/60">HTTP endpoint</span>
                                    <span class="break-all font-mono">{{ $primaryProxy['http_endpoint'] ?: 'Unavailable' }}</span>
                                </div>
                                <div class="grid gap-2 md:grid-cols-[120px_minmax(0,1fr)]">
                                    <span class="text-base/60">SOCKS5 endpoint</span>
                                    <span class="break-all font-mono">{{ $primaryProxy['socks_endpoint'] ?: 'Unavailable' }}</span>
                                </div>
                            </div>
                        </td>
                        <td class="p-4 align-top text-right">
                            <div class="flex flex-wrap justify-end gap-2">
                                @if($primaryProxy['http_endpoint'])
                                <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral px-3 py-2 text-sm font-semibold hover:bg-background-secondary" @click="copy(@js($primaryProxy['http_endpoint']), 'http')"><x-ri-file-copy-line class="size-4" />HTTP</button>
                                @endif
                                @if($primaryProxy['socks_endpoint'])
                                <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral px-3 py-2 text-sm font-semibold hover:bg-background-secondary" @click="copy(@js($primaryProxy['socks_endpoint']), 'socks')"><x-ri-file-copy-line class="size-4" />SOCKS5</button>
                                @endif
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td class="p-4 align-top font-semibold">Authentication</td>
                        <td class="p-4 align-top">
                            <div class="grid gap-3 md:grid-cols-2">
                                <div><span class="text-base/60">Username:</span> <span class="font-mono">{{ $primaryProxy['username'] ?: 'Not set' }}</span></div>
                                <div><span class="text-base/60">Password:</span> <span class="font-mono" x-text="showPassword ? @js($primaryProxy['password'] ?: 'Not set') : '************'"></span></div>
                            </div>
                        </td>
                        <td class="p-4 align-top text-right">
                            <div class="flex flex-wrap justify-end gap-2">
                                <button type="button" class="inline-flex min-h-11 items-center rounded-md border border-neutral px-3 py-2 text-sm font-semibold hover:bg-background-secondary" @click="showPassword = !showPassword" :aria-pressed="showPassword.toString()">
                                    <span x-text="showPassword ? 'Hide' : 'Show'"></span>
                                </button>
                                @if($primaryProxy['username'])
                                <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral px-3 py-2 text-sm font-semibold hover:bg-background-secondary" @click="copy(@js($primaryProxy['username']), 'username')"><x-ri-file-copy-line class="size-4" />User</button>
                                @endif
                                @if($primaryProxy['password'])
                                <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-md border border-neutral px-3 py-2 text-sm font-semibold hover:bg-background-secondary" @click="copy(@js($primaryProxy['password']), 'password')"><x-ri-file-copy-line class="size-4" />Pass</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endif

                    <tr>
                        <td class="p-4 align-top font-semibold">Invoices & payments</td>
                        <td class="p-4 align-top" colspan="2">
                            @if($relatedInvoices->isEmpty())
                            <span class="text-base/60">No invoices or payments are linked to this service yet.</span>
                            @else
                            <div class="grid gap-3">
                                @foreach($relatedInvoices as $invoice)
                                <div class="rounded-md border border-neutral bg-background p-3">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <a href="{{ route('invoices.show', $invoice) }}" wire:navigate class="font-semibold hover:underline">Invoice #{{ $invoice->number ?: $invoice->id }}</a>
                                            <span class="ml-2 rounded-md border border-neutral px-2 py-0.5 text-xs font-semibold">{{ ucfirst($invoice->status) }}</span>
                                        </div>
                                        <div class="text-sm">
                                            <span class="font-semibold">{{ $invoice->formattedTotal }}</span>
                                            <span class="text-base/50">· Remaining {{ $invoice->formattedRemaining }}</span>
                                        </div>
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-2 text-xs text-base/60">
                                        <span>Due {{ $invoice->due_at?->format('M d, Y') ?? 'Not set' }}</span>
                                        @forelse($invoice->transactions->sortByDesc('created_at') as $transaction)
                                        <span class="rounded border border-neutral px-2 py-1">
                                            {{ ucfirst($transaction->status->value) }} · {{ $transaction->formattedAmount }} · {{ $transaction->is_credit_transaction ? __('invoices.paid_with_credits') : $transaction->gateway?->name ?? 'N/A' }}
                                        </span>
                                        @empty
                                        <span>No payment transactions yet</span>
                                        @endforelse
                                    </div>
                                </div>
                                @endforeach
                            </div>
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    @if($showCancel)
    <x-modal open="true"
        title="{{ __('services.cancellation', ['service' => $service->product->name]) }}"
        width="max-w-3xl">
        <livewire:services.cancel :service="$service" />
        <x-slot name="closeTrigger">
            <div class="flex gap-4">
                <button wire:click="$set('showCancel', false)" @click="open = false" class="text-primary-100">
                    <x-ri-close-fill class="size-6" />
                </button>
            </div>
        </x-slot>
    </x-modal>
    @endif

    @if (count($views) > 0)
    <div class="bg-primary-800 rounded-lg mt-2">
        @if (count($views) > 1)
        <div class="flex w-fit mb-2 flex-row flex-wrap">
            @foreach ($views as $view)
            <button wire:click="changeView('{{ $view['name'] }}')"
                class="px-4 py-2 -mb-px focus:outline-none {{ $view['name'] == $currentView ? 'border-b-2 border-gray-400 font-semibold' : 'text-base border-b border-gray-500 ' }}">
                {{ $view['label'] }}
            </button>
            @endforeach
        </div>
        @endif

        <!-- show loading spinner -->
        <x-loading target="changeView" />
        <div wire:loading.remove wire:target="changeView">
            {!! $extensionView !!}
        </div>
    </div>
    @endif
</div>
