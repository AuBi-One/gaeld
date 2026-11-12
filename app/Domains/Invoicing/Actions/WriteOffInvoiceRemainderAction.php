<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoicePayment;
use App\Domains\Invoicing\Services\InvoiceAccountingService;
use Illuminate\Support\Facades\Log;

/**
 * Settles the open rest of an invoice that will not be paid (cash discount, rounding, bad debt)
 * as a reduction of its revenue and output VAT (InvoiceAccountingService::writeOffRemainder()).
 */
class WriteOffInvoiceRemainderAction
{
    public function __construct(
        private InvoiceAccountingService $accountingService,
    ) {}

    public function execute(Invoice $invoice, string $date, string $reason): InvoicePayment
    {
        $payment = $this->accountingService->writeOffRemainder($invoice, $date, $reason);

        Log::info('Invoice rest written off', [
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
            'organization_id' => $invoice->organization_id,
        ]);

        return $payment;
    }
}
