<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class JournalEntryJustificatifTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Account $bank;

    private Account $revenue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        Storage::fake('local');

        $this->bank = Account::create([
            'organization_id' => $this->organization->id,
            'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value,
        ]);
        $this->revenue = Account::create([
            'organization_id' => $this->organization->id,
            'code' => '3400', 'name' => 'Revenue', 'type' => AccountType::Revenue->value,
        ]);
    }

    /** @return list<array{account_id: string, debit: string, credit: string}> */
    private function lines(): array
    {
        return [
            ['account_id' => (string) $this->bank->id, 'debit' => '100.00', 'credit' => '0.00'],
            ['account_id' => (string) $this->revenue->id, 'debit' => '0.00', 'credit' => '100.00'],
        ];
    }

    public function test_a_justificatif_can_be_attached_when_creating_an_entry(): void
    {
        $file = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');

        $this->actAsOrg()->post('/accounting/journal-entries', [
            'date' => '2026-03-15',
            'reference' => 'JE-JUST-1',
            'is_posted' => true,
            'lines' => $this->lines(),
            'justificatif' => $file,
        ])->assertRedirect();

        $entry = JournalEntry::where('reference', 'JE-JUST-1')->firstOrFail();
        $this->assertNotNull($entry->justificatif_path);
        Storage::disk('local')->assertExists($entry->justificatif_path);
    }

    public function test_the_justificatif_can_be_downloaded(): void
    {
        $file = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');
        $this->actAsOrg()->post('/accounting/journal-entries', [
            'date' => '2026-03-15',
            'reference' => 'JE-JUST-2',
            'is_posted' => true,
            'lines' => $this->lines(),
            'justificatif' => $file,
        ]);
        $entry = JournalEntry::where('reference', 'JE-JUST-2')->firstOrFail();

        $this->actAsOrg()
            ->get(route('accounting.journal-entries.justificatif.download', $entry))
            ->assertOk();
    }

    public function test_the_show_page_exposes_the_justificatif_url_only_when_one_is_attached(): void
    {
        $entry = JournalEntry::create([
            'organization_id' => $this->organization->id,
            'date' => '2026-03-15',
            'reference' => 'JE-JUST-3',
            'is_posted' => false,
            'justificatif_path' => 'justificatifs/'.$this->organization->id.'/receipt.pdf',
        ]);

        $this->actAsOrg()
            ->get(route('accounting.journal-entries.show', $entry))
            ->assertInertia(fn ($page) => $page
                ->component('Accounting/JournalEntryShow')
                ->where('justificatifUrl', route('accounting.journal-entries.justificatif.download', $entry))
                ->where('canEditJustificatif', true));
    }

    public function test_a_draft_entrys_justificatif_can_be_removed(): void
    {
        Storage::disk('local')->put('justificatifs/'.$this->organization->id.'/receipt.pdf', 'content');
        $entry = JournalEntry::create([
            'organization_id' => $this->organization->id,
            'date' => '2026-03-15',
            'reference' => 'JE-JUST-4',
            'is_posted' => false,
            'justificatif_path' => 'justificatifs/'.$this->organization->id.'/receipt.pdf',
        ]);

        $this->actAsOrg()
            ->from(route('accounting.journal'))
            ->delete(route('accounting.journal-entries.justificatif.remove', $entry))
            ->assertRedirect(route('accounting.journal'));

        $this->assertNull($entry->fresh()->justificatif_path);
        Storage::disk('local')->assertMissing('justificatifs/'.$this->organization->id.'/receipt.pdf');
    }

    public function test_a_posted_entrys_justificatif_cannot_be_removed(): void
    {
        Storage::disk('local')->put('justificatifs/'.$this->organization->id.'/receipt.pdf', 'content');
        $entry = JournalEntry::create([
            'organization_id' => $this->organization->id,
            'date' => '2026-03-15',
            'reference' => 'JE-JUST-5',
            'is_posted' => true,
            'justificatif_path' => 'justificatifs/'.$this->organization->id.'/receipt.pdf',
        ]);

        $this->actAsOrg()
            ->delete(route('accounting.journal-entries.justificatif.remove', $entry))
            ->assertForbidden();

        $this->assertNotNull($entry->fresh()->justificatif_path);
    }
}
