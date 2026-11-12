<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Actions\YearEndClosingAction;
use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Models\VatRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesAccountingFixtures;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Which VAT periods count as settled at year end, in the closing action and
 * in the wizard: whatever periodicity the organisation settles (AuBi-One:
 * semesters, D22), an unreversed settlement must cover the VAT activity.
 */
class YearEndClosingVatSettlementTest extends TestCase
{
    use CreatesAccountingFixtures, RefreshDatabase, WithAuthenticatedOrganization;

    private FiscalYear $fiscalYear;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        foreach ([
            ['1020', 'Bank', AccountType::Asset],
            ['2200', 'VAT owed', AccountType::Liability],
            ['2201', 'VAT settlement', AccountType::Liability],
            ['2900', 'Annual result', AccountType::Equity],
            ['3000', 'Revenue', AccountType::Revenue],
            ['3809', 'TDFN', AccountType::Revenue],
            [AccountCode::OPENING_BALANCE, 'Opening balance', AccountType::Equity],
        ] as [$code, $name, $type]) {
            Account::create(['organization_id' => $this->organization->id, 'code' => $code, 'name' => $name, 'type' => $type->value, 'is_active' => true]);
        }

        $this->fiscalYear = FiscalYear::factory()->for($this->organization)->create([
            'name' => '2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'status' => FiscalYearStatus::Operative,
        ]);

