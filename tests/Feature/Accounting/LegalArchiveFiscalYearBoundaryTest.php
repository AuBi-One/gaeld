<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\FiscalYearStatus;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\LegalArchive;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Accounting\Services\LegalArchivingService;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class LegalArchiveFiscalYearBoundaryTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_archive_uses_explicit_period_and_is_idempotent(): void
    {
        Storage::fake('local');

        $fiscalYear = FiscalYear::factory()->for($this->organization)->create([
            'name' => 'Migration year',
            'start_date' => '2024-01-01',
            'end_date' => '2025-06-30',
            'status' => FiscalYearStatus::Operative,
        ]);
        $bank = Account::create([
            'organization_id' => $this->organization->id,
            'code' => '1020',
            'name' => 'Bank',
            'type' => AccountType::Asset->value,
        ]);
        $revenue = Account::create([
            'organization_id' => $this->organization->id,
            'code' => '3000',
            'name' => 'Revenue',
            'type' => AccountType::Revenue->value,
        ]);
        $ledger = app(LedgerService::class);

        foreach ([
            ['2024-01-01', 'ARCHIVE-START'],
            ['2025-06-30', 'ARCHIVE-END'],
            ['2025-07-01', 'ARCHIVE-AFTER'],
        ] as [$date, $reference]) {
            $ledger->postEntry($this->organization->id, new JournalEntryData(
                date: $date,
                reference: $reference,
                description: $reference,
                lines: [
                    new JournalLineData(accountId: (string) $bank->id, debit: '100.00', credit: '0'),
                    new JournalLineData(accountId: (string) $revenue->id, debit: '0', credit: '100.00'),
                ],
            ));
        }

        $service = app(LegalArchivingService::class);
        $service->archiveFiscalYear($this->organization->id, '2024', $fiscalYear->id);

        $archives = LegalArchive::query()
            ->where('document_type', 'journal_entry')
            ->get();

        $this->assertCount(2, $archives);
        $this->assertTrue($archives->every(fn (LegalArchive $archive): bool => $archive->fiscal_year_id === $fiscalYear->id));
        $this->assertFalse(Storage::disk('local')->exists('archives/'.$this->organization->id.'/2025/journal_entry/ARCHIVE-AFTER.json'));

        $service->archiveFiscalYear($this->organization->id, '2024', $fiscalYear->id);

        $this->assertSame(2, LegalArchive::query()->where('document_type', 'journal_entry')->count());
    }

    public function test_archived_journal_entry_payload_includes_its_lines(): void
    {
        Storage::fake('local');

        $fiscalYear = FiscalYear::factory()->for($this->organization)->create([
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => FiscalYearStatus::Operative,
        ]);
        $bank = Account::create([
            'organization_id' => $this->organization->id,
            'code' => '1020',
            'name' => 'Bank',
            'type' => AccountType::Asset->value,
        ]);
        $revenue = Account::create([
            'organization_id' => $this->organization->id,
            'code' => '3000',
            'name' => 'Revenue',
            'type' => AccountType::Revenue->value,
        ]);
        app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData(
            date: '2024-03-01',
            reference: 'ARCHIVE-LINES',
            description: 'with lines',
            lines: [
                new JournalLineData(accountId: (string) $bank->id, debit: '123.45', credit: '0'),
                new JournalLineData(accountId: (string) $revenue->id, debit: '0', credit: '123.45'),
            ],
        ));

        $service = app(LegalArchivingService::class);
        $service->archiveFiscalYear($this->organization->id, '2024', $fiscalYear->id);

        $archive = LegalArchive::query()->where('document_type', 'journal_entry')->sole();
        $payload = Storage::disk('local')->get($archive->storage_path);
        $decoded = json_decode((string) $payload, true);

        $this->assertCount(2, $decoded['lines']);
        $this->assertSame('123.45', (string) $decoded['lines'][0]['debit']);
        $this->assertSame($archive->checksum_sha256, hash('sha256', (string) $payload));
        $this->assertTrue($service->verifyIntegrity($archive));
    }

    public function test_header_only_archive_written_before_lines_were_included_still_verifies(): void
    {
        Storage::fake('local');

        $entry = JournalEntry::create([
            'organization_id' => $this->organization->id,
            'date' => '2023-05-01',
            'reference' => 'OLD-ARCHIVE',
            'description' => 'archived before lines were part of the payload',
            'is_posted' => true,
        ]);
        // The format used until now: the entry header without its lines.
        $payload = (string) json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $path = 'archives/'.$this->organization->id.'/2023/journal_entry/'.$entry->id.'.json';
        Storage::put($path, $payload);
        $archive = LegalArchive::create([
            'organization_id' => $this->organization->id,
            'document_type' => 'journal_entry',
            'document_id' => (string) $entry->id,
            'version' => 1,
            'fiscal_year' => 2023,
            'checksum_sha256' => hash('sha256', $payload),
            'storage_path' => $path,
            'archived_at' => now(),
            'expires_at' => now()->addYears(10),
        ]);

        $this->assertTrue(app(LegalArchivingService::class)->verifyIntegrity($archive));
    }

    public function test_archive_does_not_include_another_organization(): void
    {
        Storage::fake('local');

        $fiscalYear = FiscalYear::factory()->for($this->organization)->create([
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => FiscalYearStatus::Operative,
        ]);
        $foreignOrganization = Organization::factory()->create();

        $foreignEntry = JournalEntry::create([
            'organization_id' => $foreignOrganization->id,
            'date' => '2024-06-01',
            'reference' => 'FOREIGN-ARCHIVE',
            'description' => 'Foreign organization entry',
            'is_posted' => true,
        ]);

        app(LegalArchivingService::class)->archiveFiscalYear(
            $this->organization->id,
            '2024',
            $fiscalYear->id,
        );

        $this->assertDatabaseMissing('legal_archives', [
            'document_id' => $foreignEntry->id,
        ]);
    }

    public function test_legacy_archive_rows_without_fiscal_year_provenance_remain_readable(): void
    {
        $archive = LegalArchive::create([
            'organization_id' => $this->organization->id,
            'document_type' => 'invoice',
            'document_id' => 'legacy-archive',
            'fiscal_year' => 2024,
            'fiscal_year_id' => null,
            'checksum_sha256' => str_repeat('a', 64),
            'storage_path' => 'archives/legacy.json',
            'archived_at' => now(),
            'expires_at' => now()->addYears(10),
        ]);

        $this->assertNull($archive->fiscal_year_id);
        $this->assertSame(2024, $archive->fiscal_year);
    }

    public function test_archive_acquires_the_organization_period_lock(): void
    {
        $fiscalYear = FiscalYear::factory()->for($this->organization)->create([
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => FiscalYearStatus::Operative,
        ]);
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->with(10)->andReturnTrue();
        $lock->shouldReceive('release')->once()->andReturnTrue();
        Cache::shouldReceive('lock')
            ->once()
            ->with("archive:{$this->organization->id}:{$fiscalYear->id}", 600)
            ->andReturn($lock);

        app(LegalArchivingService::class)->archiveFiscalYear(
            $this->organization->id,
            '2024',
            $fiscalYear->id,
        );
    }
}
