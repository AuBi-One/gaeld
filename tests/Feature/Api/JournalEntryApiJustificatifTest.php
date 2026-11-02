<?php

namespace Tests\Feature\Api;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Organizations\Services\OrganizationDocumentStorageService;
use Illuminate\Support\Facades\Storage;
use Tests\Security\SecurityTestCase;

/** Deleting a draft through the API removes its justificatif and frees the quota, like the web. */
class JournalEntryApiJustificatifTest extends SecurityTestCase
{
    public function test_deleting_a_draft_removes_its_justificatif_and_releases_the_quota(): void
    {
        Storage::fake('local');
        $token = $this->createApiToken($this->ownerA, $this->orgA);
        $storage = app(OrganizationDocumentStorageService::class);

        $path = "justificatifs/{$this->orgA->id}/receipt.pdf";
        $content = "%PDF-1.4\n".str_repeat('x', 1024);
        Storage::disk('local')->put($path, $content);
        $storage->reserve($this->orgA, strlen($content));
        $entry = JournalEntry::create([
            'organization_id' => $this->orgA->id,
            'date' => '2026-03-15',
            'reference' => 'API-JUST-DEL',
            'is_posted' => false,
            'justificatif_path' => $path,
        ]);

        $this->withToken($token)->withHeader('Idempotency-Key', 'api-just-del')
            ->deleteJson("/api/v1/journal-entries/{$entry->id}")
            ->assertNoContent();

        $this->assertNull(JournalEntry::find($entry->id));
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, $storage->bytesUsed($this->orgA));
    }
}
