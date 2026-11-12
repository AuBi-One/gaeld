<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\VatReportService;
use App\Domains\Banking\Enums\BankTransactionType;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\BankTransaction;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\CancelInvoiceAction;
use App\Domains\Invoicing\Actions\CreateCreditNoteAction;
use App\Domains\Invoicing\Actions\CreateInvoiceAction;
use App\Domains\Invoicing\Actions\FinalizeInvoiceAction;
use App\Domains\Invoicing\Actions\RemoveInvoicePaymentAction;
use App\Domains\Invoicing\Actions\UpdatePaymentDateAction;
use App\Domains\Invoicing\Actions\WriteOffInvoiceRemainderAction;
use App\Domains\Invoicing\DTOs\CreateInvoiceData;
use App\Domains\Invoicing\DTOs\InvoiceLineData;
use App\Domains\Invoicing\DTOs\RecordPaymentData;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\PaymentMethod;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceStateException;
use App\Domains\Invoicing\Exceptions\InvalidPaymentException;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoicePayment;
use App\Domains\Invoicing\Services\InvoiceAccountingService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Part payments of an invoice: writing off a rest nobody will pay (booked like the invoice: its
 * revenue account and the VAT share per rate off the output tax), taking back a payment entered
 * by mistake, and why a part-paid invoice cannot be cancelled.
 */
class InvoicePartPaymentTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    private VatRate $standard;

    private VatRate $reduced;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        foreach ([
            ['1020', AccountType::Asset],
            ['1100', AccountType::Asset],
            ['2200', AccountType::Liability],
            [AccountCode::REVENUE, AccountType::Revenue],
        ] as [$code, $type]) {
            Account::create(['organization_id' => $this->org->id, 'code' => $code, 'name' => 'Account '.$code, 'type' => $type->value]);
        }

        $this->standard = VatRate::create(['organization_id' => $this->org->id, 'name' => 'Normal', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true]);
        $this->reduced = VatRate::create(['organization_id' => $this->org->id, 'name' => 'Reduziert', 'rate' => 2.60, 'code' => 'REDUCED']);
        $this->customer = Contact::create(['organization_id' => $this->org->id, 'name' => 'Kunde AG']);
    }

    public function test_the_rest_is_written_off_on_the_invoice_revenue_with_its_share_of_vat(): void
    {
        $invoice = $this->finalizedInvoice('INV-WO-1');
        $this->assertSame('1621.50', (string) $invoice->total);
        $this->pay($invoice, '1600.00');

        $writeOff = app(WriteOffInvoiceRemainderAction::class)->execute($invoice->fresh(), now()->toDateString(), 'Skonto');

        $this->assertSame(PaymentMethod::WriteOff, $writeOff->payment_method);
        $this->assertSame('21.50', (string) $writeOff->amount);
        $this->assertSame('Skonto', $writeOff->reference);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame('0.00', $this->balance('1100'));
        $this->assertSame('WO-INV-WO-1', $writeOff->journalEntry->reference);
        $this->assertTrue($writeOff->journalEntry->isBalanced());

        // 21.50 gross at 8.1 %: 1.61 VAT, 19.89 net, taken off what the invoice booked.
        $vat = VatEntry::where('journal_entry_id', $writeOff->journal_entry_id)->sole();
        $this->assertSame(VatEntryType::Output, $vat->type);
        $this->assertSame('-1.61', (string) $vat->vat_amount);
        $this->assertSame('-19.89', (string) $vat->base_amount);
        $this->assertSame('119.89', Money::absoluteAmount($this->balance('2200')));
        $this->assertSame('1480.11', Money::absoluteAmount($this->balance(AccountCode::REVENUE)));

        // The VAT report sees the turnover actually received.
        $report = app(VatReportService::class)->generate($this->org->id, now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString());
        $this->assertSame('1480.11', Money::of((string) $report['total_revenue']));
        $this->assertSame('119.89', Money::of((string) $report['total_output_vat']));
    }

    public function test_the_rest_is_split_over_several_vat_rates_by_their_gross_amount(): void
    {
        $invoice = $this->finalizedInvoice('INV-WO-2', [
            ['description' => 'Beratung', 'quantity' => 1, 'unit_price' => 1000.00, 'vat_rate_id' => $this->standard->id],
            ['description' => 'Bücher', 'quantity' => 1, 'unit_price' => 500.00, 'vat_rate_id' => $this->reduced->id],
        ]);
        $this->assertSame('1594.00', (string) $invoice->total);
        $this->pay($invoice, '1500.00');

        $writeOff = app(WriteOffInvoiceRemainderAction::class)->execute($invoice->fresh(), now()->toDateString(), 'Forderungsverlust');

        $entries = VatEntry::where('journal_entry_id', $writeOff->journal_entry_id)->get()->keyBy('vat_rate_id');
        $this->assertCount(2, $entries);
        // 94.00 rest: 63.75 on the 1081.00 at 8.1 %, 30.25 on the 513.00 at 2.6 %.
        $this->assertSame('-4.78', (string) $entries[$this->standard->getKey()]->vat_amount);
        $this->assertSame('-58.97', (string) $entries[$this->standard->getKey()]->base_amount);
        $this->assertSame('-0.77', (string) $entries[$this->reduced->getKey()]->vat_amount);
        $this->assertSame('-29.48', (string) $entries[$this->reduced->getKey()]->base_amount);
        $this->assertTrue(JournalEntry::findOrFail($writeOff->journal_entry_id)->isBalanced());
        $this->assertSame('0.00', $this->balance('1100'));
    }

    public function test_a_write_off_needs_an_open_rest(): void
    {
        $invoice = $this->finalizedInvoice('INV-WO-3');
        $this->pay($invoice, (string) $invoice->total);

        $this->expectException(InvalidInvoiceStateException::class);

        app(WriteOffInvoiceRemainderAction::class)->execute($invoice->fresh(), now()->toDateString(), 'Skonto');
    }

    public function test_an_invoice_with_a_credit_note_is_not_written_off(): void
    {
        $invoice = $this->finalizedInvoice('INV-WO-CN');
        $creditNote = app(CreateCreditNoteAction::class)->execute($invoice->fresh());
        app(FinalizeInvoiceAction::class)->execute($creditNote->fresh());

        try {
            app(WriteOffInvoiceRemainderAction::class)->execute($invoice->fresh(), now()->toDateString(), 'Verlust');
            $this->fail('The rest was written off after a credit note.');
        } catch (InvalidPaymentException $e) {
            $this->assertSame(__('app.write_off_has_credit_note'), $e->getMessage());
        }

        $this->assertSame('0.00', $this->balance('1100'));
    }

    public function test_a_migrated_invoice_without_vat_entries_gets_none_on_its_write_off(): void
    {
        $invoice = $this->finalizedInvoice('INV-WO-MIG');
        VatEntry::where('journal_entry_id', $invoice->journal_entry_id)->delete(); // as migrated: 2200 lines, no VAT entries
        $this->pay($invoice, '1600.00');

        $writeOff = app(WriteOffInvoiceRemainderAction::class)->execute($invoice->fresh(), now()->toDateString(), 'Skonto');

        $this->assertSame(0, VatEntry::where('journal_entry_id', $writeOff->journal_entry_id)->count());
        $this->assertSame('119.89', Money::absoluteAmount($this->balance('2200')));
        $this->assertSame('0.00', $this->balance('1100'));
    }

    public function test_without_vat_on_the_original_posting_the_whole_rest_reduces_revenue(): void
    {
        $invoice = $this->finalizedInvoice('INV-WO-NOVAT');
        $vatOutput = Account::where('organization_id', $this->org->id)->where('code', '2200')->value('id');
        $revenue = Account::where('organization_id', $this->org->id)->where('code', AccountCode::REVENUE)->value('id');
        // As an imported entry booked gross on revenue, without 2200.
        VatEntry::where('journal_entry_id', $invoice->journal_entry_id)->delete();
        TransactionLine::where('journal_entry_id', $invoice->journal_entry_id)->where('account_id', $vatOutput)->delete();
        TransactionLine::where('journal_entry_id', $invoice->journal_entry_id)->where('account_id', $revenue)->update(['credit' => '1621.50']);
        $this->pay($invoice, '1600.00');

        $writeOff = app(WriteOffInvoiceRemainderAction::class)->execute($invoice->fresh(), now()->toDateString(), 'Skonto');

        $this->assertSame('0.00', $this->balance('2200'));
        $this->assertSame('1600.00', Money::absoluteAmount($this->balance(AccountCode::REVENUE)));
        $this->assertTrue($writeOff->journalEntry->isBalanced());
    }

    public function test_a_foreign_currency_invoice_is_not_written_off(): void
    {
        $invoice = $this->finalizedInvoice('INV-WO-EUR');
        Invoice::whereKey($invoice->id)->update(['currency' => 'EUR']);

        $this->expectException(InvalidPaymentException::class);

        app(WriteOffInvoiceRemainderAction::class)->execute($invoice->fresh(), now()->toDateString(), 'Skonto');
    }

    public function test_the_rest_is_written_off_through_the_invoice_page(): void
    {
        $invoice = $this->finalizedInvoice('INV-WO-HTTP');
        $this->pay($invoice, '1600.00');

        $response = $this->actAsOrg()->post(route('invoices.writeOff', $invoice), [
            'date' => now()->toDateString(),
            'reason' => 'Rundung',
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));
        $response->assertSessionHas('success', __('app.write_off_recorded'));
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_a_write_off_is_not_accepted_as_a_payment_method(): void
    {
        $invoice = $this->finalizedInvoice('INV-WO-METHOD');

        $response = $this->actAsOrg()->post(route('invoices.payment', $invoice), [
            'amount' => '100.00',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'write_off',
        ]);

        $response->assertSessionHasErrors('payment_method');
        $this->assertSame(0, $invoice->payments()->count());
    }

    public function test_a_payment_entered_by_mistake_is_removed_with_a_reversal(): void
    {
        $invoice = $this->finalizedInvoice('INV-RM-1');
        $payment = $this->pay($invoice, (string) $invoice->total);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        $response = $this->actAsOrg()->delete(route('invoices.payments.destroy', [$invoice, $payment]));

        $response->assertSessionHas('success', __('app.payment_removed'));
        $this->assertModelMissing($payment);
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
        $this->assertSame('1621.50', $this->balance('1100'));
        $this->assertSame('0.00', $this->balance('1020'));
        $reversal = JournalEntry::where('reference', 'REV-'.$payment->journalEntry->reference)->sole();
        $this->assertTrue($reversal->is_posted);
        $this->assertSame('payment_removal', $reversal->type);
        $this->assertSame($payment->payment_date->toDateString(), $reversal->date->toDateString());

        // The next payment does not clash with the reversed one's reference.
        $again = $this->pay($invoice->fresh(), '100.00');
        $this->assertSame('PAY-INV-RM-1-1-v2', $again->journalEntry->reference);
    }

    public function test_removing_a_write_off_takes_its_vat_correction_back(): void
    {
        $invoice = $this->finalizedInvoice('INV-RM-WO');
        $this->pay($invoice, '1600.00');
        $writeOff = app(WriteOffInvoiceRemainderAction::class)->execute($invoice->fresh(), now()->toDateString(), 'Skonto');

        app(RemoveInvoicePaymentAction::class)->execute($invoice->fresh(), $writeOff);

        $this->assertSame('21.50', $invoice->fresh()->amountDue());
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
        $this->assertSame('1500.00', Money::absoluteAmount($this->balance(AccountCode::REVENUE)));
        $this->assertSame('121.50', Money::absoluteAmount($this->balance('2200')));
        // Invoice 121.50, write-off -1.61, its reversal +1.61.
        $this->assertSame('121.50', Money::of((string) VatEntry::query()
            ->whereIn('journal_entry_id', JournalEntry::where('organization_id', $this->org->id)->pluck('id'))
            ->where('vat_rate_id', $this->standard->getKey())
            ->sum('vat_amount')));
    }

    public function test_a_write_off_cannot_be_redated(): void
    {
        $invoice = $this->finalizedInvoice('INV-WO-DATE');
        $this->pay($invoice, '1600.00');
        $writeOff = app(WriteOffInvoiceRemainderAction::class)->execute($invoice->fresh(), now()->toDateString(), 'Skonto');

        $this->expectException(\DomainException::class);

        app(UpdatePaymentDateAction::class)->execute($invoice->fresh(), $writeOff, now()->subDay()->toDateString());
    }

    public function test_a_payment_from_the_bank_reconciliation_is_taken_back_there(): void
    {
        $invoice = $this->finalizedInvoice('INV-RM-BANK');
        $payment = $this->pay($invoice, '500.00');
        $this->bankLine($invoice, $payment);

        $response = $this->actAsOrg()->delete(route('invoices.payments.destroy', [$invoice, $payment]));

        $response->assertSessionHas('error', __('app.payment_remove_bank_linked'));
        $this->assertModelExists($payment);
        $this->actAsOrg()->get(route('invoices.show', $invoice))
            ->assertInertia(fn ($page) => $page->where('bankLinkedPaymentIds', [$payment->id])->where('canCancel', false));
    }

    public function test_a_bank_line_left_on_the_original_entry_of_a_redated_payment_still_counts(): void
    {
        $invoice = $this->finalizedInvoice('INV-RM-OLD');
        $payment = $this->pay($invoice, '500.00');
        $line = $this->bankLine($invoice, $payment);
        $original = $payment->journal_entry_id;
        app(UpdatePaymentDateAction::class)->execute($invoice->fresh(), $payment, now()->subDay()->toDateString());
        $line->update(['journal_entry_id' => $original]); // as corrected before the bank line followed

        $this->actAsOrg()->delete(route('invoices.payments.destroy', [$invoice, $payment->fresh()]))
            ->assertSessionHas('error', __('app.payment_remove_bank_linked'));
        $this->assertModelExists($payment);
    }

    public function test_correcting_the_date_keeps_the_bank_line_on_the_live_entry(): void
    {
        $invoice = $this->finalizedInvoice('INV-DATE-BANK');
        $payment = $this->pay($invoice, '500.00');
        $line = $this->bankLine($invoice, $payment);

        $corrected = app(UpdatePaymentDateAction::class)->execute($invoice->fresh(), $payment, now()->subDay()->toDateString());

        $this->assertSame($corrected->journal_entry_id, $line->fresh()->journal_entry_id);
    }

    public function test_an_invoice_with_payments_cannot_be_cancelled(): void
    {
        $invoice = $this->finalizedInvoice('INV-CANCEL');
        $this->pay($invoice, '500.00');

        $this->actAsOrg()->post(route('invoices.cancel', $invoice))->assertForbidden();

        $this->expectException(InvalidInvoiceStateException::class);
        app(CancelInvoiceAction::class)->execute($invoice->fresh());
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function finalizedInvoice(string $number, array $lines = []): Invoice
    {
        $invoice = app(CreateInvoiceAction::class)->execute(new CreateInvoiceData(
            organizationId: $this->org->id,
            customerId: $this->customer->id,
            number: $number,
            issueDate: now()->subDays(10)->toDateString(),
            dueDate: now()->addDays(20)->toDateString(),
            currency: 'CHF',
            notes: null,
            paymentTerms: null,
            lines: array_map(fn (array $line) => InvoiceLineData::fromArray($line), $lines ?: [[
                'description' => 'Beratung',
                'quantity' => 10,
                'unit_price' => 150.00,
                'vat_rate_id' => $this->standard->id,
            ]]),
        ));

        return app(FinalizeInvoiceAction::class)->execute($invoice);
    }

    private function pay(Invoice $invoice, string $amount): InvoicePayment
    {
        return app(InvoiceAccountingService::class)->recordPayment($invoice, new RecordPaymentData(
            amount: $amount,
            paymentDate: now()->subDays(2)->toDateString(),
            paymentMethod: PaymentMethod::Bank,
            reference: null,
        ));
    }

    private function balance(string $code): string
    {
        $account = Account::where('organization_id', $this->org->id)->where('code', $code)->firstOrFail();

        return Money::of((string) app(LedgerQueryService::class)->accountBalance($account->id));
    }

    private function bankLine(Invoice $invoice, InvoicePayment $payment): BankTransaction
    {
        $bank = BankAccount::create([
            'organization_id' => $this->org->id,
            'name' => 'Bank',
            'currency' => 'CHF',
            'is_active' => true,
            'account_id' => Account::where('organization_id', $this->org->id)->where('code', '1020')->value('id'),
        ]);

        return BankTransaction::create([
            'bank_account_id' => $bank->id,
            'date' => $payment->payment_date->toDateString(),
            'description' => 'Zahlungseingang',
            'amount' => (string) $payment->amount,
            'type' => BankTransactionType::Credit->value,
            'import_hash' => 'test-'.$payment->id,
            'journal_entry_id' => $payment->journal_entry_id,
            'matched_invoice_id' => $invoice->id,
            'is_reconciled' => true,
        ]);
    }
}
