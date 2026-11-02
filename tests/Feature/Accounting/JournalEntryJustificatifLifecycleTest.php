<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Events\JournalEntryPosted;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Services\OrganizationDocumentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The journal-entry justificatif over its life: stored with the entry (atomically),
 * replaced, deleted with the entry, counted in the quota, attachable once posted.
 */
class JournalEntryJustificatifLifecycleTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Account $bank;

    private Account $revenue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        Storage::fake('local');

        $this->bank = Account::create(['organization_id' => $this->org->id, 'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value]);
        $this->revenue = Account::create(['organization_id' => $this->org->id, 'code' => '3400', 'name' => 'Revenue', 'type' => AccountType::Revenue->value]);
    }

    /** @return array<string, mixed> */
    private function payload(string $reference, bool $posted, ?UploadedFile $file = null): array
    {
        return array_filter([
            'date' => '2026-03-15',
            'reference' => $reference,
            'is_posted' => $posted,
            'lines' => [
                ['account_id' => (string) $this->bank->id, 'debit' => '100.00', 'credit' => '0.00'],
                ['account_id' => (string) $this->revenue->id, 'debit' => '0.00', 'credit' => '100.00'],
            ],
            'justificatif' => $file,
        ], fn ($value) => $value !== null);
    }

    private function pdf(string $name = 'receipt.pdf'): UploadedFile
    {
        // Real content: a fake create() reports a size but stores an empty file.
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".str_repeat('x', 2048)."\n%%EOF\n");
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

    public function test_posting_listeners_already_see_the_justificatif(): void
    {
        $seen = 'not dispatched';
        Event::listen(JournalEntryPosted::class, function (JournalEntryPosted $event) use (&$seen): void {
            $seen = $event->journalEntry->justificatif_path;
        });

        $this->actAsOrg()->post('/accounting/journal-entries', $this->payload('JE-L-1', true, $this->pdf()))->assertRedirect();

        $entry = JournalEntry::where('reference', 'JE-L-1')->firstOrFail();
        $this->assertNotNull($entry->justificatif_path);
        $this->assertSame($entry->justificatif_path, $seen);
    }

    public function test_a_failing_posting_leaves_no_entry_no_file_and_no_reserved_quota(): void
    {
        Event::listen(JournalEntryPosted::class, function (): void {
            throw new \RuntimeException('listener failed');
        });

        $this->withoutExceptionHandling();
        try {
            $this->actAsOrg()->post('/accounting/journal-entries', $this->payload('JE-L-2', true, $this->pdf()));
            $this->fail('The listener exception should propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('listener failed', $e->getMessage());
        }

        $this->assertFalse(JournalEntry::where('reference', 'JE-L-2')->exists());
        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, $this->bytesUsed());
    }

    public function test_deleting_a_draft_deletes_its_justificatif_and_releases_the_quota(): void
    {
        $this->actAsOrg()->post('/accounting/journal-entries', $this->payload('JE-L-3', false, $this->pdf()))->assertRedirect();
        $entry = JournalEntry::where('reference', 'JE-L-3')->firstOrFail();
        $this->assertGreaterThan(0, $this->bytesUsed());

        $this->actAsOrg()->delete("/accounting/journal-entries/{$entry->id}")->assertRedirect();

        $this->assertNull(JournalEntry::find($entry->id));
        Storage::disk('local')->assertMissing($entry->justificatif_path);
        $this->assertSame(0, $this->bytesUsed());
    }

    public function test_replacing_the_justificatif_through_the_method_override_deletes_the_old_file(): void
    {
        $this->actAsOrg()->post('/accounting/journal-entries', $this->payload('JE-L-4', false, $this->pdf('old.pdf')))->assertRedirect();
        $entry = JournalEntry::where('reference', 'JE-L-4')->firstOrFail();
        $oldPath = $entry->justificatif_path;
        $bytesBefore = $this->bytesUsed();

        // The list modal sends multipart as POST + X-HTTP-Method-Override: PUT (PHP ignores multipart PUT bodies).
        $this->actAsOrg()
            ->post("/accounting/journal-entries/{$entry->id}", $this->payload('JE-L-4b', false, $this->pdf('new.pdf')), ['X-HTTP-Method-Override' => 'PUT'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $entry->refresh();
        $this->assertSame('JE-L-4b', $entry->reference);
        $this->assertNotSame($oldPath, $entry->justificatif_path);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($entry->justificatif_path);
        $this->assertSame($bytesBefore, $this->bytesUsed());

        // Without a file the document is kept.
        $this->actAsOrg()
            ->post("/accounting/journal-entries/{$entry->id}", $this->payload('JE-L-4c', false), ['X-HTTP-Method-Override' => 'PUT'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('JE-L-4c', $entry->fresh()->reference);
        Storage::disk('local')->assertExists($entry->fresh()->justificatif_path);
    }

    public function test_a_posted_entry_without_a_justificatif_can_receive_one_but_not_replace_it(): void
    {
        $this->actAsOrg()->post('/accounting/journal-entries', $this->payload('JE-L-5', true))->assertRedirect();
        $entry = JournalEntry::where('reference', 'JE-L-5')->firstOrFail();

        $this->actAsOrg()->get("/accounting/journal-entries/{$entry->id}")->assertInertia(fn ($page) => $page
            ->where('canAttachJustificatif', true)->where('canEditJustificatif', false));

        $this->actAsOrg()->post("/accounting/journal-entries/{$entry->id}/justificatif", ['justificatif' => $this->pdf()])
            ->assertSessionHasNoErrors()->assertRedirect();
        $path = $entry->fresh()->justificatif_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        $this->assertGreaterThan(0, $this->bytesUsed());

        // Once attached on a posted entry: no second attach, no removal.
        $this->actAsOrg()->post("/accounting/journal-entries/{$entry->id}/justificatif", ['justificatif' => $this->pdf('other.pdf')])->assertForbidden();
        $this->actAsOrg()->delete("/accounting/journal-entries/{$entry->id}/justificatif")->assertForbidden();
        $this->assertSame($path, $entry->fresh()->justificatif_path);
        $this->assertCount(1, $this->storedFiles());
    }

    public function test_attach_is_refused_for_owned_archived_and_foreign_entries(): void
    {
        $owned = JournalEntry::create(['organization_id' => $this->org->id, 'date' => '2026-03-15', 'reference' => 'JE-L-6', 'is_posted' => true]);
        Invoice::factory()->create(['organization_id' => $this->org->id, 'journal_entry_id' => $owned->id]);
        $archived = JournalEntry::create(['organization_id' => $this->org->id, 'date' => '2026-03-15', 'reference' => 'JE-L-7', 'is_posted' => true, 'archived_at' => now()]);
        $otherOrg = Organization::factory()->create();
        $foreign = JournalEntry::create(['organization_id' => $otherOrg->id, 'date' => '2026-03-15', 'reference' => 'JE-L-8', 'is_posted' => true]);

        $this->actAsOrg()->post("/accounting/journal-entries/{$owned->id}/justificatif", ['justificatif' => $this->pdf()])->assertForbidden();
        $this->actAsOrg()->post("/accounting/journal-entries/{$archived->id}/justificatif", ['justificatif' => $this->pdf()])->assertForbidden();
        $foreignResponse = $this->actAsOrg()->post("/accounting/journal-entries/{$foreign->id}/justificatif", ['justificatif' => $this->pdf()]);
        $this->assertContains($foreignResponse->status(), [403, 404]);

        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, $this->bytesUsed());
    }

    public function test_justificatifs_of_another_organisation_cannot_be_downloaded_or_removed(): void
    {
        $otherOrg = Organization::factory()->create();
        $path = "justificatifs/{$otherOrg->id}/foreign.pdf";
        Storage::disk('local')->put($path, '%PDF-1.4 foreign');
        $foreign = JournalEntry::create(['organization_id' => $otherOrg->id, 'date' => '2026-03-15', 'reference' => 'JE-L-9', 'is_posted' => false, 'justificatif_path' => $path]);

        $download = $this->actAsOrg()->get(route('accounting.journal-entries.justificatif.download', $foreign));
        $this->assertContains($download->status(), [403, 404]);
        $remove = $this->actAsOrg()->delete("/accounting/journal-entries/{$foreign->id}/justificatif");
        $this->assertContains($remove->status(), [403, 404]);

        Storage::disk('local')->assertExists($path);
        $this->assertSame($path, $foreign->fresh()->justificatif_path);
    }

    public function test_the_storage_backfill_counts_journal_entry_justificatifs(): void
    {
        $this->actAsOrg()->post('/accounting/journal-entries', $this->payload('JE-L-10', true, $this->pdf()))->assertRedirect();
        $entry = JournalEntry::where('reference', 'JE-L-10')->firstOrFail();
        $size = Storage::disk('local')->size($entry->justificatif_path);

        $this->artisan('organizations:backfill-document-storage', ['organization' => $this->org->id])->assertSuccessful();

        $this->assertSame($size, $this->bytesUsed());
    }

    public function test_an_update_replaces_a_justificatif_attached_meanwhile_instead_of_orphaning_it(): void
    {
        $this->actAsOrg()->post('/accounting/journal-entries', $this->payload('JE-L-11', false))->assertRedirect();
        $entry = JournalEntry::where('reference', 'JE-L-11')->firstOrFail();

        // Another request attaches a file after this one loaded the entry (route binding, no file yet).
        $attached = "justificatifs/{$this->org->id}/attached-meanwhile.pdf";
        $content = "%PDF-1.4\n".str_repeat('y', 512);
        Storage::disk('local')->put($attached, $content);
        app(OrganizationDocumentStorageService::class)->reserve($this->org, strlen($content));
        $done = false;
        JournalEntry::retrieved(function (JournalEntry $model) use (&$done, $entry, $attached): void {
            if (! $done && $model->id === $entry->id) {
                $done = true;
                DB::table('journal_entries')->where('id', $entry->id)->update(['justificatif_path' => $attached]);
            }
        });

        $this->actAsOrg()
            ->post("/accounting/journal-entries/{$entry->id}", $this->payload('JE-L-11', false, $this->pdf('new.pdf')), ['X-HTTP-Method-Override' => 'PUT'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertTrue($done);
        $newPath = $entry->fresh()->justificatif_path;
        $this->assertNotSame($attached, $newPath);
        Storage::disk('local')->assertMissing($attached);
        $this->assertSame([$newPath], $this->storedFiles());
        $this->assertSame(Storage::disk('local')->size($newPath), $this->bytesUsed());
    }
}
