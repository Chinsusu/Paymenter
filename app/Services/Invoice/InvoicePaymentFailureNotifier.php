<?php

namespace App\Services\Invoice;

use App\Helpers\NotificationHelper;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoicePaymentFailureNotifier
{
    private const NOTIFIED_PROPERTY_KEY = 'invoice_payment_failed_notified_at';

    /**
     * Send the payment-failure notification at most once per invoice.
     */
    public function notifyOnce(Invoice $invoice): bool
    {
        $notificationInvoice = DB::transaction(function () use ($invoice): ?Invoice {
            $lockedInvoice = Invoice::query()
                ->with('user')
                ->lockForUpdate()
                ->findOrFail($invoice->getKey());

            if ($lockedInvoice->properties()->where('key', self::NOTIFIED_PROPERTY_KEY)->exists()) {
                return null;
            }

            $lockedInvoice->properties()->create([
                'key' => self::NOTIFIED_PROPERTY_KEY,
                'name' => 'Invoice payment failure notified at',
                'value' => now()->toIso8601String(),
            ]);

            return $lockedInvoice;
        });

        if (!$notificationInvoice) {
            return false;
        }

        NotificationHelper::invoicePaymentFailedNotification(
            $notificationInvoice->user,
            $notificationInvoice
        );

        return true;
    }
}
