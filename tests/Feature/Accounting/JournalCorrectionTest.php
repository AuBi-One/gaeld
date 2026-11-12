<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Actions\YearEndClosingAction;
use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\JournalCorrectionStatus;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Services\PayrollCalculator;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\CreatesAccountingFixtures;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Guided correction of a posted entry (D70: the posted entry never changes):
 * a locked reversal and an editable replacement, posted or discarded together.
 */
class JournalCorrectionTest extends TestCase
{
    use CreatesAccountingFixtures, RefreshDatabase, WithAuthenticatedOrganization;

    private Account $bank;

    private Account $office;

    private Account $rent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->bank = $this->account('1020', 'Bank', AccountType::Asset);
        $this->office = $this->account('6500', 'Office', AccountType::Expense);
        $this->rent = $this->account('6000', 'Rent', AccountType::Expense);
    }

    public function test_preparing_creates_a_locked_reversal_and_an_editable_replacement_without_touching_the_ledger(): void
    {
        $original = $this->postedEntry('JE-1');

        $this->prepare($original)->assertRedirect();

        $correction = JournalCorrection::query()->sole();
        $this->assertSame(JournalCorrectionStatus::Draft, $correction->status);
        $reversal = $correction->reversal()->with('lines')->firstOrFail();
        $replacement = $correction->replacement()->with('lines')->firstOrFail();

        $this->assertFalse($reversal->is_posted);
        $this->assertFalse($replacement->is_posted);
        $this->assertSame('REV-JE-1', $reversal->reference);
        $this->assertSame($original->id, $reversal->reversal_of_entry_id);
        $this->assertSame('120.00', (string) $reversal->lines->firstWhere('account_id', $this->office->id)?->credit);
        $this->assertSame('120.00', (string) $replacement->lines->firstWhere('account_id', $this->office->id)?->debit);
        $this->assertSame('2026-05-04', $reversal->date->toDateString());

        // The original is untouched and the ledger unchanged until the correction is posted.
        $this->assertSame('JE-1', $original->fresh()?->reference);
        $this->assertSame('120.00', $this->balance($this->office));
    }

    public function test_the_replacement_is_edited_and_both_drafts_are_posted_together(): void
    {
        $original = $this->postedEntry('JE-1');
        $this->prepare($original);
        $correction = JournalCorrection::query()->sole();

        // Booked on the wrong account: move it from office to rent.
        $this->actAsOrg()->put(route('accounting.journal-corrections.replacement', $correction), [
            'date' => '2026-05-04',
            'reference' => 'JE-1-C',
            'description' => 'Rent, not office',
            'lines' => [
                ['account_id' => $this->rent->id, 'debit' => '120.00', 'credit' => '0', 'description' => null],
                ['account_id' => $this->bank->id, 'debit' => '0', 'credit' => '120.00', 'description' => null],
            ],
        ])->assertSessionHasNoErrors();

        $this->actAsOrg()->post(route('accounting.journal-corrections.post', $correction))->assertSessionHas('success');

        $correction->refresh();
        $this->assertSame(JournalCorrectionStatus::Posted, $correction->status);
        $this->assertTrue((bool) $correction->reversal->is_posted);
        $this->assertTrue((bool) $correction->replacement->is_posted);
        $this->assertSame('JE-1-C', $correction->replacement->reference);
        $this->assertSame('0.00', $this->balance($this->office));
        $this->assertSame('120.00', $this->balance($this->rent));
        $this->assertSame('-120.00', $this->balance($this->bank));
        $this->assertSame('JE-1', $original->fresh()?->reference);

        // Final: no second post, no discard, the original cannot be corrected again.
        $this->actAsOrg()->delete(route('accounting.journal-corrections.destroy', $correction))->assertForbidden();
        $this->prepare($original)->assertSessionHas('error');
        $this->assertSame(1, JournalCorrection::query()->count());
    }

    public function test_discarding_deletes_both_drafts_and_the_original_can_be_corrected_again(): void
    {
        $original = $this->postedEntry('JE-1');
        $this->prepare($original);
        $correction = JournalCorrection::query()->sole();

        $this->actAsOrg()->delete(route('accounting.journal-corrections.destroy', $correction))->assertSessionHas('success');

        $this->assertSame(0, JournalCorrection::query()->count());
        $this->assertSame(1, JournalEntry::query()->count());
        $this->prepare($original)->assertSessionHasNoErrors();
        $this->assertSame(1, JournalCorrection::query()->count());
    }

    public function test_the_drafts_cannot_be_changed_posted_or_deleted_on_their_own(): void
    {
        $this->prepare($this->postedEntry('JE-1'));
        $correction = JournalCorrection::query()->sole();

        foreach ([$correction->reversal, $correction->replacement] as $draft) {
            $this->actAsOrg()->post("/accounting/journal-entries/{$draft->id}/post")->assertSessionHas('error');
            $this->actAsOrg()->delete("/accounting/journal-entries/{$draft->id}")->assertSessionHas('error');
            $this->actAsOrg()->put("/accounting/journal-entries/{$draft->id}", [
                'date' => '2026-05-04',
                'reference' => 'X',
                'lines' => [
                    ['account_id' => $this->rent->id, 'debit' => '1.00', 'credit' => '0'],
                    ['account_id' => $this->bank->id, 'debit' => '0', 'credit' => '1.00'],
                ],
            ])->assertSessionHas('error');
            $this->assertFalse((bool) $draft->fresh()?->is_posted);
        }
        $this->assertSame(3, JournalEntry::query()->count());
    }

    public function test_entries_owned_by_another_record_are_corrected_through_their_owner(): void
    {
        $invoiceEntry = $this->postedEntry('INV-2026-001');
        Invoice::factory()->for($this->organization)->create(['journal_entry_id' => $invoiceEntry->id]);
        $expenseEntry = $this->postedEntry('EXPENSE-MANUAL');
        Expense::factory()->for($this->organization)->create(['journal_entry_id' => $expenseEntry->id]);
        $slipEntry = $this->postedEntry('SLIP-2026-03');
        $employee = Employee::create(['organization_id' => $this->organization->id, 'first_name' => 'Max', 'last_name' => 'Muster', 'entry_date' => '2025-01-01', 'gross_salary' => '6000.00', 'is_active' => true]);
        $slip = app(PayrollCalculator::class)->calculate($employee, 3, 2026);
        $slip->fill(['journal_entry_id' => $slipEntry->id, 'posted_at' => now()])->save();

        foreach ([$invoiceEntry, $expenseEntry, $slipEntry] as $entry) {
            $this->assertFalse($this->user->can('correct', $entry), (string) $entry->reference);
            $this->prepare($entry)->assertSessionHas('error');
        }
        $this->assertSame(0, JournalCorrection::query()->count());
    }

    public function test_system_entries_reversals_and_reversed_entries_are_not_corrected(): void
    {
        $settlement = $this->postedEntry('VAT-SETTLEMENT-2026-01-01-2026-06-30');
        $settlement->update(['type' => 'vat_settlement']);
        $payroll = $this->postedEntry('PAY-AB-2026-03');
        $reversed = $this->postedEntry('JE-2');
        $reversal = app(LedgerService::class)->reverseEntry($reversed);
        app(LedgerService::class)->postDraft($reversal);

        $this->assertSame($reversed->id, $reversal->fresh()?->reversal_of_entry_id, 'reverseEntry links the reversal');

        foreach ([$settlement, $payroll, $reversed, $reversal->fresh()] as $entry) {
            $this->assertFalse($this->user->can('correct', $entry), (string) $entry?->reference);
        }
        $this->prepare($reversed)->assertSessionHas('error');
        $this->assertSame(0, JournalCorrection::query()->count());
    }

    public function test_a_draft_entry_is_edited_not_corrected(): void
    {
        $draft = app(LedgerService::class)->createDraft($this->organization->id, new JournalEntryData(
            date: '2026-04-01',
            reference: 'JE-DRAFT',
            description: 'draft',
            lines: [$this->journalLine($this->office, '10.00', '0'), $this->journalLine($this->bank, '0', '10.00')],
        ));

        $this->prepare($draft)->assertSessionHas('error');
        $this->assertSame(0, JournalCorrection::query()->count());
    }

    public function test_posting_into_a_closed_year_posts_nothing(): void
    {
        $original = $this->postedEntry('JE-1', '2025-03-01');
        $this->prepare($original, '2025-12-31');
        $correction = JournalCorrection::query()->sole();
        $this->organization->closeFiscalYear(2025);

        $this->actAsOrg()->post(route('accounting.journal-corrections.post', $correction))->assertSessionHas('error');

        $correction->refresh();
        $this->assertSame(JournalCorrectionStatus::Draft, $correction->status);
        $this->assertFalse((bool) $correction->reversal->is_posted);
        $this->assertFalse((bool) $correction->replacement->is_posted);
    }

    public function test_posting_into_a_settled_vat_period_posts_nothing(): void
    {
        $original = $this->postedEntry('JE-1');
        $this->prepare($original);
        $correction = JournalCorrection::query()->sole();
        $settlement = $this->postedEntry('VAT-SETTLEMENT-2026-01-01-2026-06-30', '2026-06-30');
        $settlement->update(['type' => 'vat_settlement', 'vat_period_start' => '2026-01-01', 'vat_period_end' => '2026-06-30']);

        $this->actAsOrg()->post(route('accounting.journal-corrections.post', $correction))->assertSessionHas('error');

        $this->assertFalse((bool) $correction->fresh()?->reversal->is_posted);
        $this->assertFalse((bool) $correction->fresh()?->replacement->is_posted);
    }

    public function test_the_replacement_may_not_book_on_an_inactive_account_but_the_reversal_may(): void
    {
        $original = $this->postedEntry('JE-1');
        $this->prepare($original);
        $correction = JournalCorrection::query()->sole();
        $this->office->update(['is_active' => false]);

        // The untouched replacement still books on the now inactive account.
        $this->actAsOrg()->post(route('accounting.journal-corrections.post', $correction))->assertSessionHas('error');
        $this->assertFalse((bool) $correction->fresh()?->reversal->is_posted);

        $this->actAsOrg()->put(route('accounting.journal-corrections.replacement', $correction), [
            'date' => '2026-05-04',
            'lines' => [
                ['account_id' => $this->rent->id, 'debit' => '120.00', 'credit' => '0'],
                ['account_id' => $this->bank->id, 'debit' => '0', 'credit' => '120.00'],
            ],
        ])->assertSessionHasNoErrors();
        $this->actAsOrg()->post(route('accounting.journal-corrections.post', $correction))->assertSessionHas('success');
        $this->assertSame('0.00', $this->balance($this->office));
    }

    public function test_an_unbalanced_or_reserved_replacement_is_refused(): void
    {
        $this->prepare($this->postedEntry('JE-1'));
        $correction = JournalCorrection::query()->sole();
        $lines = [
            ['account_id' => $this->rent->id, 'debit' => '120.00', 'credit' => '0'],
            ['account_id' => $this->bank->id, 'debit' => '0', 'credit' => '100.00'],
        ];

        $this->actAsOrg()->put(route('accounting.journal-corrections.replacement', $correction), ['date' => '2026-05-04', 'lines' => $lines])
            ->assertSessionHas('error');
        $lines[1]['credit'] = '120.00';
        $this->actAsOrg()->put(route('accounting.journal-corrections.replacement', $correction), ['date' => '2026-05-04', 'reference' => 'REV-X', 'lines' => $lines])
            ->assertSessionHasErrors('reference');
        $this->actAsOrg()->put(route('accounting.journal-corrections.replacement', $correction), ['date' => '2026-05-04', 'reference' => 'JE-1', 'lines' => $lines])
            ->assertSessionHas('error');

        $this->assertSame('6500', $correction->replacement()->firstOrFail()->lines()->orderBy('id')->first()?->account?->code);
    }

    public function test_the_entry_page_offers_the_correction_and_shows_its_parts(): void
    {
        $original = $this->postedEntry('JE-1');
        $this->actAsOrg()->get(route('accounting.journal-entries.show', $original))
            ->assertInertia(fn ($page) => $page->where('canCorrect', true)->where('correction', null));

        $this->prepare($original);
        $correction = JournalCorrection::query()->sole();

        $this->actAsOrg()->get(route('accounting.journal-entries.show', $correction->replacement_journal_entry_id))
            ->assertInertia(fn ($page) => $page
                ->where('correction.role', 'replacement')
                ->where('correction.editable', true)
                ->where('canCorrect', false)
                ->has('correctionAccounts', 3));
        $this->actAsOrg()->get(route('accounting.journal-entries.show', $correction->reversal_journal_entry_id))
            ->assertInertia(fn ($page) => $page->where('correction.role', 'reversal')->where('correction.editable', false));
    }

    public function test_another_organisation_or_a_viewer_cannot_touch_a_correction(): void
    {
        $original = $this->postedEntry('JE-1');
        $this->prepare($original);
        $correction = JournalCorrection::query()->sole();

        $viewer = User::factory()->create();
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        $this->assignOrganizationRole($viewer, $this->organization, 'viewer');
        $asViewer = $this->actingAs($viewer)->withSession(['current_organization_id' => $this->organization->id]);
        $asViewer->post(route('accounting.journal-corrections.post', $correction))->assertForbidden();
        $asViewer->post(route('accounting.journal-corrections.store', $original), ['reason' => 'x', 'correction_date' => '2026-05-04'])->assertForbidden();

        $other = Organization::factory()->create();
        $stranger = User::factory()->create();
        $other->users()->attach($stranger->id, ['role' => 'owner']);
        $this->assignOrganizationRole($stranger, $other, 'owner');
        $status = $this->actingAs($stranger)->withSession(['current_organization_id' => $other->id])
            ->post(route('accounting.journal-corrections.post', $correction))->status();
        $this->assertContains($status, [403, 404]);

        $this->assertSame(JournalCorrectionStatus::Draft, $correction->fresh()?->status);
    }

    public function test_an_entry_of_a_closed_year_is_corrected_in_the_open_year(): void
    {
        $this->account('2900', 'Annual result', AccountType::Equity);
        $this->account(AccountCode::OPENING_BALANCE, 'Opening balance', AccountType::Equity);
        $original = $this->postedEntry('JE-2025', '2025-04-01');
        app(YearEndClosingAction::class)->execute($this->organization, [
            'year' => 2025, 'fiscal_year_id' => null, 'closing_date' => '2025-12-31',
            'reference' => 'BOUCL-2025', 'result_account_code' => '2900',
        ], $this->user);
        $this->assertNotNull($original->fresh()?->archived_at);

        $this->prepare($original, '2026-01-15')->assertSessionHasNoErrors()->assertSessionMissing('error');
        $correction = JournalCorrection::query()->sole();
        $this->actAsOrg()->post(route('accounting.journal-corrections.post', $correction))->assertSessionHas('success');

        $this->assertSame(JournalCorrectionStatus::Posted, $correction->fresh()?->status);
        $this->assertSame('2026-01-15', $correction->reversal->date->toDateString());
    }

    public function test_a_correction_date_in_a_closed_year_or_a_settled_vat_period_is_refused_up_front(): void
    {
        $original = $this->postedEntry('JE-1', '2025-03-01');
        $this->organization->closeFiscalYear(2025);
        $this->prepare($original, '2025-12-31')->assertSessionHas('error', __('app.journal_correction_date_closed'));

        $settlement = $this->postedEntry('VAT-SETTLEMENT-2026-01-01-2026-06-30', '2026-06-30');
        $settlement->update(['type' => 'vat_settlement', 'vat_period_start' => '2026-01-01', 'vat_period_end' => '2026-06-30']);
        $this->prepare($original, '2026-05-04')->assertSessionHas('error', __('app.journal_correction_date_vat_settled'));

        $this->assertSame(0, JournalCorrection::query()->count());
        $this->prepare($original, '2026-07-01')->assertSessionMissing('error');
        $this->assertSame(1, JournalCorrection::query()->count());
    }

    public function test_a_posted_replacement_can_be_corrected_again(): void
    {
        $this->prepare($this->postedEntry('JE-1'));
        $first = JournalCorrection::query()->sole();
        $this->actAsOrg()->post(route('accounting.journal-corrections.post', $first))->assertSessionHas('success');

        $this->actAsOrg()->get(route('accounting.journal-entries.show', $first->replacement_journal_entry_id))
            ->assertInertia(fn ($page) => $page->where('canCorrect', true)->where('correction.role', 'replacement'));

        $this->prepare($first->replacement()->firstOrFail())->assertSessionMissing('error');
        $second = JournalCorrection::query()->where('original_journal_entry_id', $first->replacement_journal_entry_id)->sole();

        $this->actAsOrg()->get(route('accounting.journal-entries.show', $first->replacement_journal_entry_id))
            ->assertInertia(fn ($page) => $page->where('correction.id', $second->id)->where('correction.role', 'original')->where('canCorrect', false));
        $this->actAsOrg()->get(route('accounting.journal-entries.show', $second->replacement_journal_entry_id))
            ->assertInertia(fn ($page) => $page->where('correction.id', $second->id)->where('correction.editable', true));
    }

    public function test_posting_records_the_reversal_in_the_journal_events(): void
    {
        $this->prepare($this->postedEntry('JE-1'));
        $correction = JournalCorrection::query()->sole();
        $this->assertDatabaseMissing('journal_events', ['journal_entry_id' => $correction->reversal_journal_entry_id, 'event_type' => 'reversed']);

        $this->actAsOrg()->post(route('accounting.journal-corrections.post', $correction))->assertSessionHas('success');

        $this->assertDatabaseHas('journal_events', ['journal_entry_id' => $correction->reversal_journal_entry_id, 'event_type' => 'reversed']);
    }

    private function prepare(JournalEntry $entry, string $date = '2026-05-04'): TestResponse
    {
        return $this->actAsOrg()->post(route('accounting.journal-corrections.store', $entry), [
            'reason' => 'Booked on the wrong account',
            'correction_date' => $date,
        ]);
    }

    private function postedEntry(string $reference, string $date = '2026-04-01'): JournalEntry
    {
        $this->postJournalEntry($date, [
            $this->journalLine($this->office, '120.00', '0'),
            $this->journalLine($this->bank, '0', '120.00'),
        ], $reference);

        return JournalEntry::query()->where('reference', $reference)->firstOrFail();
    }

    private function balance(Account $account): string
    {
        app(LedgerService::class)->flushCache($this->organization->id);

        return app(LedgerQueryService::class)->accountBalance($account->id);
    }

    private function account(string $code, string $name, AccountType $type): Account
    {
        return Account::create(['organization_id' => $this->organization->id, 'code' => $code, 'name' => $name, 'type' => $type->value, 'is_active' => true]);
    }
}
