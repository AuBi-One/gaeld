<?php

namespace App\Domains\Accounting\Controllers;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Organizations\Services\OrganizationDocumentStorageService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Journal entry justificatif (supporting document) management.
 */
class JournalEntryDocumentController extends Controller
{
    public function __construct(
        private OrganizationDocumentStorageService $documentStorage,
    ) {}

    public function removeJustificatif(JournalEntry $journalEntry): RedirectResponse
    {
        $this->authorize('update', $journalEntry);

        if ($journalEntry->justificatif_path) {
            $this->documentStorage->delete($journalEntry->organization, $journalEntry->justificatif_path);
            $journalEntry->update(['justificatif_path' => null]);
        }

        return redirect()->route('accounting.journal-entries.show', $journalEntry)
            ->with('success', __('app.justificatif_removed'));
    }

    public function downloadJustificatif(JournalEntry $journalEntry, Request $request): StreamedResponse|RedirectResponse
    {
        $this->authorize('view', $journalEntry);

        if (! $journalEntry->justificatif_path || ! Storage::disk('local')->exists($journalEntry->justificatif_path)) {
            abort(404);
        }

        $filename = basename($journalEntry->justificatif_path);

        if ($request->boolean('inline')) {
            return Storage::disk('local')->response(
                $journalEntry->justificatif_path,
                $filename,
            );
        }

        return Storage::disk('local')->download(
            $journalEntry->justificatif_path,
            $filename,
        );
    }
}
