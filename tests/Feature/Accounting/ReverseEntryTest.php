<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Exceptions\DuplicateReferenceException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesAccountingFixtures;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * LedgerService::reverseEntry: the link to the original, one reversal per
 * entry, unreferenced entries, long line texts; the closing is not reversed.
 */
class ReverseEntryTest extends TestCase
{
    use CreatesAccountingFixtures, RefreshDatabase, WithAuthenticatedOrganization;

    private Account $bank;

    private Account $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->bank = Account::create(['organization_id' => $this->organization->id, 'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value]);
        $this->office = Account::create(['organization_id' => $this->organization->id, 'code' => '6500', 'name' => 'Office', 'type' => AccountType::Expense->value]);
    }

    public function test_an_entry_is_reversed_only_once_even_when_the_reversal_was_renamed(): void
    {
        $original = $this->postedEntry('JE-1');
        $reversal = app(LedgerService::class)->reverseEntry($original);
        $this->assertSame($original->id, $reversal->reversal_of_entry_id);
        $reversal->forceFill(['reference' => 'STORNO-1'])->save();

        $this->expectException(DuplicateReferenceException::class);
        try {
            app(LedgerService::class)->reverseEntry($original);
        } finally {
            $this->assertSame(1, JournalEntry::query()->where('reversal_of_entry_id', $original->id)->count());
            $this->assertSame(2, JournalEntry::query()->count());
        }
    }

    public function test_two_unreferenced_entries_can_both_be_reversed(): void
    {
        $first = $this->postedEntry(null);
        $second = $this->postedEntry(null);

        $one = app(LedgerService::class)->reverseEntry($first);
        $two = app(LedgerService::class)->reverseEntry($second);

        $this->assertNull($one->reference);
        $this->assertNull($two->reference);
        $this->assertSame($first->id, $one->reversal_of_entry_id);
        $this->assertSame($second->id, $two->reversal_of_entry_id);
    }

    public function test_a_full_length_line_text_is_shortened_in_the_reversal(): void
    {
        $original = app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData(
            date: '2026-04-01',
            reference: 'JE-LONG',
            description: 'long',
            lines: [
                $this->journalLine($this->office, '10.00', '0', str_repeat('x', 255)),
                $this->journalLine($this->bank, '0', '10.00'),
            ],
        ));

        $reversal = app(LedgerService::class)->reverseEntry($original);

        $text = (string) $reversal->lines()->where('account_id', $this->office->id)->value('description');
        $this->assertSame(255, mb_strlen($text));
        $this->assertStringStartsWith('Reversal: x', $text);
    }

    public function test_the_year_end_closing_is_not_reversed_from_the_journal(): void
    {
        $closing = $this->postedEntry('BOUCL-2025');
        $closing->update(['type' => JournalEntry::TYPE_YEAR_END_CLOSING]);

        $this->assertFalse($this->user->can('reverse', $closing));
        $this->actAsOrg()->post("/accounting/journal-entries/{$closing->id}/reverse")
            ->assertSessionHas('error', __('app.journal_closing_not_reversible'));
        $this->assertSame(1, JournalEntry::query()->count());
    }

    private function postedEntry(?string $reference): JournalEntry
    {
        return app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData(
            date: '2026-04-01',
            reference: $reference,
            description: 'manual',
            lines: [$this->journalLine($this->office, '10.00', '0'), $this->journalLine($this->bank, '0', '10.00')],
        ));
    }
}
