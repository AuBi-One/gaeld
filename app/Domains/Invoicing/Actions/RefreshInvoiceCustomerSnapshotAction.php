<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\InvoiceTaxTreatment;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Models\Invoice;
use Illuminate\Support\Facades\DB;

class RefreshInvoiceCustomerSnapshotAction
{
    public function execute(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $storedInvoice = Invoice::withoutGlobalScope('organization')
                ->where('organization_id', $invoice->organization_id)
                ->lockForUpdate()
                ->findOrFail($invoice->id);

            if ($storedInvoice->archived_at !== null) {
                throw new InvalidInvoiceStateException(__('app.invoice_customer_snapshot_archived'));
            }

            if ($storedInvoice->status !== InvoiceStatus::Draft) {
                throw new InvalidInvoiceStateException(__('app.invoice_customer_snapshot_issued'));
            }

            $customer = Contact::withoutGlobalScope('organization')
                ->where('organization_id', $storedInvoice->organization_id)
                ->findOrFail($storedInvoice->customer_id);

            ($storedInvoice->tax_treatment ?? InvoiceTaxTreatment::Standard)->validateCustomer($customer);

            // The invoice's "Attention" person stays, as long as it is still one of the customer's.
            $person = $storedInvoice->contact_person_id === null
                ? null
                : $customer->contactPersons()->whereKey($storedInvoice->contact_person_id)->first();

            $storedInvoice->update(['customer_snapshot' => $customer->toInvoiceSnapshot($person)]);

            return $storedInvoice;
        });
    }
}
