<?php

namespace App\Domains\Invoicing\Controllers;

use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Exceptions\QrBillValidationException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceMailerService;
use App\Domains\Invoicing\Support\QrBillValidationMessageFormatter;
use App\Http\Controllers\Concerns\HandlesFlashErrorResponses;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Invoice communication: sending invoices and reminders to customers.
 */
class InvoiceCommunicationController extends Controller
{
    use HandlesFlashErrorResponses;

    public function sendInvoice(
        Invoice $invoice,
        InvoiceMailerService $mailerService,
        QrBillValidationMessageFormatter $messageFormatter,
    ): RedirectResponse {
        $this->authorize('send', $invoice);

        try {
            $mailerService->sendInvoice($invoice->load('customer'));
        } catch (InvalidInvoiceStateException $e) {
            return $this->backWithError($e);
        } catch (QrBillValidationException $e) {
            return $this->backWithError($messageFormatter->format($e->violations));
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('success', __('app.invoice_sent'));
    }

    /** Turn the scheduled payment reminders of the invoice on or off. */
    public function updateAutoReminders(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('manageReminders', $invoice);

        $validated = $request->validate(['auto_reminders' => ['required', 'boolean']]);
        $invoice->update(['auto_reminders' => (bool) $validated['auto_reminders']]);

        return redirect()->route('invoices.show', $invoice)
            ->with('success', __('app.auto_reminders_updated'));
    }

    public function sendReminder(Invoice $invoice, InvoiceMailerService $mailerService): RedirectResponse
    {
        $this->authorize('send', $invoice);

        try {
            $mailerService->sendReminder($invoice);
        } catch (InvalidInvoiceStateException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('success', __('app.reminder_sent'));
    }
}
