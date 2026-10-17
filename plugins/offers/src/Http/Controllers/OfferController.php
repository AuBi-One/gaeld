<?php

namespace Plugins\Offers\Http\Controllers;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\ContactPerson;
use App\Domains\Organizations\Models\Organization;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Response;
use Plugins\Offers\Models\Offer;
use Plugins\Offers\Models\OfferLine;
use Plugins\Offers\Models\OfferSetting;
use Plugins\Offers\Models\OfferTemplate;
use Plugins\Offers\Services\OfferLineSource;
use Plugins\Offers\Services\OfferPdf;
use Plugins\Offers\Services\Offers;
use Plugins\Offers\Support\OfferInvoicing;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class OfferController extends PluginController
{
    /** Status filter values: the stored statuses plus "expired" (sent, past validity). */
    private const FILTERS = [...Offer::STATUSES, 'expired'];

    public function __construct(private Offers $offers, private OfferPdf $pdf) {}

    public function index(Request $request): Response
    {
        $this->authorizeView();
        $status = $request->string('status')->toString();
        $contactId = $request->string('contact')->toString();
        $year = $request->string('year')->toString();
        $today = now()->toDateString();

        $offers = Offer::query()
            ->with(['contact:id,uuid,name'])
            ->when(in_array($status, Offer::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->when($status === 'expired', fn ($q) => $q->where('status', Offer::STATUS_SENT)->where('valid_until', '<', $today))
            ->when(ctype_digit($contactId), fn ($q) => $q->where('contact_id', (int) $contactId))
            ->when(preg_match('/^\d{4}$/', $year) === 1, fn ($q) => $q->whereYear('offer_date', (int) $year))
            ->orderByDesc('offer_date')
            ->orderByDesc('number')
            ->paginate(25)
            ->withQueryString();
        $invoicing = OfferInvoicing::perOffer(Offer::query()->whereKey($offers->getCollection()->pluck('id')->all()));
        $offers->through(fn (Offer $o): array => [
            'id' => $o->id,
            'number' => $o->number,
            'offer_date' => $o->offer_date->toDateString(),
            'valid_until' => $o->valid_until?->toDateString(),
            'contact' => $o->contact->name ?? ($o->recipient['company'] ?? ''),
            'attention' => $o->recipient['attention'] ?? null,
            'title' => $o->title,
            'status' => $o->isExpired() ? 'expired' : $o->status,
            'total' => (string) $o->total,
            'currency' => $o->currency,
            'invoicing' => $this->invoicingState($invoicing->get($o->id)),
        ]);

        $open = Offer::query()->where('status', Offer::STATUS_SENT)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today));
        // Accepted offers with at least one line left to invoice (net remaining).
        $left = OfferInvoicing::perOffer(Offer::query()->where('status', Offer::STATUS_ACCEPTED))
            ->filter(fn (object $row): bool => (int) $row->open_lines > 0);

        return $this->page('Offers/Index', [
            'offers' => $offers,
            'filters' => ['status' => $status, 'contact' => $contactId, 'year' => $year],
            'statuses' => self::FILTERS,
            'contacts' => Contact::query()->whereIn('id', Offer::query()->select('contact_id'))->orderBy('name')->get(['id', 'name']),
            'years' => Offer::query()->selectRaw('DISTINCT EXTRACT(YEAR FROM offer_date)::int AS y')->orderByDesc('y')->pluck('y'),
            'stats' => [
                'open' => ['count' => (clone $open)->count(), 'total' => (string) (clone $open)->sum('total')],
                'to_invoice' => ['count' => $left->count(), 'total' => self::sum($left->map(fn (object $row): string => number_format((float) $row->remaining, 2, '.', ''))->all())],
                'drafts' => Offer::query()->where('status', Offer::STATUS_DRAFT)->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeWrite();
        $templateId = $request->string('template')->toString();
        $template = Str::isUuid($templateId)
            ? OfferTemplate::query()->find($templateId)
            : OfferTemplate::query()->where('is_default', true)->first();

        return $this->page('Offers/Form', $this->formProps(null) + [
            'initialTemplateId' => $template?->id,
            'initialContactId' => ctype_digit($request->string('contact')->toString()) ? (int) $request->string('contact')->toString() : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite();
        $offer = $this->offers->saveDraft($this->orgId(), $this->validated($request), null, $request->user()?->id);
        if ($request->boolean('send')) {
            $this->offers->transition($offer, 'send');
        }

        return redirect("/offers/{$offer->id}")->with('success', __('offers::of.saved'));
    }

    public function show(Offer $offer): Response
    {
        $this->authorizeView();
        $offer->load(['lines', 'contact', 'contactPerson', 'supersedes:id,number', 'supersededBy:id,number,supersedes_id']);
        $balance = $this->offers->balance($offer);

        return $this->page('Offers/Show', [
            'offer' => $this->present($offer) + [
                'balance' => $balance,
                'invoiced' => self::sum(array_column($balance, 'invoiced')),
                // What is left over the open positions (over-invoiced ones do not reduce it)
                'remaining' => self::sum(array_map(fn (array $b): string => Offers::isOpen($b) ? $b['remaining'] : '0.00', $balance)),
                'invoices' => OfferInvoicing::invoices($offer)->map(fn (object $i): array => [
                    'id' => $i->id,
                    'number' => $i->number,
                    'status' => $i->status,
                    'issue_date' => substr((string) $i->issue_date, 0, 10),
                    'total' => number_format((float) $i->total, 2, '.', ''),
                    'net_from_offer' => number_format((float) $i->net_from_offer, 2, '.', ''),
                ])->values(),
                'can_invoice' => $offer->status === Offer::STATUS_ACCEPTED, // an invoice may exceed the offer
                'contact_uuid' => $offer->contact?->uuid,
                'supersedes' => $offer->supersedes?->only(['id', 'number']),
                'superseded_by' => $offer->supersededBy?->only(['id', 'number']),
                'has_document' => $offer->document_path !== null,
                // The stored document's kind drives the preview (PDF only) and the download button
                'document_ext' => strtoupper($this->documentExtension($offer)),
                'expired' => $offer->isExpired(),
                'sent_at' => $offer->sent_at?->toIso8601String(),
                'decided_at' => $offer->decided_at?->toIso8601String(),
                'source' => $offer->source,
            ],
        ]);
    }

    public function edit(Offer $offer): Response|RedirectResponse
    {
        $this->authorizeWrite();
        if (! $offer->isDraft()) {
            return redirect("/offers/{$offer->id}")->with('error', __('offers::of.only_draft_editable'));
        }

        return $this->page('Offers/Form', $this->formProps($offer->load('lines')));
    }

    public function update(Request $request, Offer $offer): RedirectResponse
    {
        $this->authorizeWrite();
        $this->offers->saveDraft($this->orgId(), $this->validated($request), $offer);

        return redirect("/offers/{$offer->id}")->with('success', __('offers::of.saved'));
    }

    public function destroy(Offer $offer): RedirectResponse
    {
        $this->authorizeDelete();
        $this->offers->delete($offer);

        return redirect('/offers')->with('success', __('offers::of.deleted'));
    }

    public function transition(Offer $offer, string $action): RedirectResponse
    {
        $this->authorizeWrite();
        $this->offers->transition($offer, $action);

        return back()->with('success', __('offers::of.status_changed'));
    }

    public function revise(Request $request, Offer $offer): RedirectResponse
    {
        $this->authorizeWrite();
        $copy = $this->offers->revise($offer, $request->user()?->id);

        return redirect("/offers/{$copy->id}/edit")->with('success', __('offers::of.revision_created', ['number' => $copy->number]));
    }

    /**
     * "Add line from offer" on the core invoice form: the positions of the client's accepted
     * offers with what was invoiced and what remains (JSON for InvoiceLineSourcePicker.vue).
     */
    public function lineSource(Request $request): JsonResponse
    {
        $this->authorizeWrite();
        $customerId = $request->string('customer_id')->toString();
        $offers = ctype_digit($customerId)
            ? Offer::query()->with('lines')->where('status', Offer::STATUS_ACCEPTED)->where('contact_id', (int) $customerId)
                ->orderByDesc('offer_date')->orderByDesc('number')->limit(50)->get()
            : collect();

        $groups = [];
        foreach ($offers as $offer) {
            $balance = $this->offers->balance($offer);
            $vatRateId = $this->offers->invoiceVatRateId($offer);
            if ($offer->vat_rate !== null && $vatRateId === null) {
                continue; // VAT rate changed since the offer: invoiced from the offer page only (refused there)
            }
            $options = [];
            // Every position, with what was invoiced and what remains; fully (or over-) invoiced
            // ones are marked complete, which the picker can hide.
            foreach ($offer->lines->filter(fn (OfferLine $l): bool => $l->isItem()) as $line) {
                $b = $balance[$line->id];
                $options[] = [
                    'source_id' => (string) $line->id,
                    'label' => __('offers::of.source_option', [
                        'text' => $this->offers->invoiceDescription($line),
                        'amount' => OfferPdf::money($b['amount']),
                        'invoiced' => OfferPdf::money($b['invoiced']),
                        'remaining' => OfferPdf::money($b['remaining']),
                    ]),
                    'complete' => ! Offers::isOpen($b),
                    'reference' => OfferLineSource::reference($offer->number, $line),
                    // Prefills the invoice introduction when it is still empty (the offer's subject)
                    'introduction' => $offer->title,
                    'line' => [
                        'type' => 'item',
                        'description' => $offer->number.' · '.$this->offers->invoiceDescription($line),
                        'vat_rate_id' => $vatRateId,
                    ] + Offers::prefill($line, $b),
                ];
            }
            if ($options !== []) {
                $groups[] = ['label' => "{$offer->number} — {$offer->title}", 'options' => $options];
            }
        }

        return response()->json([
            'title' => __('offers::of.add_line_from_offer'),
            'empty' => __('offers::of.source_empty'),
            'hide_complete_label' => __('offers::of.hide_fully_invoiced'),
            'groups' => $groups,
        ]);
    }

    /** Choose the offer lines (and amounts, texts) for a new invoice. */
    public function invoiceForm(Offer $offer): Response|RedirectResponse
    {
        $this->authorizeWrite();
        if ($offer->status !== Offer::STATUS_ACCEPTED) {
            return redirect("/offers/{$offer->id}")->with('error', __('offers::of.invoice_needs_accepted'));
        }
        $offer->load('lines');
        $balance = $this->offers->balance($offer);

        return $this->page('Offers/Invoice', [
            'offer' => ['id' => $offer->id, 'number' => $offer->number, 'title' => $offer->title, 'currency' => $offer->currency, 'vat_rate' => $offer->vat_rate],
            'lines' => $offer->lines->filter(fn (OfferLine $l): bool => $l->isItem())->map(fn (OfferLine $l): array => [
                'id' => $l->id,
                'label' => $l->label,
                'description' => $l->description,
                'quantity' => (string) $l->quantity,
                'unit' => $l->unit,
                'unit_price' => (string) $l->unit_price,
                'invoice_text' => $this->offers->invoiceDescription($l),
            ] + $balance[$l->id])->values(),
        ]);
    }

    public function invoice(Request $request, Offer $offer): RedirectResponse
    {
        $this->authorizeWrite();
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.amount' => ['required', 'numeric', 'regex:/^-?\d{1,9}(\.\d{1,2})?$/'],
            'lines.*.description' => ['required', 'string', 'max:5000'],
        ]);
        $invoice = $this->offers->createInvoice($offer, $data['lines']);

        return redirect("/invoices/{$invoice->id}")->with('success', __('offers::of.invoice_created', ['number' => $invoice->number]));
    }

    public function saveAsTemplate(Request $request, Offer $offer): RedirectResponse
    {
        $this->authorizeWrite();
        $name = $request->validate(['name' => ['required', 'string', 'max:255']])['name'];
        $template = $this->offers->saveAsTemplate($offer->load('lines'), $name);

        return redirect("/offer-templates/{$template->id}/edit")->with('success', __('offers::of.template_saved'));
    }

    /**
     * The stored document once sent (or migrated), otherwise the document drawn now.
     * Only PDFs are shown inline; anything else (e.g. a migrated .docx) is always a download.
     */
    public function document(Request $request, Offer $offer): HttpResponse
    {
        $this->authorizeView();
        $disposition = $request->boolean('download') || $this->documentExtension($offer) !== 'pdf' ? 'attachment' : 'inline';

        if ($offer->document_path !== null && Storage::disk('local')->exists($offer->document_path)) {
            $name = $offer->document_name ?? basename($offer->document_path);

            return Storage::disk('local')->response($offer->document_path, $name, [], $disposition);
        }

        return response($this->pdf->render($offer), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$offer->number.'.pdf"',
        ]);
    }

    /**
     * Extension of the stored document (lower case), `pdf` for the one drawn on demand
     * (also when the stored file is missing: the document is then drawn again).
     */
    private function documentExtension(Offer $offer): string
    {
        if ($offer->document_path === null || ! Storage::disk('local')->exists($offer->document_path)) {
            return 'pdf';
        }
        foreach ([$offer->document_name, $offer->document_path] as $name) {
            $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
            if ($ext !== '') {
                return $ext;
            }
        }

        return 'pdf';
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'contact_id' => ['required', 'integer'],
            'contact_person_id' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'intro' => ['nullable', 'string', 'max:20000'],
            'closing' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'offer_date' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:offer_date'],
            'request_date' => ['nullable', 'date_format:Y-m-d'],
            'language' => ['required', 'in:fr,de,it,en'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'vat_rate_id' => ['nullable', 'integer'],
            'template_id' => ['nullable', 'uuid'],
            ...$this->lineRules(1),
        ]);
    }

    /** @return array<string, mixed> */
    private function formProps(?Offer $offer): array
    {
        $organization = Organization::query()->findOrFail($this->orgId());

        return [
            'offer' => $offer ? $this->present($offer) : null,
            'contacts' => Contact::query()->with('contactPersons')->orderBy('name')->get()
                ->map(fn (Contact $c): array => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'city' => $c->city,
                    'persons' => $c->contactPersons
                        ->sortByDesc('is_primary')
                        ->map(fn (ContactPerson $p): array => ['id' => $p->id, 'name' => $p->full_name, 'email' => $p->email, 'is_primary' => (bool) $p->is_primary])
                        ->values()
                        ->all(),
                ]),
            'vatRates' => VatRate::query()
                ->where(fn ($q) => $q->where('is_active', true)->when($offer?->vat_rate_id, fn ($q, $id) => $q->orWhere('id', $id)))
                ->orderBy('rate')->get(['id', 'name', 'rate', 'is_default']),
            'templates' => OfferTemplate::query()->orderBy('name')->get(['id', 'name', 'title', 'intro', 'closing', 'lines', 'is_default']),
            'defaults' => [
                'language' => in_array($organization->locale, ['fr', 'de', 'it', 'en'], true) ? $organization->locale : 'fr',
                'currency' => $organization->currency ?: 'CHF',
                'validity_months' => OfferSetting::for($this->orgId())->validity_months,
                // Fixed texts of the Word offer model, as a starting point (editable).
                'closing' => (string) trans('offers::of.default_closing', [], in_array($organization->locale, ['fr', 'de', 'it', 'en'], true) ? $organization->locale : 'fr'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function present(Offer $offer): array
    {
        return [
            'id' => $offer->id,
            'number' => $offer->number,
            'status' => $offer->status,
            'contact_id' => $offer->contact_id,
            'contact_person_id' => $offer->contact_person_id,
            'recipient' => $offer->recipient,
            'title' => $offer->title,
            'intro' => $offer->intro,
            'closing' => $offer->closing,
            'notes' => $offer->notes,
            'offer_date' => $offer->offer_date->toDateString(),
            'valid_until' => $offer->valid_until?->toDateString(),
            'request_date' => $offer->request_date?->toDateString(),
            'language' => $offer->language,
            'currency' => $offer->currency,
            'vat_rate_id' => $offer->vat_rate_id,
            'vat_rate' => $offer->vat_rate,
            'template_id' => $offer->template_id,
            'subtotal' => (string) $offer->subtotal,
            'vat_amount' => (string) $offer->vat_amount,
            'total' => (string) $offer->total,
            'lines' => $offer->lines->map(fn (OfferLine $l): array => [
                'id' => $l->id,
                'type' => $l->type,
                'label' => $l->label,
                'description' => $l->description,
                'quantity' => $l->isItem() ? (string) $l->quantity : '',
                'unit' => $l->unit,
                'unit_price' => $l->isItem() ? (string) $l->unit_price : '',
                'amount' => (string) $l->amount,
            ])->values(),
        ];
    }

    /** @param  array<int|string, string>  $amounts */
    private static function sum(array $amounts): string
    {
        return array_reduce($amounts, fn (string $carry, string $amount): string => Money::add($carry, $amount), '0.00');
    }

    /**
     * none (nothing invoiced), full (no line left) or partial.
     *
     * @param  object{invoiced: string, open_lines: int}|null  $row
     */
    private function invoicingState(?object $row): string
    {
        if ($row === null || Money::isZero(number_format((float) $row->invoiced, 2, '.', ''))) {
            return 'none';
        }

        return (int) $row->open_lines === 0 ? 'full' : 'partial';
    }
}
