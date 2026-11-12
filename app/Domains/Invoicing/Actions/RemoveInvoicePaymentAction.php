<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoicePayment;
use App\Domains\Invoicing\Services\InvoiceAccountingService;
use Illuminate\Support\Facades\Log;

/**
 * Takes back a payment or write-off entered by mistake, with a reversing entry.
 */
class RemoveInvoicePaymentAction
{
    public function __construct(
        private InvoiceAccountingService $accountingService,
    ) {}

    public function execute(Invoice $invoice, InvoicePayment $payment): void
    {
        $this->accountingService->removePayment($invoice, $payment);

        Log::info('Invoice payment removed', [
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
            'organization_id' => $invoice->organization_id,
        ]);
    }
}
