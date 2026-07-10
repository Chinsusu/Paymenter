<section class="bg-background-secondary border border-neutral p-4 md:p-6 rounded-lg mt-2">
    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <div>
            <h2 class="text-xl font-semibold">Invoices & payments</h2>
            <p class="mt-1 text-sm text-base/60">Billing records linked to this service.</p>
        </div>
        <span class="inline-flex w-fit items-center rounded-md border border-neutral bg-background px-2.5 py-1 text-xs font-semibold">
            {{ $invoices->count() }} {{ Str::plural('invoice', $invoices->count()) }}
        </span>
    </div>

    @if ($invoices->isEmpty())
        <div class="mt-5 rounded-md border border-neutral bg-background p-5">
            <p class="text-sm text-base/60">No invoices or payments are linked to this service yet.</p>
        </div>
    @else
        <div class="mt-5 overflow-hidden rounded-md border border-neutral bg-background">
            <div class="hidden min-h-12 grid-cols-[minmax(120px,1fr)_110px_120px_120px_minmax(160px,1.2fr)_110px] items-center gap-4 border-b border-neutral px-4 text-xs font-semibold uppercase text-base/50 lg:grid">
                <div>Invoice</div>
                <div>Status</div>
                <div>Total</div>
                <div>Remaining</div>
                <div>Payments</div>
                <div class="text-right">Action</div>
            </div>

            @foreach ($invoices as $invoice)
            <div class="grid gap-3 border-b border-neutral p-4 last:border-b-0 lg:grid-cols-[minmax(120px,1fr)_110px_120px_120px_minmax(160px,1.2fr)_110px] lg:items-start">
                <div class="min-w-0">
                    <p class="font-semibold">
                        Invoice #{{ $invoice->number ?: $invoice->id }}
                    </p>
                    <p class="mt-1 text-xs text-base/50">
                        Due {{ $invoice->due_at?->format('M d, Y') ?? 'Not set' }}
                    </p>
                </div>
                <div>
                    @php
                        $invoiceStatusClass = match ($invoice->status) {
                            'paid' => 'border-green-500/30 bg-green-500/10 text-green-500',
                            'cancelled' => 'border-red-500/30 bg-red-500/10 text-red-500',
                            default => 'border-yellow-500/30 bg-yellow-500/10 text-yellow-500',
                        };
                    @endphp
                    <span class="inline-flex rounded-md border px-2.5 py-1 text-xs font-semibold {{ $invoiceStatusClass }}">
                        {{ ucfirst($invoice->status) }}
                    </span>
                </div>
                <div class="text-sm font-semibold">{{ $invoice->formattedTotal }}</div>
                <div class="text-sm">{{ $invoice->formattedRemaining }}</div>
                <div class="space-y-2">
                    @forelse ($invoice->transactions->sortByDesc('created_at') as $transaction)
                        @php
                            $transactionStatusClass = match ($transaction->status) {
                                \App\Enums\InvoiceTransactionStatus::Succeeded => 'text-green-500',
                                \App\Enums\InvoiceTransactionStatus::Processing => 'text-yellow-500',
                                \App\Enums\InvoiceTransactionStatus::Failed => 'text-red-500',
                            };
                        @endphp
                        <div class="rounded-md border border-neutral px-3 py-2 text-sm">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-semibold {{ $transactionStatusClass }}">{{ ucfirst($transaction->status->value) }}</span>
                                <span>{{ $transaction->formattedAmount }}</span>
                            </div>
                            <p class="mt-1 break-all text-xs text-base/50">
                                {{ $transaction->transaction_id ?: 'Transaction ID N/A' }}
                                @if ($transaction->gateway || $transaction->is_credit_transaction)
                                    · {{ $transaction->is_credit_transaction ? __('invoices.paid_with_credits') : $transaction->gateway?->name }}
                                @endif
                            </p>
                            <p class="mt-1 text-xs text-base/50">{{ $transaction->created_at->format('M d, Y H:i') }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-base/50">No payment transactions yet.</p>
                    @endforelse
                </div>
                <div class="lg:text-right">
                    <a href="{{ route('invoices.show', $invoice) }}" wire:navigate
                        class="inline-flex min-h-11 items-center justify-center rounded-md border border-neutral px-4 py-2 text-sm font-semibold hover:bg-background-secondary">
                        View invoice
                    </a>
                </div>
            </div>
            @endforeach
        </div>
    @endif
</section>
