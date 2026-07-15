<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceTransactionStatus;
use App\Events\InvoiceTransaction\Created as InvoiceTransactionCreated;
use App\Events\InvoiceTransaction\Updated as InvoiceTransactionUpdated;
use App\Listeners\SendMailListener;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use App\Services\Invoice\InvoicePaymentFailureNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePaymentFailureNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_failure_notification_is_sent_once_per_invoice(): void
    {
        $invoice = $this->createInvoice();
        $notifier = app(InvoicePaymentFailureNotifier::class);

        $this->assertTrue($notifier->notifyOnce($invoice));
        $this->assertFalse($notifier->notifyOnce($invoice));

        $this->assertDatabaseHas('properties', [
            'model_id' => $invoice->id,
            'model_type' => Invoice::class,
            'key' => 'invoice_payment_failed_notified_at',
        ]);
        $this->assertSame(1, $invoice->properties()
            ->where('key', 'invoice_payment_failed_notified_at')
            ->count());
    }

    public function test_listener_only_notifies_when_a_transaction_enters_failed_state(): void
    {
        $invoice = $this->createInvoice();
        $notifier = $this->mock(InvoicePaymentFailureNotifier::class);
        $listener = new SendMailListener($notifier);

        $createdFailure = new InvoiceTransaction([
            'invoice_id' => $invoice->id,
            'status' => InvoiceTransactionStatus::Failed,
        ]);
        $createdFailure->setRelation('invoice', $invoice);

        $notifier->shouldReceive('notifyOnce')
            ->twice()
            ->withArgs(fn (Invoice $notificationInvoice) => $notificationInvoice->is($invoice));

        $listener->handle(new InvoiceTransactionCreated($createdFailure));

        $transaction = $this->createTransactionWithoutEvents($invoice, InvoiceTransactionStatus::Succeeded);
        $transaction->status = InvoiceTransactionStatus::Failed;
        $transaction->saveQuietly();
        $listener->handle(new InvoiceTransactionUpdated($transaction));

        $transaction->fee = 1;
        $transaction->saveQuietly();
        $listener->handle(new InvoiceTransactionUpdated($transaction));
    }

    private function createInvoice(): Invoice
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => $user->id]);
        $invoice->items()->create([
            'description' => 'Test item',
            'quantity' => 1,
            'price' => 10,
        ]);

        return $invoice->fresh();
    }

    private function createTransactionWithoutEvents(Invoice $invoice, InvoiceTransactionStatus $status): InvoiceTransaction
    {
        $dispatcher = InvoiceTransaction::getEventDispatcher();
        InvoiceTransaction::unsetEventDispatcher();

        try {
            return InvoiceTransaction::create([
                'invoice_id' => $invoice->id,
                'amount' => 10,
                'status' => $status,
            ]);
        } finally {
            InvoiceTransaction::setEventDispatcher($dispatcher);
        }
    }
}
