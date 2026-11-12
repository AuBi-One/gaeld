<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Accounting\Actions\PostVatSettlementAction;
use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\CreateInvoiceAction;
use App\Domains\Invoicing\Actions\DeleteInvoiceAction;
use App\Domains\Invoicing\Actions\FinalizeInvoiceAction;
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

class RevertInvoiceToDraftTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    private VatRate $vatRate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        Account::create(['organization_id' => $this->org->id, 'code' => '1100', 'name' => 'Accounts Receivable', 'type' => AccountType::Asset->value]);
        Account::create(['organization_id' => $this->org->id, 'code' => AccountCode::REVENUE, 'name' => 'Revenue', 'type' => AccountType::Revenue->value]);
        Account::create(['organization_id' => $this->org->id, 'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value]);
        Account::create(['organization_id' => $this->org->id, 'code' => '2200', 'name' => 'VAT Output', 'type' => AccountType::Liability->value]);

        $this->vatRate = VatRate::create([
            'organization_id' => $this->org->id,
            'name' => 'Standard',
            'rate' => 8.10,
            'code' => 'NORMAL',
            'is_default' => true,
        ]);

        $this->customer = Contact::create(['organization_id' => $this->org->id, 'name' => 'Test Client AG']);
    }

    private function createInvoice(string $number = 'INV-2026-001'): Invoice
    {
        $action = app(CreateInvoiceAction::class);

        return $action->execute(new CreateInvoiceData(
            organizationId: $this->org->id,
            customerId: $this->customer->id,
            number: $number,
            issueDate: '2026-03-16',
            dueDate: '2026-04-15',
            currency: 'CHF',
            notes: null,
            paymentTerms: null,
            lines: [InvoiceLineData::fromArray([
                'description' => 'Web Development',
                'quantity' => 10,
                'unit_price' => 150.00,
                'vat_rate_id' => $this->vatRate->id,
            ])],
        ));
    }

    public function test_it_reverts_a_sent_invoice_to_draft_and_reverses_the_journal_entry(): void
    {
        $invoice = $this->createInvoice();
        $invoice = app(FinalizeInvoiceAction::class)->execute($invoice);

        $this->assertEquals(InvoiceStatus::Sent, $invoice->status);
        $originalRef = $invoice->journalEntry->reference;

        $invoice = app(RevertInvoiceToDraftAction::class)->execute($invoice);

        $this->assertEquals(InvoiceStatus::Draft, $invoice->status);

        $reversal = JournalEntry::where('organization_id', $this->org->id)
            ->where('reference', 'REV-'.$originalRef)
            ->first();
        $this->assertNotNull($reversal);
        $this->assertTrue($reversal->is_posted);
    }

    public function test_a_reverted_invoice_is_finalized_again_under_a_versioned_reference(): void
    {
        $invoice = app(FinalizeInvoiceAction::class)->execute($this->createInvoice());
        $invoice = app(RevertInvoiceToDraftAction::class)->execute($invoice);
        $invoice->lines()->first()->update(['quantity' => 12, 'amount' => '1800.00', 'vat_amount' => '145.80']);
        $invoice->recalculate();

        $invoice = app(FinalizeInvoiceAction::class)->execute($invoice->fresh());

        $this->assertEquals(InvoiceStatus::Sent, $invoice->status);
        $this->assertSame('INV-2026-001', $invoice->number);
        $this->assertSame('INV-2026-001-v2', $invoice->journalEntry->reference);
        // Original, its reversal and the new entry: the books hold the corrected invoice once.
        $this->assertSame(
            ['INV-2026-001', 'INV-2026-001-v2', 'REV-INV-2026-001'],
            JournalEntry::where('organization_id', $this->org->id)->orderBy('reference')->pluck('reference')->all(),
        );
        $this->assertEqualsWithDelta(1945.80, $this->receivableBalance(), 0.001);
        $this->assertEqualsWithDelta(145.80, (float) VatEntry::sum('vat_amount'), 0.001);
    }

    public function test_an_invoice_is_corrected_a_second_time(): void
    {
        $invoice = app(FinalizeInvoiceAction::class)->execute($this->createInvoice());
        $invoice = app(FinalizeInvoiceAction::class)->execute(app(RevertInvoiceToDraftAction::class)->execute($invoice));
        $invoice = app(RevertInvoiceToDraftAction::class)->execute($invoice);

        $invoice = app(FinalizeInvoiceAction::class)->execute($invoice);

        $this->assertSame('INV-2026-001-v3', $invoice->journalEntry->reference);
        $this->assertTrue(JournalEntry::where('reference', 'REV-INV-2026-001-v2')->where('is_posted', true)->exists());
        $this->assertEqualsWithDelta(1621.50, $this->receivableBalance(), 0.001);
    }

    public function test_deleting_a_corrected_draft_removes_every_entry_it_posted(): void
    {
        $other = JournalEntry::create(['organization_id' => $this->org->id, 'date' => '2026-03-16', 'reference' => 'INV-2026-001-X', 'description' => 'Not the invoice', 'is_posted' => false]);
        $invoice = app(FinalizeInvoiceAction::class)->execute($this->createInvoice());
        $invoice = app(FinalizeInvoiceAction::class)->execute(app(RevertInvoiceToDraftAction::class)->execute($invoice));
        $invoice = app(RevertInvoiceToDraftAction::class)->execute($invoice);
        $this->assertCount(2, $invoice->postings());

        app(DeleteInvoiceAction::class)->execute($invoice);

        $this->assertSame([$other->id], JournalEntry::where('organization_id', $this->org->id)->pluck('id')->all());
    }

    public function test_the_reversal_and_the_new_posting_stay_in_the_open_period_of_the_invoice(): void
    {
        $this->travelTo('2026-10-25');
        $invoice = app(FinalizeInvoiceAction::class)->execute($this->createInvoice());

        $response = $this->actAsOrg()->post("/invoices/{$invoice->id}/revert-to-draft");
        $response->assertSessionHas('success', __('app.invoice_reverted_to_draft'));
        $invoice = app(FinalizeInvoiceAction::class)->execute($invoice->fresh());

        $this->assertSame('2026-03-16', JournalEntry::where('reference', 'REV-INV-2026-001')->firstOrFail()->date->toDateString());
        $this->assertSame('2026-03-16', $invoice->journalEntry->date->toDateString());
    }

    public function test_a_settled_vat_period_is_left_as_filed_and_the_correction_goes_to_today(): void
    {
        Account::create(['organization_id' => $this->org->id, 'code' => '1170', 'name' => 'VAT Input', 'type' => AccountType::Asset->value]);
        Account::create(['organization_id' => $this->org->id, 'code' => '2201', 'name' => 'VAT payable', 'type' => AccountType::Liability->value]);
        $this->travelTo('2026-10-25');
        $invoice = app(FinalizeInvoiceAction::class)->execute($this->createInvoice());
        // A semester, as with the net tax rate method.
        app(PostVatSettlementAction::class)->execute($this->org->id, '2026-01-01', '2026-06-30');

        $response = $this->actAsOrg()->post("/invoices/{$invoice->id}/revert-to-draft");
        $response->assertSessionHas('success', __('app.invoice_reverted_to_draft_settled_period'));
        $this->travelTo('2026-10-25'); // the request resets the test clock (FakeTimeMiddleware)
        $invoice = app(FinalizeInvoiceAction::class)->execute($invoice->fresh());

        $this->assertSame('2026-10-25', JournalEntry::where('reference', 'REV-INV-2026-001')->firstOrFail()->date->toDateString());
        $this->assertSame('2026-10-25', $invoice->journalEntry->date->toDateString());
        $this->assertSame('2026-03-16', $invoice->issue_date->toDateString());
        $this->assertEqualsWithDelta(121.50, (float) VatEntry::whereHas('journalEntry', fn ($q) => $q->whereBetween('date', ['2026-01-01', '2026-06-30']))->sum('vat_amount'), 0.001);
    }

    public function test_a_closed_fiscal_year_is_left_alone_and_the_reversal_goes_to_today(): void
    {
        $this->travelTo('2027-02-10');
        $invoice = app(FinalizeInvoiceAction::class)->execute($this->createInvoice());
        FiscalYear::create(['organization_id' => $this->org->id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Closed]);

        app(RevertInvoiceToDraftAction::class)->execute($invoice);

        $this->assertSame('2027-02-10', JournalEntry::where('reference', 'REV-INV-2026-001')->firstOrFail()->date->toDateString());
    }

    public function test_a_stale_second_finalization_does_not_book_the_invoice_twice(): void
    {
        $draft = $this->createInvoice();
        $stale = Invoice::findOrFail($draft->id);
        app(FinalizeInvoiceAction::class)->execute($draft);

        try {
            app(InvoiceAccountingService::class)->postToLedger($stale);
            $this->fail('The invoice was posted twice.');
        } catch (InvalidInvoiceStateException) {
        }

        $this->assertSame(['INV-2026-001'], JournalEntry::where('organization_id', $this->org->id)->pluck('reference')->all());
        $this->assertEqualsWithDelta(1621.50, $this->receivableBalance(), 0.001);
    }

    public function test_a_corrected_draft_with_entries_in_a_settled_vat_period_is_not_deleted(): void
    {
        Account::create(['organization_id' => $this->org->id, 'code' => '1170', 'name' => 'VAT Input', 'type' => AccountType::Asset->value]);
        Account::create(['organization_id' => $this->org->id, 'code' => '2201', 'name' => 'VAT payable', 'type' => AccountType::Liability->value]);
        $this->travelTo('2026-10-25');
        $invoice = app(FinalizeInvoiceAction::class)->execute($this->createInvoice());
        $draft = app(RevertInvoiceToDraftAction::class)->execute($invoice); // reversal in March: open then
        app(FinalizeInvoiceAction::class)->execute($this->createInvoice('INV-2026-002')); // something to settle
        app(PostVatSettlementAction::class)->execute($this->org->id, '2026-01-01', '2026-06-30');

        $response = $this->actAsOrg()->delete("/invoices/{$draft->id}");

        $response->assertSessionHas('error', __('app.invoice_delete_closed_period', ['date' => '16.03.2026']));
        $this->assertNotNull($draft->fresh());
        $this->assertTrue(JournalEntry::where('reference', 'INV-2026-001')->exists());
        $this->assertTrue(JournalEntry::where('reference', 'REV-INV-2026-001')->exists());
    }

    private function receivableBalance(): float
    {
        $account = Account::where('organization_id', $this->org->id)->where('code', '1100')->firstOrFail();

        return (float) TransactionLine::where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($query) => $query->where('is_posted', true))
            ->selectRaw('COALESCE(SUM(debit - credit), 0) AS balance')
            ->value('balance');
    }

    public function test_it_refuses_to_revert_a_paid_invoice(): void
    {
        $invoice = $this->createInvoice();
        $invoice = app(FinalizeInvoiceAction::class)->execute($invoice);

        app(InvoiceAccountingService::class)->recordPayment($invoice, new RecordPaymentData(
            amount: (string) $invoice->total,
            paymentDate: '2026-04-01',
            paymentMethod: PaymentMethod::Bank,
            reference: null,
        ));

        $invoice->refresh();
        $this->assertEquals(InvoiceStatus::Paid, $invoice->status);

        $this->expectException(InvalidInvoiceStateException::class);
        app(RevertInvoiceToDraftAction::class)->execute($invoice);
    }

    public function test_it_refuses_to_revert_once_a_payment_is_recorded_even_if_partial(): void
    {
        $invoice = $this->createInvoice();
        $invoice = app(FinalizeInvoiceAction::class)->execute($invoice);

        app(InvoiceAccountingService::class)->recordPayment($invoice, new RecordPaymentData(
            amount: '100.00',
            paymentDate: '2026-04-01',
            paymentMethod: PaymentMethod::Bank,
            reference: null,
        ));

        $invoice->refresh();

        $this->expectException(InvalidInvoiceStateException::class);
        app(RevertInvoiceToDraftAction::class)->execute($invoice);
    }

    public function test_revert_to_draft_route_reverts_the_invoice(): void
    {
        $invoice = $this->createInvoice();
        $invoice = app(FinalizeInvoiceAction::class)->execute($invoice);

        $response = $this->actAsOrg()->post("/invoices/{$invoice->id}/revert-to-draft");

        $response->assertRedirect(route('invoices.edit', $invoice));
        $this->assertEquals(InvoiceStatus::Draft, $invoice->fresh()->status);
    }

    public function test_revert_to_draft_route_rejects_a_paid_invoice(): void
    {
        $invoice = $this->createInvoice();
        $invoice = app(FinalizeInvoiceAction::class)->execute($invoice);

        app(InvoiceAccountingService::class)->recordPayment($invoice, new RecordPaymentData(
            amount: (string) $invoice->total,
            paymentDate: '2026-04-01',
            paymentMethod: PaymentMethod::Bank,
            reference: null,
        ));

        $response = $this->actAsOrg()->post("/invoices/{$invoice->id}/revert-to-draft");

        $response->assertForbidden();
    }
}
