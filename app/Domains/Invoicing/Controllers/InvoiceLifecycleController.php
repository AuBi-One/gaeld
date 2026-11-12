<?php

namespace App\Domains\Invoicing\Controllers;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Invoicing\Actions\CancelInvoiceAction;
use App\Domains\Invoicing\Actions\CreateCreditNoteAction;
use App\Domains\Invoicing\Actions\DuplicateInvoiceAction;
use App\Domains\Invoicing\Actions\FinalizeInvoiceAction;
use App\Domains\Invoicing\Actions\PurgeInvoiceAction;
use App\Domains\Invoicing\Actions\RecordPaymentAction;
use App\Domains\Invoicing\Actions\RemoveInvoicePaymentAction;
use App\Domains\Invoicing\Actions\RevertInvoiceToDraftAction;
use App\Domains\Invoicing\Actions\UpdatePaymentDateAction;
use App\Domains\Invoicing\Actions\WriteOffInvoiceRemainderAction;
use App\Domains\Invoicing\DTOs\RecordPaymentData;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Exceptions\InvalidPaymentException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoicePayment;
use App\Domains\Invoicing\Notifications\InvoicePaymentRecordedNotification;
use App\Domains\Invoicing\Requests\RecordPaymentRequest;
use App\Domains\Invoicing\Requests\UpdatePaymentDateRequest;
use App\Domains\Invoicing\Requests\WriteOffInvoiceRemainderRequest;
use App\Domains\Organizations\Enums\Permission;
use App\Http\Controllers\Concerns\HandlesFlashErrorResponses;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

/**
 * Invoice state transitions: finalize, cancel, duplicate, credit notes, and payments.
 */
class InvoiceLifecycleController extends Controller
{
    use HandlesFlashErrorResponses;

    public function finalize(Invoice $invoice, FinalizeInvoiceAction $action): RedirectResponse
    {
        $this->authorize('finalize', $invoice);

        try {
            $action->execute($invoice);
        } catch (InvalidInvoiceStateException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('success', __('app.invoice_finalized'));
    }

    public function cancel(Invoice $invoice, CancelInvoiceAction $action): RedirectResponse
    {
        $this->authorize('cancel', $invoice);

        try {
            $action->execute($invoice);
        } catch (InvalidInvoiceStateException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('success', __('app.invoice_cancelled'));
    }

    public function revertToDraft(Invoice $invoice, RevertInvoiceToDraftAction $action): RedirectResponse
    {
        $this->authorize('revertToDraft', $invoice);

        $posted = $invoice->journalEntry;

        try {
            $action->execute($invoice);
        } catch (InvalidInvoiceStateException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('invoices.edit', $invoice)
            ->with('success', __($this->reversedOnAnotherDate($posted) ? 'app.invoice_reverted_to_draft_settled_period' : 'app.invoice_reverted_to_draft'));
    }

    /**
     * Whether the reversal went to another date than the entry it reverses: its VAT period was
     * settled or its fiscal year closed (InvoiceAccountingService::correctionDate()).
     */
    private function reversedOnAnotherDate(?JournalEntry $posted): bool
    {
        $reversedOn = $posted === null ? null : JournalEntry::query()
            ->where('organization_id', $posted->organization_id)
            ->where('reference', 'REV-'.$posted->reference)
            ->value('date');

        return $reversedOn !== null && Carbon::parse($reversedOn)->toDateString() !== $posted?->date->toDateString();
    }

    public function recordPayment(RecordPaymentRequest $request, Invoice $invoice, RecordPaymentAction $action): RedirectResponse
    {
        $validated = $request->validated();

        $dto = RecordPaymentData::fromArray($validated);

        try {
            $action->execute($invoice, $dto);
        } catch (InvalidInvoiceStateException|InvalidPaymentException $e) {
            return $this->backWithError($e);
        } catch (ModelNotFoundException) {
            return $this->backWithError(__('app.account_not_found', ['code' => $validated['bank_account_code'] ?? '']));
        }

        // Notify org users with invoice permissions (excluding the actor)
        $org = $invoice->organization;
        app()[PermissionRegistrar::class]->setPermissionsTeamId($org->id);
        $recipients = $org->users()
            ->permission(Permission::InvoicingView->value)
            ->where('users.id', '!=', $request->user()->id)
            ->get();

        Notification::send($recipients, new InvoicePaymentRecordedNotification($invoice));

        return redirect()->route('invoices.show', $invoice)
            ->with('success', __('app.payment_recorded'));
    }

    public function updatePaymentDate(
        UpdatePaymentDateRequest $request,
        Invoice $invoice,
        InvoicePayment $payment,
        UpdatePaymentDateAction $action,
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $action->execute($invoice, $payment, $validated['payment_date']);
        } catch (\DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('success', __('app.payment_date_updated'));
    }

    public function writeOff(
        WriteOffInvoiceRemainderRequest $request,
        Invoice $invoice,
        WriteOffInvoiceRemainderAction $action,
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $action->execute($invoice, $validated['date'], $validated['reason']);
        } catch (\DomainException $e) {
            return $this->backWithError($e);
        } catch (ModelNotFoundException) {
            return $this->backWithError(__('app.account_not_found', ['code' => '1100 / 2200']));
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('success', __('app.write_off_recorded'));
    }

    public function removePayment(Invoice $invoice, InvoicePayment $payment, RemoveInvoicePaymentAction $action): RedirectResponse
    {
        $this->authorize('updatePayment', $invoice);

        try {
            $action->execute($invoice, $payment);
        } catch (\DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('success', __('app.payment_removed'));
    }

    public function duplicate(Invoice $invoice, DuplicateInvoiceAction $action): RedirectResponse
    {
        $this->authorize('duplicate', $invoice);

        $newInvoice = $action->execute($invoice);

        return redirect()->route('invoices.show', $newInvoice)
            ->with('success', __('app.invoice_duplicated'));
    }

    public function creditNote(Invoice $invoice, CreateCreditNoteAction $action): RedirectResponse
    {
        $this->authorize('creditNote', $invoice);

        try {
            $creditNote = $action->execute($invoice);
        } catch (InvalidInvoiceStateException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('invoices.show', $creditNote)
            ->with('success', __('app.credit_note_created'));
    }

    public function purge(Invoice $invoice, PurgeInvoiceAction $action): RedirectResponse
    {
        $this->authorize('forceDelete', $invoice);

        try {
            $action->execute($invoice);
        } catch (InvalidInvoiceStateException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('invoices.index')
            ->with('success', __('app.invoice_purged'));
    }
}
