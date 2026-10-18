<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Accounting\Services\JournalEntryReferences;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\CancelInvoiceAction;
use App\Domains\Invoicing\Actions\CreateInvoiceAction;
use App\Domains\Invoicing\Actions\DeleteInvoiceAction;
use App\Domains\Invoicing\Actions\FinalizeInvoiceAction;
use App\Domains\Invoicing\Actions\PurgeInvoiceAction;
use App\Domains\Invoicing\Actions\RevertInvoiceToDraftAction;
use App\Domains\Invoicing\DTOs\CreateInvoiceData;
use App\Domains\Invoicing\DTOs\InvoiceLineData;
use App\Domains\Invoicing\DTOs\RecordPaymentData;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\PaymentMethod;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Deleting or purging an invoice removes its journal entry together with the reversal of that entry,
 * found by the entry's own reference, and invoices and their payments own their journal entries.
 */
class InvoiceJournalEntryDeletionTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    private VatRate $vatRate;

    /** @var array<string, Account> */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        foreach (['1100' => AccountType::Asset, AccountCode::REVENUE => AccountType::Revenue, '1020' => AccountType::Asset, '2200' => AccountType::Liability] as $code => $type) {
            $this->accounts[$code] = Account::create(['organization_id' => $this->org->id, 'code' => $code, 'name' => "Account {$code}", 'type' => $type->value]);
        }
        $this->vatRate = VatRate::create(['organization_id' => $this->org->id, 'name' => 'Standard', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true]);
        $this->customer = Contact::create(['organization_id' => $this->org->id, 'name' => 'Test Client AG']);
    }

    public function test_a_gald_invoice_reverted_to_draft_is_deleted_with_its_entry_and_reversal(): void
    {
        $invoice = app(FinalizeInvoiceAction::class)->execute($this->createInvoice());
        $invoice = app(RevertInvoiceToDraftAction::class)->execute($invoice);
        $this->assertSame(2, $this->entryCount());

        app(DeleteInvoiceAction::class)->execute($invoice);

        $this->assertSame(0, $this->entryCount());
    }

    public function test_a_cancelled_gald_invoice_is_purged_with_its_entry_and_reversal(): void
    {
        $invoice = app(CancelInvoiceAction::class)->execute(app(FinalizeInvoiceAction::class)->execute($this->createInvoice()));

        app(PurgeInvoiceAction::class)->execute($invoice);

        $this->assertSame(0, $this->entryCount());
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    }

    public function test_an_imported_invoice_keeps_the_ledger_balanced_when_deleted(): void
    {
        // Entry written outside postToLedger (an import): its reference is not the invoice number.
        $invoice = $this->createInvoice();
        $entry = app(LedgerService::class)->postEntry($this->org->id, new JournalEntryData('2026-03-16', 'AT-42', 'Imported invoice', [
            new JournalLineData((string) $this->accounts['1100']->id, '1621.50', '0'),
            new JournalLineData((string) $this->accounts[AccountCode::REVENUE]->id, '0', '1500.00'),
            new JournalLineData((string) $this->accounts['2200']->id, '0', '121.50'),
        ]));
        $invoice->update(['status' => InvoiceStatus::Sent, 'journal_entry_id' => $entry->id]);

        $invoice = app(RevertInvoiceToDraftAction::class)->execute($invoice->fresh());
        $this->assertTrue(JournalEntry::where('reference', 'REV-AT-42')->exists());

        app(DeleteInvoiceAction::class)->execute($invoice);

        $this->assertSame(0, $this->entryCount());
    }

    public function test_an_invoice_whose_posted_entry_was_not_reversed_is_not_deleted(): void
    {
        $invoice = app(FinalizeInvoiceAction::class)->execute($this->createInvoice());
        $invoice->update(['status' => InvoiceStatus::Draft]); // inconsistent state, e.g. edited by hand

        try {
            app(DeleteInvoiceAction::class)->execute($invoice->fresh());
            $this->fail('Expected the deletion to be refused.');
        } catch (InvalidInvoiceStateException) {
            $this->assertSame(1, $this->entryCount());
            $this->assertNotSoftDeleted('invoices', ['id' => $invoice->id]);
        }
    }

    public function test_invoices_and_payments_own_their_journal_entries(): void
    {
        $invoice = app(FinalizeInvoiceAction::class)->execute($this->createInvoice());
        $payment = app(InvoiceAccountingService::class)->recordPayment($invoice, new RecordPaymentData(
            amount: '100.00', paymentDate: '2026-03-20', paymentMethod: PaymentMethod::Bank, reference: null,
        ));

        $owners = app(JournalEntryReferences::class)->forMany([(string) $invoice->journal_entry_id, (string) $payment->journal_entry_id]);

        $this->assertSame('Invoice INV-2026-001', $owners[(string) $invoice->journal_entry_id]->label);
        $this->assertSame('Payment of invoice INV-2026-001', $owners[(string) $payment->journal_entry_id]->label);
        $this->assertStringEndsWith("/invoices/{$invoice->id}", (string) $owners[(string) $payment->journal_entry_id]->url);

        // Owned: a posted invoice entry is reversed by cancelling the invoice, not from the journal.
        $this->actAsOrg()->post("/accounting/journal-entries/{$invoice->journal_entry_id}/reverse")->assertSessionHas('error');
        $this->assertFalse(JournalEntry::where('reference', 'like', 'REV-%')->exists());
    }

    private function createInvoice(): Invoice
    {
        return app(CreateInvoiceAction::class)->execute(new CreateInvoiceData(
            organizationId: $this->org->id,
            customerId: (string) $this->customer->id,
            number: 'INV-2026-001',
            issueDate: '2026-03-16',
            dueDate: '2026-04-15',
            currency: 'CHF',
            notes: null,
            paymentTerms: null,
            lines: [InvoiceLineData::fromArray(['description' => 'Web Development', 'quantity' => 10, 'unit_price' => 150.00, 'vat_rate_id' => $this->vatRate->id])],
        ));
    }

    private function entryCount(): int
    {
        return JournalEntry::where('organization_id', $this->org->id)->count();
    }
}
