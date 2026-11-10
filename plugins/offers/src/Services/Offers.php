<?php

namespace Plugins\Offers\Services;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\CreateInvoiceAction;
use App\Domains\Invoicing\DTOs\CreateInvoiceData;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceNumberGenerator;
use App\Domains\Organizations\Models\Organization;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Plugins\Offers\Models\Offer;
use Plugins\Offers\Models\OfferLine;
use Plugins\Offers\Models\OfferSetting;
use Plugins\Offers\Models\OfferTemplate;
use Plugins\Offers\Support\Layout;
use Plugins\Offers\Support\OfferInvoicing;

/**
 * Offer lifecycle: drafts with lines and totals, status changes, revisions and
 * conversion into a draft invoice. Totals use the invoice arithmetic (amount =
 * qty × price, VAT per line) so the invoice made from an offer has the same total.
 */
class Offers
{
    private const MAX_NUMBER_RETRIES = 5;

    /** Largest amount stored in the decimal(12,2) columns of offers and invoices, with a margin. */
    private const MAX_AMOUNT = '999999999.99';

    /** action => [allowed from, target] */
    private const TRANSITIONS = [
        'send' => [[Offer::STATUS_DRAFT], Offer::STATUS_SENT],
        'revert' => [[Offer::STATUS_SENT], Offer::STATUS_DRAFT],
        'accept' => [[Offer::STATUS_SENT], Offer::STATUS_ACCEPTED],
        'refuse' => [[Offer::STATUS_SENT], Offer::STATUS_REFUSED],
        'reopen' => [[Offer::STATUS_ACCEPTED, Offer::STATUS_REFUSED], Offer::STATUS_SENT],
    ];

    public function __construct(
        private OfferPdf $pdf,
        private CreateInvoiceAction $createInvoice,
        private InvoiceNumberGenerator $invoiceNumbers,
    ) {}

