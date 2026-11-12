<?php

namespace App\Domains\Accounting\Controllers;

use App\Domains\Accounting\Actions\CancelJournalCorrectionAction;
use App\Domains\Accounting\Actions\PostJournalCorrectionAction;
use App\Domains\Accounting\Actions\PrepareJournalCorrectionAction;
use App\Domains\Accounting\Actions\UpdateJournalCorrectionReplacementAction;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Models\JournalCorrection;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Rules\NotSystemReference;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Concerns\HandlesFlashErrorResponses;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Guided correction of a posted journal entry (web): start it from the
 * entry's page, edit the replacement, then post or discard both drafts
 * together. The original entry never changes (D70).
 */
class JournalCorrectionController extends Controller
{
    use HandlesFlashErrorResponses;

    public function store(Request $request, JournalEntry $journalEntry, PrepareJournalCorrectionAction $prepare): RedirectResponse
    {
        $check = Gate::inspect('correct', $journalEntry);
        if ($check->denied()) {
            if ($check->message() === null) {
                abort(403);
            }

            return $this->backWithError($check->message());
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'correction_date' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $correction = $prepare->execute($journalEntry, $validated['reason'], $validated['correction_date'], $request->user()?->id);
        } catch (\DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('accounting.journal-entries.show', $correction->replacement_journal_entry_id)
            ->with('success', __('app.journal_correction_prepared'));
    }

    public function updateReplacement(
        Request $request,
        JournalCorrection $journalCorrection,
        UpdateJournalCorrectionReplacementAction $update,
        CurrentOrganization $currentOrg,
    ): RedirectResponse {
        $this->authorize('update', $journalCorrection);

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:100', new NotSystemReference($journalCorrection->replacement->reference)],
            'description' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where('organization_id', $currentOrg->id())->where('is_active', true),
            ],
            'lines.*.debit' => ['required', 'numeric', 'min:0', 'max:99999999999.99'],
            'lines.*.credit' => ['required', 'numeric', 'min:0', 'max:99999999999.99'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $data = new JournalEntryData(
            date: $validated['date'],
            reference: $validated['reference'] ?? null,
            description: $validated['description'] ?? null,
            lines: array_map(fn (array $line): JournalLineData => new JournalLineData(
                accountId: (string) $line['account_id'],
                debit: (string) $line['debit'],
                credit: (string) $line['credit'],
                description: $line['description'] ?? null,
            ), $validated['lines']),
        );

        try {
            $update->execute($journalCorrection, $data);
        } catch (\DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('accounting.journal-entries.show', $journalCorrection->replacement_journal_entry_id)
            ->with('success', __('app.journal_entry_updated'));
    }

    public function post(JournalCorrection $journalCorrection, PostJournalCorrectionAction $post): RedirectResponse
    {
        $this->authorize('update', $journalCorrection);

        try {
            $post->execute($journalCorrection);
        } catch (\DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('accounting.journal-entries.show', $journalCorrection->replacement_journal_entry_id)
            ->with('success', __('app.journal_correction_posted'));
    }

    public function destroy(JournalCorrection $journalCorrection, CancelJournalCorrectionAction $cancel): RedirectResponse
    {
        $this->authorize('delete', $journalCorrection);
        $originalId = $journalCorrection->original_journal_entry_id;

        try {
            $cancel->execute($journalCorrection);
        } catch (\DomainException $e) {
            return $this->backWithError($e);
        }

        return redirect()->route('accounting.journal-entries.show', $originalId)
            ->with('success', __('app.journal_correction_cancelled'));
    }
}
