<?php

namespace App\Domains\Invoicing\Actions;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Services\OrganizationDocumentStorageService;
use App\Support\Exceptions\FileUploadFailedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores the invoice PDF, the one the user downloads (in the organisation's layout), as the
 * justificatif of a posted invoice, so that its journal entry has its document.
 *
 * Only for an invoice without a justificatif: an uploaded or migrated one is never replaced.
 * Not for a credit note: it has no PDF (a QR-bill cannot carry a negative amount).
 * The file counts against the organisation's document storage. A failure (no valid QR-bill,
 * storage quota reached, disk error) is logged and never thrown: the caller goes on, and
 * `invoices:store-pdf` can store the PDF later.
 */
class StoreInvoicePdfAction
{
    public const STORED = 'stored';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    private const DISK = 'local';

    public function __construct(
        private GenerateQrInvoicePdfAction $pdfAction,
        private OrganizationDocumentStorageService $documentStorage,
    ) {}

    /**
     * @param  bool  $replaceStored  replace a PDF this action stored before (on finalizing: it is outdated)
     * @return self::STORED|self::SKIPPED|self::FAILED skipped: a draft, a credit note, or the invoice has a justificatif
     */
    public function execute(Invoice $invoice, bool $replaceStored = false): string
    {
        $previous = $replaceStored && $this->isStoredPdf($invoice) ? $invoice->justificatif_path : null;
        if ($invoice->status === InvoiceStatus::Draft
            || $invoice->type === InvoiceType::CreditNote
            || ($invoice->justificatif_path !== null && $previous === null)) {
            return self::SKIPPED;
        }

        $organization = $invoice->organization;
        $path = $this->prefix($invoice).Str::random(16).'.pdf';
        $reservedBytes = 0;
        $written = false;

        try {
            $pdf = $this->pdfAction->execute($invoice, $organization, $organization->locale ?? app()->getLocale());

            $this->documentStorage->reserve($organization, strlen($pdf));
            $reservedBytes = strlen($pdf);

            if (! Storage::disk(self::DISK)->put($path, $pdf)) {
                throw new FileUploadFailedException;
            }
            $written = true;

            // Set the path only if no other justificatif was attached meanwhile.
            $attached = DB::transaction(function () use ($invoice, $path, $previous): bool {
                $current = Invoice::query()->withoutGlobalScopes()->whereKey($invoice->id)->lockForUpdate()->first();
                if ($current === null || $current->justificatif_path !== $previous) {
                    return false;
                }
                $current->update(['justificatif_path' => $path]);

                return true;
            });

            if (! $attached) {
                $this->documentStorage->delete($organization, $path, self::DISK);

                return self::SKIPPED;
            }
        } catch (Throwable $exception) {
            if ($written) {
                Storage::disk(self::DISK)->delete($path);
            }
            $this->documentStorage->release($organization, $reservedBytes);

            Log::warning('Invoice PDF not stored as justificatif', [
                'invoice_id' => $invoice->id,
                'organization_id' => $invoice->organization_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return self::FAILED;
        }

        $invoice->forceFill(['justificatif_path' => $path])->syncOriginalAttribute('justificatif_path');
        if ($previous !== null) {
            $this->documentStorage->delete($organization, $previous, self::DISK);
        }

        return self::STORED;
    }

    /** The stored PDF of the invoice, if its justificatif is one this action stored. */
    public function storedPdf(Invoice $invoice): ?string
    {
        if (! $this->isStoredPdf($invoice)) {
            return null;
        }

        return Storage::disk(self::DISK)->get((string) $invoice->justificatif_path);
    }

    /**
     * When the invoice goes back to draft: its stored PDF (not an uploaded or migrated
     * justificatif) becomes the own justificatif of the entry it was posted with, which stays
     * posted (reversed), so the issued document stays with it; the storage quota is unchanged.
     * If that entry already has a justificatif, the stored PDF is deleted instead. The next
     * finalisation stores a fresh PDF.
     */
    public function moveToEntry(Invoice $invoice, ?string $journalEntryId): void
    {
        if (! $this->isStoredPdf($invoice)) {
            return;
        }

        $path = (string) $invoice->justificatif_path;
        try {
            $this->moveOrDelete($invoice, $journalEntryId, $path);
        } catch (Throwable $exception) {
            // The draft keeps the PDF: the next finalisation replaces it.
            Log::warning('Stored invoice PDF not moved to its journal entry', [
                'invoice_id' => $invoice->id,
                'organization_id' => $invoice->organization_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function moveOrDelete(Invoice $invoice, ?string $journalEntryId, string $path): void
    {
        $moved = DB::transaction(function () use ($invoice, $journalEntryId, $path): bool {
            $invoice->update(['justificatif_path' => null]);
            $entry = $journalEntryId === null ? null : JournalEntry::query()->withoutGlobalScopes()
                ->where('organization_id', $invoice->organization_id)
                ->whereKey($journalEntryId)
                ->lockForUpdate()
                ->first();
            if ($entry === null || $entry->justificatif_path !== null) {
                return false;
            }
            $entry->update(['justificatif_path' => $path]);

            return true;
        });

        if (! $moved) {
            $this->documentStorage->delete($invoice->organization, $path, self::DISK);
        }
    }

    /** The download name of the stored PDF, as for the PDF download and the mail. */
    public function fileName(Invoice $invoice): string
    {
        return 'invoice-'.str_replace(['/', '\\'], '-', (string) ($invoice->number ?? $invoice->id)).'.pdf';
    }

    public function isStoredPdf(Invoice $invoice): bool
    {
        return $invoice->justificatif_path !== null && str_starts_with($invoice->justificatif_path, $this->prefix($invoice));
    }

    /** Uploads are stored as `{uuid}.{ext}` and migrated files in sub-folders: this prefix is the action's own. */
    private function prefix(Invoice $invoice): string
    {
        return "justificatifs/{$invoice->organization_id}/invoice-{$invoice->id}-";
    }
}