    /**
     * Create a draft, or update one. $data is validated by the controller.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(string $orgId, array $data, ?Offer $offer = null, ?int $userId = null): Offer
    {
        if ($offer !== null && ! $offer->isDraft()) {
            throw ValidationException::withMessages(['status' => __('offers::of.only_draft_editable')]);
        }

        $contact = Contact::query()->where('organization_id', $orgId)->find((int) $data['contact_id']);
        if ($contact === null) {
            throw ValidationException::withMessages(['contact_id' => __('offers::of.contact_not_found')]);
        }
        $person = null;
        if (! empty($data['contact_person_id'])) {
            $person = $contact->contactPersons()->whereKey($data['contact_person_id'])->first();
            if ($person === null) {
                throw ValidationException::withMessages(['contact_person_id' => __('offers::of.person_not_of_contact')]);
            }
        }
        $vat = null;
        if (! empty($data['vat_rate_id'])) {
            $vat = VatRate::query()->where('organization_id', $orgId)->find((int) $data['vat_rate_id']);
            if ($vat === null || (! $vat->is_active && $vat->id !== $offer?->vat_rate_id)) {
                throw ValidationException::withMessages(['vat_rate_id' => __('offers::of.vat_rate_not_found')]);
            }
        }

        $attributes = [
            'contact_id' => $contact->id,
            'contact_person_id' => $person?->id,
            'recipient' => [
                'company' => $contact->name,
                'attention' => $person?->full_name,
                'email' => $person?->email ?: $contact->email,
                'address' => $contact->address,
                'postal_code' => $contact->postal_code,
                'city' => $contact->city,
                'country' => $contact->country ?? 'CH',
                'phone' => $person?->phone ?: $contact->phone,
            ],
            'title' => $data['title'],
            'intro' => $data['intro'] ?? null,
            'closing' => $data['closing'] ?? null,
            'notes' => $data['notes'] ?? null,
            'offer_date' => $data['offer_date'],
            'valid_until' => $data['valid_until'] ?? null,
            'request_date' => $data['request_date'] ?? null,
            'language' => $data['language'],
            'currency' => $data['currency'],
            'vat_rate_id' => $vat?->id,
            'vat_rate' => $vat !== null ? (string) $vat->rate : null,
        ];
        if ($offer === null) {
            $template = empty($data['template_id']) ? null : OfferTemplate::query()->where('organization_id', $orgId)->find((string) $data['template_id']);
            $attributes += ['template_id' => $template?->id, 'layout' => Layout::normalize($template?->layout)];
        }

        $persist = fn (): Offer => DB::transaction(function () use ($orgId, $offer, $attributes, $data, $userId): Offer {
            if ($offer === null) {
                $offer = Offer::create($attributes + [
                    'organization_id' => $orgId,
                    'number' => $this->nextNumber($orgId, Carbon::parse($attributes['offer_date'])->year),
                    'status' => Offer::STATUS_DRAFT,
                    'created_by' => $userId,
                ]);
            } else {
                $offer = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
                if (! $offer->isDraft()) {
                    throw ValidationException::withMessages(['status' => __('offers::of.only_draft_editable')]);
                }
                $offer->update($attributes);
            }
            $this->syncLines($offer, $data['lines']);

            return $offer->fresh(['lines']) ?? $offer;
        });

        return $offer === null ? $this->withNumberRetry($persist) : $persist();
    }

    /**
     * Run $create, again when a concurrent insert took the number it picked.
     *
     * @param  callable(): Offer  $create
     */
    private function withNumberRetry(callable $create): Offer
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $create();
            } catch (UniqueConstraintViolationException $e) {
                if (! str_contains($e->getMessage(), 'of_offers_organization_id_number_unique') || $attempt >= self::MAX_NUMBER_RETRIES) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Next free number {prefix}-{year}-{NNN} for the organisation and year: the highest
     * number of the year plus the organisation's number step (default 1).
     */
    public function nextNumber(string $orgId, int $year): string
    {
        $prefix = config('offers.number_prefix', 'OF')."-{$year}-";
        $max = Offer::query()->withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('number', 'like', $prefix.'%')
            ->pluck('number')
            ->map(fn (string $n): int => (int) substr($n, strlen($prefix)))
            ->max() ?? 0;

        $step = max(1, OfferSetting::for($orgId)->number_step);

        return $prefix.str_pad((string) ($max + $step), 3, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function syncLines(Offer $offer, array $lines): void
    {
        $offer->lines()->delete();
        $rate = $offer->vat_rate;
        $subtotal = '0.00';
        $vatTotal = '0.00';

        foreach (array_values($lines) as $i => $line) {
            $isItem = ($line['type'] ?? OfferLine::TYPE_ITEM) === OfferLine::TYPE_ITEM;
            $quantity = $isItem ? Money::round((string) $line['quantity']) : '0.00';
            $price = $isItem ? Money::round((string) $line['unit_price']) : '0.00';
            $amount = Money::multiply2($quantity, $price);
            $vat = $isItem && $rate !== null ? Money::percentage($amount, (string) $rate) : '0.00';
            if (Money::compare(Money::absoluteAmount($amount), self::MAX_AMOUNT) > 0) {
                throw ValidationException::withMessages(["lines.{$i}.unit_price" => __('offers::of.amount_too_large')]);
            }

            $offer->lines()->create([
                'sort' => $i,
                'type' => $isItem ? OfferLine::TYPE_ITEM : OfferLine::TYPE_TEXT,
                'label' => $line['label'] ?? null,
                'description' => (string) $line['description'],
                'quantity' => $quantity,
                'unit' => $isItem ? ($line['unit'] ?? null) : null,
                'unit_price' => $price,
                'amount' => $amount,
                'vat_amount' => $vat,
            ]);
            $subtotal = Money::add($subtotal, $amount);
            $vatTotal = Money::add($vatTotal, $vat);
        }

        if (Money::compare(Money::absoluteAmount(Money::add($subtotal, $vatTotal)), self::MAX_AMOUNT) > 0) {
            throw ValidationException::withMessages(['lines' => __('offers::of.amount_too_large')]);
        }

        $offer->update([
            'subtotal' => $subtotal,
            'vat_amount' => $vatTotal,
            'total' => Money::add($subtotal, $vatTotal),
        ]);
    }

    /** Apply a status change (send, revert, accept, refuse, reopen). */
    public function transition(Offer $offer, string $action): Offer
    {
        if (! isset(self::TRANSITIONS[$action])) {
            throw ValidationException::withMessages(['status' => __('offers::of.invalid_transition')]);
        }
        [$from, $to] = self::TRANSITIONS[$action];

        $obsoleteDocument = null;
        $newDocument = null;

        try {
            $offer = DB::transaction(function () use ($offer, $action, $from, $to, &$obsoleteDocument, &$newDocument): Offer {
                $offer = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
                if (! in_array($offer->status, $from, true)) {
                    throw ValidationException::withMessages(['status' => __('offers::of.invalid_transition')]);
                }
                if ($action === 'reopen' && $offer->status === Offer::STATUS_ACCEPTED && $offer->hasInvoice()) {
                    throw ValidationException::withMessages(['status' => __('offers::of.already_invoiced')]);
                }

                $changes = ['status' => $to];
                if ($action === 'send') {
                    // A revision replaces its original once the client receives it.
                    // Refused when the original was accepted meanwhile: two live offers otherwise.
                    $original = $offer->supersedes_id !== null
                        ? Offer::query()->whereKey($offer->supersedes_id)->lockForUpdate()->first()
                        : null;
                    if ($original !== null) {
                        if (! in_array($original->status, [Offer::STATUS_SENT, Offer::STATUS_REFUSED], true)) {
                            throw ValidationException::withMessages(['status' => __('offers::of.original_decided', ['number' => $original->number])]);
                        }
                        // decided_at is kept: set means the client had refused it (restored on revert).
                        $original->update(['status' => Offer::STATUS_SUPERSEDED]);
                    }
                    $path = "offers/{$offer->organization_id}/{$offer->id}.pdf";
                    Storage::disk('local')->put($path, $this->pdf->render($offer));
                    $newDocument = $path;
                    $changes += ['sent_at' => now(), 'document_path' => $path, 'document_name' => $offer->number.'.pdf'];
                } elseif ($action === 'revert') {
                    // The original gets its status back (sent, or refused) while its revision is a draft.
                    $original = $offer->supersedes_id !== null
                        ? Offer::query()->whereKey($offer->supersedes_id)->where('status', Offer::STATUS_SUPERSEDED)->lockForUpdate()->first()
                        : null;
                    $original?->update(['status' => $original->decided_at !== null ? Offer::STATUS_REFUSED : Offer::STATUS_SENT]);
                    $obsoleteDocument = $offer->source === 'app' ? $offer->document_path : null;
                    $changes += ['sent_at' => null, 'document_path' => null, 'document_name' => null];
                } elseif ($action === 'accept' || $action === 'refuse') {
                    $changes += ['decided_at' => now()];
                } elseif ($action === 'reopen') {
                    $changes += ['decided_at' => null];
                }
                $offer->update($changes);

                return $offer;
            });
        } catch (\Throwable $e) {
            if ($newDocument !== null) {
                Storage::disk('local')->delete($newDocument);
            }
            throw $e;
        }

        if ($obsoleteDocument !== null) {
            Storage::disk('local')->delete($obsoleteDocument);
        }

        return $offer;
    }

    /**
     * Start a revision of a sent or refused offer: a draft copy with a new number.
     * The original becomes superseded when the revision is sent.
     */
    public function revise(Offer $offer, ?int $userId = null): Offer
    {
        return $this->withNumberRetry(fn (): Offer => DB::transaction(function () use ($offer, $userId): Offer {
            $offer = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if (! in_array($offer->status, [Offer::STATUS_SENT, Offer::STATUS_REFUSED], true)) {
                throw ValidationException::withMessages(['status' => __('offers::of.invalid_transition')]);
            }
            if (Offer::query()->where('supersedes_id', $offer->id)->exists()) {
                throw ValidationException::withMessages(['status' => __('offers::of.revision_exists')]);
            }

            $today = now()->startOfDay();
            $copy = $offer->replicate(['number', 'status', 'sent_at', 'decided_at', 'document_path', 'document_name', 'source', 'external_ref', 'created_by']);
            $copy->fill([
                'number' => $this->nextNumber($offer->organization_id, $today->year),
                'status' => Offer::STATUS_DRAFT,
                'offer_date' => $today->toDateString(),
                'valid_until' => $today->copy()->addMonthsNoOverflow(OfferSetting::for($offer->organization_id)->validity_months)->toDateString(),
                'supersedes_id' => $offer->id,
                'source' => 'app',
                'created_by' => $userId,
            ]);
            $copy->save();
            foreach ($offer->lines as $line) {
                $copy->lines()->create($line->only(['sort', 'type', 'label', 'description', 'quantity', 'unit', 'unit_price', 'amount', 'vat_amount']));
            }

            return $copy;
        }));
    }

    /**
     * What is left to invoice per item line (line amount − amount on invoices that still count).
     *
     * @return array<int, array{amount: string, invoiced: string, remaining: string}> offer line id => figures
     */
    public function balance(Offer $offer): array
    {
        $invoiced = $offer->invoicedByLine();
        $result = [];
        foreach ($offer->lines as $line) {
            if ($line->isItem()) {
                $done = $invoiced[$line->id] ?? '0.00';
                $result[$line->id] = ['amount' => (string) $line->amount, 'invoiced' => $done, 'remaining' => Money::subtract((string) $line->amount, $done)];
            }
        }

        return $result;
    }

    /**
     * Create a draft invoice from chosen lines of an accepted offer. Each chosen line
     * has a net amount (not zero, with the sign of the position; it may exceed what
     * remains) and an invoice text. A line invoiced whole at once keeps its quantity and
     * unit price; otherwise it becomes 1 × amount.
     *
     * @param  array<int, array{line_id: int|string, amount: string, description: string}>  $selection
     */
    public function createInvoice(Offer $offer, array $selection): Invoice
    {
        return DB::transaction(function () use ($offer, $selection): Invoice {
            $offer = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if ($offer->status !== Offer::STATUS_ACCEPTED) {
                throw ValidationException::withMessages(['status' => __('offers::of.invoice_needs_accepted')]);
            }
            if ($selection === []) {
                throw ValidationException::withMessages(['lines' => __('offers::of.invoice_no_lines')]);
            }
            // The invoice takes the rate's current percentage: it must still be the one offered.
            $vatRateId = $this->invoiceVatRateId($offer);
            if ($offer->vat_rate !== null && $vatRateId === null) {
                throw ValidationException::withMessages(['status' => __('offers::of.vat_rate_changed', ['rate' => (string) $offer->vat_rate])]);
            }

            $balance = $this->balance($offer);
            $lines = [];
            $seen = [];
            $net = '0.00';
            foreach (array_values($selection) as $i => $chosen) {
                $line = $offer->lines->firstWhere('id', (int) $chosen['line_id']);
                if ($line === null || ! $line->isItem() || Money::isZero((string) $line->amount) || isset($seen[$line->id])) {
                    throw ValidationException::withMessages(["lines.{$i}.line_id" => __('offers::of.invoice_line_invalid')]);
                }
                // Not zero, and the sign of the offered position (a rebate stays a rebate). More than
                // what remains is allowed: an invoice may exceed the offer (the offer then shows it as over-invoiced).
                $amount = Money::round((string) $chosen['amount']);
                if (Money::isZero($amount) || Money::isNegative($amount) !== Money::isNegative((string) $line->amount)) {
                    throw ValidationException::withMessages(["lines.{$i}.amount" => __('offers::of.invoice_amount_sign')]);
                }
                $whole = Money::isZero($balance[$line->id]['invoiced']) && Money::compare($amount, (string) $line->amount) === 0;
                $lines[] = [
                    'type' => 'item',
                    'description' => (string) $chosen['description'],
                    'quantity' => $whole ? (string) $line->quantity : '1',
                    'unit_price' => $whole ? (string) $line->unit_price : $amount,
                    'vat_rate_id' => $vatRateId,
                    'sort_order' => $i,
                    'source_type' => OfferInvoicing::SOURCE,
                    'source_id' => (string) $line->id,
                ];
                $seen[$line->id] = true;
                $net = Money::add($net, $amount);
            }

            // A rebate alone (or one outweighing the rest) would be a credit note, not an invoice.
            if (! Money::isPositive($net)) {
                throw ValidationException::withMessages(['lines' => __('offers::of.invoice_total_not_positive')]);
            }

            $organization = Organization::query()->findOrFail($offer->organization_id);
            $today = now()->startOfDay();
            $invoice = $this->createInvoice->execute(CreateInvoiceData::fromArray([
                'organization_id' => $offer->organization_id,
                'customer_id' => $offer->contact_id,
                // The offer's "Attention" person; dropped by CreateInvoiceAction if no longer the customer's.
                'contact_person_id' => $offer->contact_person_id,
                'number' => $this->invoiceNumbers->next($offer->organization_id, null, $today->year),
                'issue_date' => $today->toDateString(),
                'due_date' => $today->copy()->addDays($organization->default_payment_terms_days ?? 30)->toDateString(),
                'currency' => $offer->currency,
                'introduction' => $offer->title,
                'notes' => trans('offers::of.invoice_note', ['number' => $offer->number], $offer->language),
                'lines' => $lines,
            ]));

            return $invoice;
        });
    }

    /** The VAT rate to put on invoice lines: the offer's, if its percentage is unchanged. */
    public function invoiceVatRateId(Offer $offer): ?string
    {
        if ($offer->vat_rate === null || $offer->vat_rate_id === null) {
            return null;
        }
        $rate = VatRate::query()->find($offer->vat_rate_id);

        return $rate !== null && Money::compare((string) $rate->rate, (string) $offer->vat_rate) === 0 ? (string) $rate->id : null;
    }

    /**
     * Whether something of the position is left to invoice: a remainder with the
     * sign of the position (a fully or over-invoiced one has none).
     *
     * @param  array{amount: string, invoiced: string, remaining: string}  $balance
     */
    public static function isOpen(array $balance): bool
    {
        return ! Money::isZero($balance['amount']) && ! Money::isZero($balance['remaining'])
            && Money::isNegative($balance['remaining']) === Money::isNegative($balance['amount']);
    }

    /**
     * Prefill of an invoice line from an offer line: the line as offered when nothing
     * was invoiced yet (or nothing is left), otherwise 1 × what remains.
     *
     * @param  array{amount: string, invoiced: string, remaining: string}  $balance
     * @return array{quantity: string, unit_price: string}
     */
    public static function prefill(OfferLine $line, array $balance): array
    {
        return Money::isZero($balance['invoiced']) || ! self::isOpen($balance)
            ? ['quantity' => (string) $line->quantity, 'unit_price' => (string) $line->unit_price]
            : ['quantity' => '1', 'unit_price' => $balance['remaining']];
    }

    /** Default invoice text of an offer line: position, description, unit. */
    public function invoiceDescription(OfferLine $line): string
    {
        $text = trim(($line->label ? $line->label.' ' : '').$line->description);

        return $line->isItem() && $line->unit ? "{$text} ({$line->unit})" : $text;
    }

    /** Store an offer's texts and lines as a new template. */
    public function saveAsTemplate(Offer $offer, string $name): OfferTemplate
    {
        return OfferTemplate::create([
            'organization_id' => $offer->organization_id,
            'name' => $name,
            'title' => $offer->title,
            'intro' => $offer->intro,
            'closing' => $offer->closing,
            'layout' => Layout::normalize($offer->layout),
            'lines' => $offer->lines->map(fn (OfferLine $l): array => [
                'type' => $l->type,
                'label' => $l->label,
                'description' => $l->description,
                'quantity' => $l->isItem() ? (string) $l->quantity : null,
                'unit' => $l->unit,
                'unit_price' => $l->isItem() ? (string) $l->unit_price : null,
            ])->values()->all(),
            'is_default' => false,
        ]);
    }

    /** Delete a draft (drafts have no stored document). */
    public function delete(Offer $offer): void
    {
        DB::transaction(function () use ($offer): void {
            $offer = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if (! $offer->isDraft()) {
                throw ValidationException::withMessages(['status' => __('offers::of.only_draft_deletable')]);
            }
            $offer->delete();
        });
    }
}