        // An invoice with VAT in every quarter.
        foreach (['2025-02-10', '2025-05-12', '2025-08-14', '2025-11-17'] as $i => $date) {
            $this->invoiceWithVat($date, 'INV-2025-00'.($i + 1));
        }
    }

    public function test_a_year_settled_by_semester_can_be_closed(): void
    {
        $this->settlement('2025-01-01', '2025-06-30');
        $this->settlement('2025-07-01', '2025-12-31');

        $this->assertWizardShowsUnsettled([]);

        app(YearEndClosingAction::class)->execute($this->organization, $this->validated(), $this->user);

        $this->assertSame(1, JournalEntry::query()->where('type', 'year_end_closing')->count());
        $this->assertSame(FiscalYearStatus::Closed, $this->fiscalYear->refresh()->status);
    }

    public function test_an_unsettled_second_semester_blocks_the_closing(): void
    {
        $this->settlement('2025-01-01', '2025-06-30');

        $this->assertWizardShowsUnsettled(['Q3 2025', 'Q4 2025']);
        $this->assertClosingRefused(['Q3 2025', 'Q4 2025'], ['Q1 2025', 'Q2 2025']);
    }

    public function test_a_reversed_settlement_does_not_count_as_settled(): void
    {
        $this->settlement('2025-01-01', '2025-06-30');
        $reversed = $this->settlement('2025-07-01', '2025-12-31');
        $this->reversal($reversed);

        $this->assertWizardShowsUnsettled(['Q3 2025', 'Q4 2025']);
        $this->assertClosingRefused(['Q3 2025', 'Q4 2025'], ['Q1 2025']);
    }

    public function test_a_draft_reversal_leaves_the_settlement_in_force(): void
    {
        $this->settlement('2025-01-01', '2025-06-30');
        $this->reversal($this->settlement('2025-07-01', '2025-12-31'), posted: false);

        $this->assertWizardShowsUnsettled([]);
    }

    public function test_a_re_settlement_after_a_reversal_counts_as_settled(): void
    {
        $this->settlement('2025-01-01', '2025-06-30');
        $this->reversal($this->settlement('2025-07-01', '2025-12-31'));
        $this->settlement('2025-07-01', '2025-12-31', version: 2);

        $this->assertWizardShowsUnsettled([]);

        app(YearEndClosingAction::class)->execute($this->organization, $this->validated(), $this->user);
        $this->assertSame(FiscalYearStatus::Closed, $this->fiscalYear->refresh()->status);
    }

    public function test_quarterly_settlements_still_count(): void
    {
        foreach ([['01-01', '03-31'], ['04-01', '06-30'], ['07-01', '09-30'], ['10-01', '12-31']] as [$from, $to]) {
            $this->settlement("2025-{$from}", "2025-{$to}");
        }

        $this->assertWizardShowsUnsettled([]);
    }

    public function test_a_settlement_known_only_by_its_reference_counts(): void
    {
        // Settlements written before the vat_period_* columns carry the period in the reference only.
        $this->settlement('2025-01-01', '2025-06-30', withPeriodColumns: false);
        $this->settlement('2025-07-01', '2025-12-31', withPeriodColumns: false);

        $this->assertWizardShowsUnsettled([]);
    }

    /** @param  list<string>  $expected */
    private function assertWizardShowsUnsettled(array $expected): void
    {
        $this->actAsOrg()
            ->get('/accounting/year-end-closing?year=2025&fiscal_year_id='.$this->fiscalYear->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('unsettledVatPeriods', $expected));
    }

    /**
     * @param  list<string>  $named
     * @param  list<string>  $notNamed
     */
    private function assertClosingRefused(array $named, array $notNamed): void
    {
        try {
            app(YearEndClosingAction::class)->execute($this->organization, $this->validated(), $this->user);
            $this->fail('Expected the unsettled VAT period to block the closing.');
        } catch (\RuntimeException $exception) {
            foreach ($named as $label) {
                $this->assertStringContainsString($label, $exception->getMessage());
            }
            foreach ($notNamed as $label) {
                $this->assertStringNotContainsString($label, $exception->getMessage());
            }
        }

        $this->assertSame(0, JournalEntry::query()->where('type', 'year_end_closing')->count());
        $this->assertSame(FiscalYearStatus::Operative, $this->fiscalYear->refresh()->status);
    }

    /** @return array<string, mixed> */
    private function validated(): array
    {
        return [
            'year' => 2025,
            'fiscal_year_id' => $this->fiscalYear->id,
            'closing_date' => '2025-12-31',
            'reference' => 'BOUCL-2025',
            'result_account_code' => '2900',
        ];
    }

    private function invoiceWithVat(string $date, string $reference): void
    {
        $this->postJournalEntry($date, [
            $this->journalLine($this->account('1020'), '108.10', '0'),
            $this->journalLine($this->account('3000'), '0', '100.00'),
            $this->journalLine($this->account('2200'), '0', '8.10'),
        ], $reference);

        VatEntry::create([
            'journal_entry_id' => JournalEntry::query()->where('reference', $reference)->value('id'),
            'vat_rate_id' => VatRate::query()->value('id') ?? VatRate::factory()->for($this->organization)->create()->id,
            'base_amount' => '100.00',
            'vat_amount' => '8.10',
            'type' => VatEntryType::Output,
        ]);
    }

    /** Our TDFN settlement (D22): Dr 2200 / Cr 2201 the AFC amount / Cr 3809 the difference. */
    private function settlement(string $from, string $to, int $version = 1, bool $withPeriodColumns = true): JournalEntry
    {
        $reference = "VAT-SETTLEMENT-{$from}-{$to}".($version > 1 ? "-v{$version}" : '');
        $this->postJournalEntry($to, [
            $this->journalLine($this->account('2200'), '16.20', '0'),
            $this->journalLine($this->account('2201'), '0', '12.40'),
            $this->journalLine($this->account('3809'), '0', '3.80'),
        ], $reference);

        $entry = JournalEntry::query()->where('reference', $reference)->firstOrFail();
        $entry->update(['type' => 'vat_settlement'] + ($withPeriodColumns ? ['vat_period_start' => $from, 'vat_period_end' => $to] : []));

        return $entry;
    }

    private function reversal(JournalEntry $settlement, bool $posted = true): void
    {
        JournalEntry::create([
            'organization_id' => $this->organization->id,
            'date' => $settlement->date->toDateString(),
            'reference' => 'REV-'.$settlement->reference,
            'description' => 'reversal',
            'is_posted' => $posted,
        ]);
    }

    private function account(string $code): Account
    {
        return Account::query()->where('organization_id', $this->organization->id)->where('code', $code)->firstOrFail();
    }
}
