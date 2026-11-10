<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Accounting\Services\JournalEntryReferences;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\CancelInvoiceAction;
use App\Domains\Invoicing\Actions\CreateCreditNoteAction;
use App\Domains\Invoicing\Actions\CreateInvoiceAction;
use App\Domains\Invoicing\Actions\DeleteInvoiceAction;
use App\Domains\Invoicing\Actions\FinalizeInvoiceAction;
use App\Domains\Invoicing\Actions\GenerateQrInvoicePdfAction;
use App\Domains\Invoicing\Actions\PurgeInvoiceAction;
use App\Domains\Invoicing\Actions\RevertInvoiceToDraftAction;
use App\Domains\Invoicing\Actions\StoreInvoicePdfAction;
use App\Domains\Invoicing\DTOs\CreateInvoiceData;
use App\Domains\Invoicing\DTOs\InvoiceLineData;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Mail\InvoiceMail;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceMailerService;
use App\Domains\Organizations\Services\OrganizationDocumentStorageService;
use App\Support\Contracts\OrganizationQuotaResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/** Finalizing an invoice stores its PDF as the justificatif of the invoice and its journal entry. */
class InvoicePdfJustificatifTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    private VatRate $vatRate;

    private BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        Storage::fake('local');

        $this->org->update([
            'legal_name' => 'Test GmbH',
            'address' => 'Bahnhofstrasse 1',
            'postal_code' => '8001',
            'city' => 'Zürich',
            'country' => 'CH',
        ]);
        Account::create(['organization_id' => $this->org->id, 'code' => '1100', 'name' => 'Accounts Receivable', 'type' => AccountType::Asset->value]);
        Account::create(['organization_id' => $this->org->id, 'code' => AccountCode::REVENUE, 'name' => 'Revenue', 'type' => AccountType::Revenue->value]);
        Account::create(['organization_id' => $this->org->id, 'code' => '2200', 'name' => 'VAT Output', 'type' => AccountType::Liability->value]);
        $this->bankAccount = BankAccount::create([
            'organization_id' => $this->org->id,
            'name' => 'QR-Bill account',
            'currency' => 'CHF',
            'qr_iban' => 'CH4431999123000889012',
            'is_default_for_invoicing' => true,
            'is_active' => true,
        ]);
        $this->vatRate = VatRate::create([
            'organization_id' => $this->org->id,
            'name' => 'Standard',
            'rate' => 8.10,
            'code' => 'NORMAL',
            'is_default' => true,
        ]);
        $this->customer = Contact::create([
            'organization_id' => $this->org->id,
            'name' => 'Client AG',
            'email' => 'billing@client.test',
            'address' => 'Lagerstrasse 5',
            'postal_code' => '8004',
            'city' => 'Zürich',
            'country' => 'CH',
        ]);
    }

    public function test_finalizing_stores_the_pdf_as_the_justificatif_of_the_invoice_and_its_entry(): void
    {
        $invoice = $this->finalize($this->draft('INV-PDF-001'));

        $this->assertNotNull($invoice->justificatif_path);
        $this->assertSame($invoice->justificatif_path, $invoice->fresh()->justificatif_path);
        $this->assertStringStartsWith("justificatifs/{$this->org->id}/", $invoice->justificatif_path);
        $this->assertStringStartsWith('%PDF-', (string) Storage::disk('local')->get($invoice->justificatif_path));
        $this->assertSame(Storage::disk('local')->size($invoice->justificatif_path), $this->bytesUsed());

        $reference = app(JournalEntryReferences::class)->for($invoice->journalEntry);
        $this->assertSame(route('invoices.justificatif.download', [$invoice, 'inline' => 1]), $reference?->documentUrl);
    }

    public function test_an_existing_justificatif_is_kept(): void
    {
        $draft = $this->draft('INV-PDF-002');
        $uploaded = "justificatifs/{$this->org->id}/uploaded.pdf";
        Storage::disk('local')->put($uploaded, '%PDF-uploaded');
        $draft->update(['justificatif_path' => $uploaded]);

        $invoice = $this->finalize($draft);

        $this->assertSame($uploaded, $invoice->fresh()->justificatif_path);
        $this->assertSame([$uploaded], $this->storedFiles());
        $this->assertSame('%PDF-uploaded', Storage::disk('local')->get($uploaded));
    }

    public function test_a_pdf_that_cannot_be_rendered_does_not_block_finalizing(): void
    {
        $this->bankAccount->delete(); // no IBAN: no valid QR-bill
        Log::spy();

        $invoice = $this->finalize($this->draft('INV-PDF-003'));

        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
        $this->assertNotNull($invoice->journal_entry_id);
        $this->assertNull($invoice->fresh()->justificatif_path);
        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, $this->bytesUsed());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_the_storage_quota_is_respected_without_blocking_finalizing(): void
    {
        $this->mock(OrganizationQuotaResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('maxStorageBytes')->andReturn(10); // smaller than any PDF
            $mock->shouldIgnoreMissing(-1);
        });

        $invoice = $this->finalize($this->draft('INV-PDF-004'));

        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->justificatif_path);
        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, $this->bytesUsed());
    }

    public function test_reverting_to_draft_moves_the_stored_pdf_to_the_posted_entry(): void
    {
        $invoice = $this->finalize($this->draft('INV-PDF-005'));
        $first = (string) $invoice->justificatif_path;
        $postedEntryId = $invoice->journal_entry_id;
        $bytes = $this->bytesUsed();

        $draft = app(RevertInvoiceToDraftAction::class)->execute($invoice);

        $this->assertNull($draft->fresh()->justificatif_path);
        $this->assertSame($first, JournalEntry::find($postedEntryId)?->justificatif_path);
        $this->assertTrue(Storage::disk('local')->exists($first));
        $this->assertSame($bytes, $this->bytesUsed());

        $draft->update(['number' => 'INV-PDF-005-B']); // the reversed entry keeps the old reference
        $again = $this->finalize($draft->fresh());

        $this->assertNotNull($again->justificatif_path);
        $this->assertNotSame($first, $again->justificatif_path);
        $this->assertNotSame($postedEntryId, $again->journal_entry_id);
        $this->assertEqualsCanonicalizing([$first, $again->justificatif_path], $this->storedFiles());
        $this->assertSame($first, JournalEntry::find($postedEntryId)?->justificatif_path);
    }

    public function test_reverting_deletes_the_stored_pdf_when_the_entry_has_its_own_justificatif(): void
    {
        $invoice = $this->finalize($this->draft('INV-PDF-011'));
        $stored = (string) $invoice->justificatif_path;
        $own = "justificatifs/{$this->org->id}/own.pdf";
        Storage::disk('local')->put($own, '%PDF-own');
        JournalEntry::whereKey($invoice->journal_entry_id)->update(['justificatif_path' => $own]);

        $draft = app(RevertInvoiceToDraftAction::class)->execute($invoice);

        $this->assertNull($draft->fresh()->justificatif_path);
        $this->assertSame($own, JournalEntry::find($invoice->journal_entry_id)?->justificatif_path);
        $this->assertFalse(Storage::disk('local')->exists($stored));
        $this->assertSame(0, $this->bytesUsed());
    }

    public function test_reverting_to_draft_keeps_an_uploaded_justificatif(): void
    {
        $draft = $this->draft('INV-PDF-006');
        $uploaded = "justificatifs/{$this->org->id}/uploaded.pdf";
        Storage::disk('local')->put($uploaded, '%PDF-uploaded');
        $draft->update(['justificatif_path' => $uploaded]);

        $reverted = app(RevertInvoiceToDraftAction::class)->execute($this->finalize($draft));

        $this->assertSame($uploaded, $reverted->fresh()->justificatif_path);
        $this->assertTrue(Storage::disk('local')->exists($uploaded));
    }

    public function test_a_justificatif_attached_while_rendering_wins_and_the_pdf_is_dropped(): void
    {
        $this->bankAccount->delete();
        $invoice = $this->finalize($this->draft('INV-PDF-012')); // nothing stored: no IBAN
        $uploaded = "justificatifs/{$this->org->id}/uploaded.pdf";
        $this->mock(GenerateQrInvoicePdfAction::class, function (MockInterface $mock) use ($uploaded): void {
            $mock->shouldReceive('execute')->once()->andReturnUsing(function (Invoice $candidate) use ($uploaded): string {
                Invoice::query()->whereKey($candidate->id)->update(['justificatif_path' => $uploaded]); // a concurrent upload

                return '%PDF-race';
            });
        });

        $result = app(StoreInvoicePdfAction::class)->execute($invoice->fresh());

        $this->assertSame(StoreInvoicePdfAction::SKIPPED, $result);
        $this->assertSame($uploaded, $invoice->fresh()->justificatif_path);
        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, $this->bytesUsed());
    }

    public function test_purging_deletes_the_stored_pdf_and_releases_the_quota(): void
    {
        $invoice = $this->finalize($this->draft('INV-PDF-013'));
        $this->assertGreaterThan(0, $this->bytesUsed());
        $cancelled = app(CancelInvoiceAction::class)->execute($invoice);

        app(PurgeInvoiceAction::class)->execute($cancelled->fresh());

        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, $this->bytesUsed());
    }

    public function test_the_pdf_download_returns_the_stored_pdf_and_the_justificatif_is_named_after_the_invoice(): void
    {
        $invoice = $this->finalize($this->draft('INV-PDF-014'));
        $stored = Storage::disk('local')->get((string) $invoice->justificatif_path);
        $this->bankAccount->delete(); // re-rendering would fail now: the stored file is served

        $response = $this->actAsOrg()->get(route('invoices.qr-pdf', $invoice));
        $response->assertOk();
        $this->assertSame($stored, $response->getContent());

        $this->actAsOrg()->get(route('invoices.justificatif.download', $invoice))
            ->assertOk()
            ->assertDownload('invoice-INV-PDF-014.pdf');
    }

    public function test_the_remove_button_is_offered_only_where_removing_is_allowed(): void
    {
        $draft = $this->draft('INV-PDF-015');
        $uploaded = "justificatifs/{$this->org->id}/uploaded.pdf";
        Storage::disk('local')->put($uploaded, '%PDF-uploaded');
        $draft->update(['justificatif_path' => $uploaded]);

        $this->actAsOrg()->get(route('invoices.show', $draft))
            ->assertInertia(fn ($page) => $page->where('canRemoveJustificatif', true));

        $sent = $this->finalize($draft->fresh());

        $this->actAsOrg()->get(route('invoices.show', $sent))
            ->assertInertia(fn ($page) => $page->where('canRemoveJustificatif', false));
        $this->actAsOrg()->delete(route('invoices.justificatif.remove', $sent))->assertForbidden();
    }

    public function test_the_mail_attaches_the_stored_pdf(): void
    {
        $invoice = $this->finalize($this->draft('INV-PDF-007'));
        $stored = Storage::disk('local')->get((string) $invoice->justificatif_path);
        Mail::fake();

        app(InvoiceMailerService::class)->sendInvoice($invoice->fresh()->load('customer'));

        Mail::assertSent(InvoiceMail::class, function (InvoiceMail $mail) use ($stored): bool {
            $data = $mail->attachments()[0]->attachWith(fn () => null, fn ($data) => $data());

            return $data === $stored;
        });
        $this->assertCount(1, $this->storedFiles());
    }

    public function test_a_credit_note_is_skipped_quietly_and_left_out_of_the_backfill(): void
    {
        $invoice = $this->finalize($this->draft('INV-PDF-010'));
        Log::spy();

        $creditNote = $this->finalize(app(CreateCreditNoteAction::class)->execute($invoice));

        $this->assertSame(InvoiceStatus::Sent, $creditNote->fresh()->status);
        $this->assertNull($creditNote->fresh()->justificatif_path);
        $this->assertCount(1, $this->storedFiles()); // the invoice's only
        Log::shouldNotHaveReceived('warning');

        $this->artisan('invoices:store-pdf', ['--organization' => $this->org->id, '--since' => '2026-03-01'])
            ->expectsOutput('Invoices without a justificatif: 0')
            ->assertSuccessful();
    }

    public function test_the_backfill_needs_a_target_and_stores_missing_pdfs(): void
    {
        $this->bankAccount->delete();
        $missing = $this->finalize($this->draft('INV-PDF-008')); // stored nothing: no IBAN then
        $this->draft('INV-PDF-009'); // a draft gets no PDF
        $this->assertNull($missing->fresh()->justificatif_path);
        $this->restoreBankAccount();

        $this->artisan('invoices:store-pdf')->assertExitCode(2); // neither --invoice nor --since

        $this->artisan('invoices:store-pdf', ['--invoice' => 'INV-PDF-008', '--organization' => $this->org->id, '--dry-run' => true])
            ->expectsOutput('Invoices without a justificatif: 1')
            ->expectsOutput('INV-PDF-008')
            ->assertSuccessful();
        $this->assertNull($missing->fresh()->justificatif_path);

        $this->artisan('invoices:store-pdf', ['--invoice' => (string) $missing->id])
            ->expectsOutput('Invoices without a justificatif: 1')
            ->expectsOutputToContain('Stored: 1, skipped: 0, failed: 0')
            ->assertSuccessful();

        $this->assertNotNull($missing->fresh()->justificatif_path);
        $this->assertCount(1, $this->storedFiles());

        $this->artisan('invoices:store-pdf', ['--since' => '2026-03-01'])
            ->expectsOutput('Invoices without a justificatif: 0')
            ->assertSuccessful();
    }

    public function test_the_backfill_leaves_out_cancelled_and_imported_invoices(): void
    {
        $this->bankAccount->delete();
        $cancelled = $this->finalize($this->draft('INV-PDF-016'));
        app(CancelInvoiceAction::class)->execute($cancelled);
        $imported = $this->finalize($this->draft('INV-PDF-017'));
        Invoice::query()->whereKey($imported->id)->update(['created_at' => '2026-02-01 10:00:00']); // created before --since
        $this->finalize($this->draft('INV-PDF-019', '2025-12-01')); // imported now, issued before --since
        $this->restoreBankAccount();

        $this->artisan('invoices:store-pdf', ['--since' => '2026-03-01', '--dry-run' => true])
            ->expectsOutput('Invoices without a justificatif: 0')
            ->assertSuccessful();
        $this->artisan('invoices:store-pdf', ['--invoice' => 'INV-PDF-016', '--organization' => $this->org->id])
            ->expectsOutput('Invoices without a justificatif: 0')
            ->assertSuccessful();
        $this->assertSame([], $this->storedFiles());
    }

    public function test_the_backfill_fails_when_a_pdf_cannot_be_stored(): void
    {
        $this->bankAccount->delete();
        $this->finalize($this->draft('INV-PDF-018')); // still no IBAN at the backfill

        $this->artisan('invoices:store-pdf', ['--invoice' => 'INV-PDF-018', '--organization' => $this->org->id])
            ->expectsOutputToContain('Stored: 0, skipped: 0, failed: 1')
            ->assertFailed();
    }

    public function test_deleting_a_reverted_invoice_deletes_the_pdf_kept_on_its_entry(): void
    {
        $invoice = $this->finalize($this->draft('INV-PDF-020'));
        $draft = app(RevertInvoiceToDraftAction::class)->execute($invoice);
        $this->assertCount(1, $this->storedFiles()); // now on the reversed entry

        app(DeleteInvoiceAction::class)->execute($draft->fresh());

        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, $this->bytesUsed());
    }

    public function test_finalizing_replaces_a_stored_pdf_left_on_the_draft(): void
    {
        $invoice = $this->finalize($this->draft('INV-PDF-021'));
        $old = (string) $invoice->justificatif_path;
        $draft = app(RevertInvoiceToDraftAction::class)->execute($invoice);
        // As if moving it to the entry had failed: the draft still holds its stored PDF.
        JournalEntry::whereKey($invoice->journal_entry_id)->update(['justificatif_path' => null]);
        $draft->update(['justificatif_path' => $old, 'number' => 'INV-PDF-021-B']);

        $again = $this->finalize($draft->fresh());

        $this->assertNotNull($again->justificatif_path);
        $this->assertNotSame($old, $again->justificatif_path);
        $this->assertSame([$again->justificatif_path], $this->storedFiles());
        $this->assertSame(Storage::disk('local')->size((string) $again->justificatif_path), $this->bytesUsed());
    }

    public function test_the_backfill_validates_its_options(): void
    {
        $this->artisan('invoices:store-pdf', ['--invoice' => 'INV-PDF-001'])->assertExitCode(2); // number without --organization
        $this->artisan('invoices:store-pdf', ['--since' => '10.11.2026'])->assertExitCode(2);
        $this->artisan('invoices:store-pdf', ['--since' => '2026-02-30'])->assertExitCode(2);
        $this->artisan('invoices:store-pdf', ['--since' => '2026-03-01', '--dry-run' => true])->assertSuccessful();
    }

    private function restoreBankAccount(): void
    {
        $this->bankAccount = BankAccount::create([
            'organization_id' => $this->org->id,
            'name' => 'QR-Bill account',
            'currency' => 'CHF',
            'qr_iban' => 'CH4431999123000889012',
            'is_default_for_invoicing' => true,
            'is_active' => true,
        ]);
    }

    private function draft(string $number, string $issueDate = '2026-03-16'): Invoice
    {
        return app(CreateInvoiceAction::class)->execute(new CreateInvoiceData(
            organizationId: $this->org->id,
            customerId: $this->customer->id,
            number: $number,
            issueDate: $issueDate,
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

    private function finalize(Invoice $invoice): Invoice
    {
        return app(FinalizeInvoiceAction::class)->execute($invoice);
    }

    private function bytesUsed(): int
    {
        return app(OrganizationDocumentStorageService::class)->bytesUsed($this->org);
    }

    /** @return list<string> */
    private function storedFiles(): array
    {
        return Storage::disk('local')->allFiles("justificatifs/{$this->org->id}");
    }
}
