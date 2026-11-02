<?php

namespace App\Domains\Accounting\Controllers;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Exceptions\AlreadyPostedException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Accounting\Queries\JournalEntryQuery;
use App\Domains\Accounting\Requests\StoreJournalEntryRequest;
use App\Domains\Accounting\Services\JournalEntryReferences;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Organizations\Services\OrganizationDocumentStorageService;
use App\Http\Controllers\Concerns\HandlesFlashErrorResponses;
use App\Http\Controllers\Controller;
use App\Support\CsvExportService;
use App\Support\Exceptions\DomainException;
use App\Support\PdfExportService;
use App\Support\Services\FileUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Journal entry management: listing, creating, posting, and reversing entries.
 */
class AccountingController extends Controller
{
    use HandlesFlashErrorResponses;

    public function __construct(
        private FileUploadService $uploadService,
        private OrganizationDocumentStorageService $documentStorage,
    ) {}

    public function chartOfAccounts(Request $request): Response
    {
        $this->authorize('viewAny', Account::class);

        $query = Account::withCount('transactionLines')
            ->orderBy('code');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'ilike', "%{$search}%")
                    ->orWhere('name', 'ilike', "%{$search}%");
            });
        }

        $accounts = $query
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Account $a) => [
                ...$a->toArray(),
                'has_transactions' => $a->transaction_lines_count > 0,
            ]);

        $user = $request->user();

        return Inertia::render('Accounting/ChartOfAccounts', [
            'accounts' => $accounts,
            'query' => ['search' => $request->input('search', '')],
            'can' => [
                'create' => $user->can('create', Account::class),
                'edit' => $user->hasPermissionTo(Permission::AccountingEdit),
                'delete' => $user->hasPermissionTo(Permission::AccountingDelete),
            ],
            'accountTypes' => array_map(fn ($t) => ['value' => $t->value, 'label' => $t->value], AccountType::cases()),
        ]);
    }

    public function journalEntries(Request $request): Response
    {
        $this->authorize('viewAny', JournalEntry::class);

        $filters = JournalEntryQuery::params($request);
        $entries = JournalEntryQuery::list($filters);
        // The record that owns an entry (e.g. a salary slip): shown, and edit/delete/reverse happen there.
        // `source` is a display-only attribute (no such column); these models are never saved here.
        $owners = app(JournalEntryReferences::class)->forMany(array_map(fn (JournalEntry $entry) => (string) $entry->id, $entries->items()));
        foreach ($entries->items() as $entry) {
            $entry->setAttribute('source', ($owners[(string) $entry->id] ?? null)?->toArray());
        }

        $accounts = Account::where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type'])
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->display_name,
                'type' => $a->type->value,
            ]);

        $user = $request->user();

        // Filter choices include inactive accounts: old entries may still use them.
        // Lazy, so partial reloads of the list (filters, sort, paging) skip the query.
        $filterAccounts = fn () => Account::orderBy('code')
            ->get(['id', 'code', 'name', 'type'])
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->display_name,
                'type' => $a->type->value,
            ]);

        return Inertia::render('Accounting/JournalEntries', [
            'entries' => $entries,
            'accounts' => $accounts,
            'filterAccounts' => $filterAccounts,
            'filters' => $filters,
            'perPageOptions' => JournalEntryQuery::PER_PAGE_OPTIONS,
            'can' => [
                'create' => $user?->can('create', JournalEntry::class) ?? false,
                'edit' => $user?->hasPermissionTo(Permission::AccountingEdit) ?? false,
                'delete' => $user?->hasPermissionTo(Permission::AccountingDelete) ?? false,
            ],
        ]);
    }

    public function showJournalEntry(Request $request, JournalEntry $journalEntry): Response
    {
        $this->authorize('view', $journalEntry);

        return Inertia::render('Accounting/JournalEntryShow', [
            'entry' => $journalEntry->load('lines.account'),
            'source' => app(JournalEntryReferences::class)->for($journalEntry)?->toArray(),
            'justificatifUrl' => $journalEntry->justificatif_path
                ? route('accounting.journal-entries.justificatif.download', $journalEntry)
                : null,
            'canEditJustificatif' => $request->user()?->can('update', $journalEntry) ?? false,
            'canAttachJustificatif' => $request->user()?->can('attachJustificatif', $journalEntry) ?? false,
        ]);
    }

    public function createJournalEntry(CurrentOrganization $currentOrg): Response
    {
        $this->authorize('create', JournalEntry::class);

        $accounts = Account::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type'])
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->display_name,
                'type' => $a->type->value,
            ]);

        return Inertia::render('Accounting/JournalEntryCreate', [
            'accounts' => $accounts,
            'defaultDate' => now()->toDateString(),
        ]);
    }

    public function storeJournalEntry(
        StoreJournalEntryRequest $request,
        CurrentOrganization $currentOrg,
        LedgerService $ledger,
    ): RedirectResponse {
        $this->authorize('create', JournalEntry::class);

        $validated = $request->validated();
        $isPosted = (bool) $request->boolean('is_posted', true);

        $lines = array_map(fn (array $line) => new JournalLineData(
            accountId: (string) $line['account_id'],
            debit: (string) ($line['debit'] ?? '0'),
            credit: (string) ($line['credit'] ?? '0'),
            description: $line['description'] ?? null,
        ), $validated['lines']);

        $justificatifPath = $request->hasFile('justificatif')
            ? $this->storeJustificatif($currentOrg->get(), $request->file('justificatif'))
            : null;

        $entryData = new JournalEntryData(
            date: $validated['date'],
            reference: $validated['reference'] ?? null,
            description: $validated['description'] ?? null,
            lines: $lines,
            justificatifPath: $justificatifPath,
        );

        try {
            // One transaction with the posting listeners: whatever fails, no entry and no file remain.
            DB::transaction(fn () => $isPosted
                ? $ledger->postEntry($currentOrg->id(), $entryData)
                : $ledger->createDraft($currentOrg->id(), $entryData));
        } catch (Throwable $e) {
            $this->documentStorage->delete($currentOrg->get(), $justificatifPath);

            if ($e instanceof DomainException) {
                return $this->backWithError($e);
            }

            throw $e;
        }

        return $this->toJournalList()
            ->with('success', __($isPosted ? 'app.journal_entry_posted' : 'app.journal_entry_draft_saved'));
    }

    public function updateJournalEntry(
        StoreJournalEntryRequest $request,
        JournalEntry $journalEntry,
        CurrentOrganization $currentOrg,
    ): RedirectResponse {
        if ($refused = $this->refuseOwned('update', $journalEntry)) {
            return $refused;
        }
        $this->authorize('update', $journalEntry);

        if ($journalEntry->is_posted) {
            return $this->toJournalList()
                ->with('error', __('app.cannot_edit_posted_entry'));
        }

        $validated = $request->validated();

        $lines = array_map(fn (array $line) => new JournalLineData(
            accountId: (string) $line['account_id'],
            debit: (string) ($line['debit'] ?? '0'),
            credit: (string) ($line['credit'] ?? '0'),
            description: $line['description'] ?? null,
        ), $validated['lines']);

        $entryData = new JournalEntryData(
            date: $validated['date'],
            reference: $validated['reference'] ?? null,
            description: $validated['description'] ?? null,
            lines: $lines,
        );

        $oldJustificatifPath = null;
        $newJustificatifPath = $request->hasFile('justificatif')
            ? $this->storeJustificatif($journalEntry->organization, $request->file('justificatif'))
            : null;

        try {
            // Delete old lines and update entry in a transaction
            DB::transaction(function () use ($journalEntry, $entryData, $newJustificatifPath, &$oldJustificatifPath) {
                // Locked, and the replaced path read under the lock: a concurrent attach
                // (attachJustificatif) must not slip a file in between and be orphaned.
                $locked = JournalEntry::query()->whereKey($journalEntry->id)->lockForUpdate()->firstOrFail();
                if ($locked->is_posted) {
                    throw new AlreadyPostedException(__('app.cannot_edit_posted_entry'));
                }
                $oldJustificatifPath = $locked->justificatif_path;

                $journalEntry->lines()->delete();
                $journalEntry->update([
                    'date' => $entryData->date,
                    'reference' => $entryData->reference,
                    'description' => $entryData->description,
                    ...($newJustificatifPath !== null ? ['justificatif_path' => $newJustificatifPath] : []),
                ]);

                // Recreate lines
                foreach ($entryData->lines as $line) {
                    TransactionLine::create([
                        'journal_entry_id' => $journalEntry->id,
                        'account_id' => $line->accountId,
                        'debit' => $line->debit,
                        'credit' => $line->credit,
                        'description' => $line->description,
                    ]);
                }
            });

        } catch (Throwable $e) {
            $this->documentStorage->delete($journalEntry->organization, $newJustificatifPath);

            if ($e instanceof DomainException) {
                return $this->backWithError($e);
            }

            throw $e;
        }

        // The replaced document goes only once the new one is committed.
        if ($newJustificatifPath !== null) {
            $this->documentStorage->delete($journalEntry->organization, $oldJustificatifPath);
        }

        return $this->toJournalList()
            ->with('success', __('app.journal_entry_updated'));
    }

    public function postJournalEntry(JournalEntry $journalEntry, LedgerService $ledger): RedirectResponse
    {
        $this->authorize('post', $journalEntry);

        try {
            $ledger->postDraft($journalEntry);
        } catch (DomainException $e) {
            return $this->backWithError($e);
        }

        return $this->toJournalList()
            ->with('success', __('app.journal_entry_posted'));
    }

    public function reverseJournalEntry(JournalEntry $journalEntry, LedgerService $ledger): RedirectResponse
    {
        if ($refused = $this->refuseOwned('reverse', $journalEntry)) {
            return $refused;
        }
        $this->authorize('reverse', $journalEntry);

        try {
            $ledger->reverseEntry($journalEntry);
        } catch (DomainException $e) {
            return $this->backWithError($e);
        }

        return $this->toJournalList()
            ->with('success', __('app.journal_entry_reversed'));
    }

    public function destroyJournalEntry(JournalEntry $journalEntry, LedgerService $ledger): RedirectResponse
    {
        if ($refused = $this->refuseOwned('delete', $journalEntry)) {
            return $refused;
        }
        $this->authorize('delete', $journalEntry);

        try {
            // Also removes the justificatif and releases its quota (after commit).
            $ledger->deleteDraft($journalEntry);
        } catch (DomainException $e) {
            return $this->backWithError($e);
        }

        return $this->toJournalList()
            ->with('success', __('app.journal_entry_deleted'));
    }

    /**
     * Store an uploaded justificatif within the organisation's document quota.
     */
    private function storeJustificatif(Organization $organization, UploadedFile $document): string
    {
        $documentBytes = (int) $document->getSize();
        $this->documentStorage->reserve($organization, $documentBytes);

        try {
            return $this->uploadService->store($document, "justificatifs/{$organization->id}");
        } catch (Throwable $exception) {
            $this->documentStorage->release($organization, $documentBytes);

            throw $exception;
        }
    }

    /**
     * An entry owned by another feature (the policy denies with a message): back to the list with that message.
     */
    private function refuseOwned(string $ability, JournalEntry $entry): ?RedirectResponse
    {
        $check = Gate::inspect($ability, $entry);

        return $check->denied() && $check->message() !== null
            ? $this->toJournalList()->with('error', $check->message())
            : null;
    }

    /**
     * Back to the journal list, keeping its filters, sort and page when the action came from there.
     */
    private function toJournalList(): RedirectResponse
    {
        $previous = url()->previous();

        return str_starts_with($previous, route('accounting.journal').'?')
            ? redirect()->to($previous)
            : redirect()->route('accounting.journal');
    }

    public function trialBalance(Request $request, LedgerQueryService $ledgerService, CurrentOrganization $currentOrg): Response
    {
        $this->authorize('viewAny', Account::class);

        $orgId = $currentOrg->id();
        $asOfDate = $request->input('as_of_date', now()->toDateString());

        $balances = $ledgerService->trialBalance($orgId, $asOfDate);

        return Inertia::render('Accounting/TrialBalance', [
            'balances' => $balances,
            'asOfDate' => $asOfDate,
        ]);
    }

    public function exportTrialBalance(
        Request $request,
        LedgerQueryService $ledgerService,
        CurrentOrganization $currentOrg,
        PdfExportService $pdf,
        CsvExportService $csv,
        string $format,
    ): HttpResponse {
        $this->authorize('viewAny', Account::class);

        abort_unless(in_array($format, ['pdf', 'csv'], true), 404);

        $orgId = $currentOrg->id();
        $asOfDate = $request->input('as_of_date', now()->toDateString());
        $balances = $ledgerService->trialBalance($orgId, $asOfDate);
        $org = $currentOrg->get();

        if ($format === 'csv') {
            $headers = ['Code', 'Account', 'Type', 'Debit', 'Credit'];
            $rows = array_map(fn ($row) => [
                $row['account_code'],
                $row['account_name'],
                $row['account_type'] instanceof AccountType
                    ? $row['account_type']->value
                    : $row['account_type'],
                $row['debit'],
                $row['credit'],
            ], $balances);

            return $csv->export($headers, $rows, "trial-balance-{$asOfDate}.csv");
        }

        return $pdf->download('exports.trial-balance', [
            'organization' => $org,
            'asOfDate' => $asOfDate,
            'balances' => $balances,
        ], "trial-balance-{$asOfDate}.pdf");
    }

    public function exportJournalEntries(
        Request $request,
        CurrentOrganization $currentOrg,
        PdfExportService $pdf,
        CsvExportService $csv,
        string $format,
    ): HttpResponse {
        $this->authorize('viewAny', JournalEntry::class);

        abort_unless(in_array($format, ['pdf', 'csv'], true), 404);

        // The list filters apply; without them: posted entries from 1 January to today.
        $params = JournalEntryQuery::params($request);
        $from = $params['from'] ?? now()->startOfYear()->toDateString();
        $to = $params['to'] ?? now()->toDateString();
        $drafts = $params['status'] === 'draft';
        $suffix = $drafts ? '-drafts' : '';

        $entries = JournalEntryQuery::filtered([
            ...$params,
            'from' => $from,
            'to' => $to,
            'status' => $drafts ? 'draft' : 'posted',
        ])
            ->with('lines.account')
            ->orderBy('date')
            ->orderBy('created_at')
            ->get();

        $org = $currentOrg->get();

        if ($format === 'csv') {
            $headers = ['Date', 'Reference', 'Description', 'Account Code', 'Account Name', 'Debit', 'Credit', 'Status'];
            $rows = [];
            foreach ($entries as $entry) {
                foreach ($entry->lines as $line) {
                    $rows[] = [
                        $entry->date->format('Y-m-d'),
                        $entry->reference,
                        $entry->description,
                        $line->account->code ?? '',
                        $line->account->name ?? '',
                        (string) $line->debit,
                        (string) $line->credit,
                        $entry->is_posted ? 'posted' : 'draft',
                    ];
                }
            }

            return $csv->export($headers, $rows, "journal-entries-{$from}-{$to}{$suffix}.csv");
        }

        return $pdf->download('exports.journal-entries', [
            'organization' => $org,
            'fromDate' => $from,
            'toDate' => $to,
            'entries' => $entries,
            'statusLabel' => $drafts ? __('exports.journal_entries.drafts') : null,
        ], "journal-entries-{$from}-{$to}{$suffix}.pdf");
    }
}
