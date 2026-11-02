<?php

namespace App\Domains\Accounting\Controllers;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Organizations\Services\OrganizationDocumentStorageService;
use App\Http\Controllers\Controller;
use App\Support\Services\FileUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Journal entry justificatif (supporting document) management.
 */
class JournalEntryDocumentController extends Controller
{
    public function __construct(
        private OrganizationDocumentStorageService $documentStorage,
        private FileUploadService $uploadService,
    ) {}

    /**
     * Attach a justificatif to an entry without one, also once posted (audited like any
     * change of the entry). Replacing or removing it needs a draft (update).
     */
    public function attachJustificatif(Request $request, JournalEntry $journalEntry): RedirectResponse
    {
        $this->authorize('attachJustificatif', $journalEntry);

        $validated = $request->validate([
            'justificatif' => ['required', 'file', 'mimes:'.config('uploads.allowed_mimes.document'), 'max:'.config('uploads.max_size.document')],
        ]);
        $document = $validated['justificatif'];
        $organization = $journalEntry->organization;
        $documentBytes = (int) $document->getSize();
        $this->documentStorage->reserve($organization, $documentBytes);

        try {
            $path = $this->uploadService->store($document, "justificatifs/{$journalEntry->organization_id}");
        } catch (\Throwable $exception) {
            $this->documentStorage->release($organization, $documentBytes);

            throw $exception;
        }

        // Only if still none: a concurrent attach must not orphan the other file.
        $attached = DB::transaction(function () use ($journalEntry, $path): bool {
            $entry = JournalEntry::query()->whereKey($journalEntry->id)->lockForUpdate()->first();
            if ($entry === null || $entry->justificatif_path !== null) {
                return false;
            }
            $entry->update(['justificatif_path' => $path]);

            return true;
        });

        if (! $attached) {
            $this->documentStorage->delete($organization, $path);
            abort(409);
        }

        return back()->with('success', __('app.justificatif_added'));
    }

    public function removeJustificatif(JournalEntry $journalEntry): RedirectResponse
    {
        $this->authorize('update', $journalEntry);

        if ($journalEntry->justificatif_path) {
            $this->documentStorage->delete($journalEntry->organization, $journalEntry->justificatif_path);
            $journalEntry->update(['justificatif_path' => null]);
        }

        // Back to wherever the request came from: the entry's own page, or the
        // list's create/edit modal (a different page component — a redirect to
        // the show route would navigate away from it and close the modal).
        return back()->with('success', __('app.justificatif_removed'));
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
