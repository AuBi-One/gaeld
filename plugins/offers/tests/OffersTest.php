<?php

namespace Plugins\Offers\Tests;

require_once __DIR__.'/OffersTestCase.php';

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\CreateInvoiceAction;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceNumberGenerator;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use App\Support\Plugins\PluginNavigation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Offers\Models\Offer;
use Plugins\Offers\Models\OfferTemplate;
use Plugins\Offers\Services\OfferContactPanel;
use Plugins\Offers\Services\OfferPdf;
use Plugins\Offers\Services\Offers;
use Plugins\Offers\Support\Layout;

class OffersTest extends OffersTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'contact_id' => $this->contact->id,
            'contact_person_id' => $this->person->id,
            'title' => 'Analyse des processus',
            'intro' => "Madame,\n\nSuite à votre demande, voici notre offre pour **{company}**.",
            'closing' => 'Offre {number}, valable jusqu’au {valid_until}.',
            'offer_date' => '2026-03-02',
            'valid_until' => '2026-04-01',
            'language' => 'fr',
            'currency' => 'CHF',
            'vat_rate_id' => $this->vat->id,
            'lines' => [
                ['type' => 'text', 'label' => null, 'description' => 'Phase 1'],
                ['type' => 'item', 'label' => '1', 'description' => 'Atelier', 'quantity' => '2', 'unit' => 'jour', 'unit_price' => '1200'],
                ['type' => 'item', 'label' => '2', 'description' => 'Rapport', 'quantity' => '1.5', 'unit' => 'heure', 'unit_price' => '33.33'],
            ],
        ], $overrides);
    }

    private function offer(array $overrides = []): Offer
    {
        return app(Offers::class)->saveDraft($this->org->id, $this->payload($overrides));
    }

    private function sent(array $overrides = []): Offer
    {
        return app(Offers::class)->transition($this->offer($overrides), 'send');
    }

    #[Test]
    public function totals_and_vat_follow_the_invoice_arithmetic(): void
    {
        $offer = $this->offer();

        // 2 × 1200 = 2400.00 (VAT 194.40); 1.5 × 33.33 = 49.995 → 49.99 (VAT 4.05)
        $this->assertSame('2449.99', (string) $offer->subtotal);
        $this->assertSame('198.45', (string) $offer->vat_amount);
        $this->assertSame('2648.44', (string) $offer->total);
        $this->assertSame('8.10', (string) $offer->vat_rate);
        $this->assertCount(3, $offer->lines);
        $this->assertSame('0.00', (string) $offer->lines[0]->amount);
        $this->assertSame(['company' => 'Salines Test SA', 'attention' => 'Marie Exemple', 'email' => 'marie@example.test', 'address' => 'Route des Mines 1', 'postal_code' => '1880', 'city' => 'Bex', 'country' => 'CH', 'phone' => null], $offer->recipient);
    }

    #[Test]
    public function numbers_are_sequential_per_year_of_the_offer_date(): void
    {
        $this->assertSame('OF-2026-001', $this->offer()->number);
        $this->assertSame('OF-2026-002', $this->offer()->number);
        $this->assertSame('OF-2025-001', $this->offer(['offer_date' => '2025-12-01', 'valid_until' => null])->number);
        Offer::query()->create([
            'organization_id' => $this->org->id, 'number' => '2026012', 'contact_id' => $this->contact->id,
            'title' => 'Migrated', 'offer_date' => '2026-01-10', 'source' => 'airtable',
        ]);
        $this->assertSame('OF-2026-003', $this->offer()->number);
    }

    #[Test]
    public function the_contact_person_must_belong_to_the_client(): void
    {
        $other = Contact::factory()->create(['organization_id' => $this->org->id]);
        $stranger = $other->contactPersons()->create(['first_name' => 'Paul', 'last_name' => 'Autre']);

        $this->expectException(ValidationException::class);
        $this->offer(['contact_person_id' => $stranger->id]);
    }

    #[Test]
    public function store_validates_and_redirects_to_the_offer(): void
    {
        $this->actAsOrg()->post('/offers', $this->payload(['lines' => []]))->assertSessionHasErrors('lines');
        $this->actAsOrg()->post('/offers', $this->payload(['lines' => [['type' => 'item', 'description' => 'X', 'quantity' => '1e3', 'unit_price' => '1']]]))
            ->assertSessionHasErrors('lines.0.quantity');
        $this->actAsOrg()->post('/offers', $this->payload(['valid_until' => '2026-01-01']))->assertSessionHasErrors('valid_until');

        $response = $this->actAsOrg()->post('/offers', $this->payload());
        $offer = Offer::query()->firstOrFail();
        $response->assertRedirect("/offers/{$offer->id}");
        $this->assertSame($this->user->id, $offer->created_by);

        $this->actAsOrg()->get('/offers')->assertOk()->assertInertia(fn ($page) => $page->component('Offers/Index', false)->where('offers.data.0.number', 'OF-2026-001'));
        $this->actAsOrg()->get("/offers/{$offer->id}")->assertOk()->assertInertia(fn ($page) => $page->component('Offers/Show', false));
        $this->actAsOrg()->get("/offers/{$offer->id}/edit")->assertOk()->assertInertia(fn ($page) => $page->component('Offers/Form', false));
    }

    #[Test]
    public function offers_of_another_organisation_are_not_found(): void
    {
        $offer = $this->offer();
        $otherOrg = Organization::factory()->create();
        $offer->forceFill(['organization_id' => $otherOrg->id])->saveQuietly();

        $this->actAsOrg()->get("/offers/{$offer->id}")->assertNotFound();
        $this->actAsOrg()->get("/offers/{$offer->id}/document")->assertNotFound();
        $this->actAsOrg()->post("/offers/{$offer->id}/send")->assertNotFound();
    }

    #[Test]
    public function only_drafts_can_be_edited_or_deleted(): void
    {
        $offer = $this->sent();

        $this->actAsOrg()->put("/offers/{$offer->id}", $this->payload(['title' => 'Changed']))->assertSessionHasErrors('status');
        $this->actAsOrg()->delete("/offers/{$offer->id}")->assertSessionHasErrors('status');
        $this->actAsOrg()->get("/offers/{$offer->id}/edit")->assertRedirect("/offers/{$offer->id}");
        $this->assertSame('Analyse des processus', $offer->fresh()->title);

        $draft = $this->offer();
        $this->actAsOrg()->delete("/offers/{$draft->id}")->assertRedirect('/offers');
        $this->assertNull(Offer::query()->find($draft->id));
    }

    #[Test]
    public function invalid_status_changes_are_refused(): void
    {
        $offer = $this->offer();
        foreach (['accept', 'refuse', 'revert', 'reopen'] as $action) {
            $this->actAsOrg()->post("/offers/{$offer->id}/{$action}")->assertSessionHasErrors('status');
        }
        $this->actAsOrg()->post("/offers/{$offer->id}/invoice", ['lines' => $this->whole($offer)])->assertSessionHasErrors('status');
        $this->actAsOrg()->get("/offers/{$offer->id}/invoice")->assertRedirect("/offers/{$offer->id}");
        $this->actAsOrg()->post("/offers/{$offer->id}/revise")->assertSessionHasErrors('status');
        $this->actAsOrg()->post("/offers/{$offer->id}/delete")->assertNotFound();
        $this->assertSame(Offer::STATUS_DRAFT, $offer->fresh()->status);
    }

    #[Test]
    public function sending_stores_the_document_and_reverting_discards_it(): void
    {
        Storage::fake('local');
        $offer = $this->offer();

        $this->actAsOrg()->post("/offers/{$offer->id}/send")->assertSessionHasNoErrors();
        $offer->refresh();
        $this->assertSame(Offer::STATUS_SENT, $offer->status);
        $this->assertNotNull($offer->sent_at);
        Storage::disk('local')->assertExists((string) $offer->document_path);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('local')->get((string) $offer->document_path));

        $response = $this->actAsOrg()->get("/offers/{$offer->id}/document?download=1");
        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('OF-2026-001.pdf', (string) $response->headers->get('Content-Disposition'));

        $path = (string) $offer->document_path;
        $this->actAsOrg()->post("/offers/{$offer->id}/revert")->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($offer->fresh()->document_path);
        $this->assertNull($offer->fresh()->sent_at);
    }

    #[Test]
    public function the_draft_document_is_drawn_on_demand(): void
    {
        $offer = $this->offer();
        $response = $this->actAsOrg()->get("/offers/{$offer->id}/document");
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    #[Test]
    public function the_document_fills_placeholders_and_escapes_html(): void
    {
        $offer = $this->offer([
            'intro' => "Pour **{company}**, à l’attention de {attention}.\n\n<script>alert(1)</script>\n\n[x](javascript:alert) [y](https://example.test)",
            'lines' => [['type' => 'item', 'label' => '1', 'description' => '<b>Atelier</b>', 'quantity' => '1', 'unit' => 'jour', 'unit_price' => '1000']],
        ]);
        $html = app(OfferPdf::class)->html($offer);

        $this->assertStringContainsString('<strong>Salines Test SA</strong>', $html);
        $this->assertStringContainsString('Marie Exemple', $html);
        $this->assertStringContainsString('Offre OF-2026-001, valable jusqu’au 01.04.2026.', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertStringContainsString('href="https://example.test"', $html);
        $this->assertStringNotContainsString('<b>Atelier</b>', $html);
        $this->assertStringContainsString('1&#039;000.00', $html); // Swiss thousands separator, HTML-escaped
        $this->assertStringContainsString('8.1 %', $html);
        $this->assertStringContainsString('Total TVA incluse', $html);
    }

    #[Test]
    public function accept_refuse_and_reopen_are_tracked(): void
    {
        $offer = $this->sent();
        app(Offers::class)->transition($offer, 'refuse');
        $this->assertSame(Offer::STATUS_REFUSED, $offer->fresh()->status);
        $this->assertNotNull($offer->fresh()->decided_at);

        app(Offers::class)->transition($offer, 'reopen');
        $this->assertSame(Offer::STATUS_SENT, $offer->fresh()->status);
        $this->assertNull($offer->fresh()->decided_at);

        app(Offers::class)->transition($offer, 'accept');
        $this->assertSame(Offer::STATUS_ACCEPTED, $offer->fresh()->status);
    }

    #[Test]
    public function a_revision_is_a_new_draft_that_supersedes_the_original_once_sent(): void
    {
        $original = $this->sent();

        $this->actAsOrg()->post("/offers/{$original->id}/revise")->assertSessionHasNoErrors();
        $revision = Offer::query()->where('supersedes_id', $original->id)->firstOrFail();
        $this->assertSame(Offer::STATUS_DRAFT, $revision->status);
        $this->assertNotSame($original->number, $revision->number);
        $this->assertCount(3, $revision->lines);
        $this->assertSame((string) $original->total, (string) $revision->total);
        $this->assertSame(Offer::STATUS_SENT, $original->fresh()->status);

        // one open revision at a time
        $this->actAsOrg()->post("/offers/{$original->id}/revise")->assertSessionHasErrors('status');

        app(Offers::class)->transition($revision, 'send');
        $this->assertSame(Offer::STATUS_SUPERSEDED, $original->fresh()->status);
        $this->actAsOrg()->post("/offers/{$original->id}/accept")->assertSessionHasErrors('status');
    }

    /** Every item line of the offer, whole, with its default invoice text. @return list<array<string, mixed>> */
    private function whole(Offer $offer): array
    {
        return $offer->lines()->where('type', 'item')->get()->map(fn ($l): array => [
            'line_id' => $l->id, 'amount' => (string) $l->amount, 'description' => app(Offers::class)->invoiceDescription($l),
        ])->values()->all();
    }

    #[Test]
    public function an_accepted_offer_invoiced_whole_gives_the_same_totals(): void
    {
        $offer = app(Offers::class)->transition($this->sent(), 'accept');
        $this->actAsOrg()->get("/offers/{$offer->id}/invoice")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Offers/Invoice', false)->has('lines', 2)->where('lines.0.remaining', '2400.00')->where('lines.0.invoice_text', '1 Atelier (jour)'));

        $response = $this->actAsOrg()->post("/offers/{$offer->id}/invoice", ['lines' => $this->whole($offer)]);
        $invoice = Invoice::query()->with('lines')->sole();
        $response->assertRedirect("/invoices/{$invoice->id}");

        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame($this->contact->id, $invoice->customer_id);
        $this->assertSame((string) $offer->subtotal, (string) $invoice->subtotal);
        $this->assertSame((string) $offer->vat_amount, (string) $invoice->vat_amount);
        $this->assertSame((string) $offer->total, (string) $invoice->total);
        $this->assertCount(2, $invoice->lines);
        $this->assertSame('1 Atelier (jour)', $invoice->lines[0]->description);
        $this->assertSame('2.00', (string) $invoice->lines[0]->quantity); // whole line: quantity and unit price kept
        $this->assertStringContainsString('OF-2026-001', (string) $invoice->notes);
        $this->assertStringNotContainsString('Analyse des processus', (string) $invoice->notes);
        $this->assertSame('Analyse des processus', $invoice->introduction);

        // nothing left: reopen is refused (an invoice counts); invoicing again stays possible
        $this->actAsOrg()->post("/offers/{$offer->id}/reopen")->assertSessionHasErrors('status');
        $this->actAsOrg()->get('/offers')->assertInertia(fn ($page) => $page->where('offers.data.0.invoicing', 'full')->where('stats.to_invoice.count', 0));
        $this->assertSame(1, Invoice::query()->count());
    }

    #[Test]
    public function an_offer_can_be_invoiced_in_stages(): void
    {
        $offer = app(Offers::class)->transition($this->sent(), 'accept');
        $atelier = $offer->lines()->where('label', '1')->sole();
        $rapport = $offer->lines()->where('label', '2')->sole();

        // deposit: 1800 of the 2400 workshop, own text
        $this->actAsOrg()->post("/offers/{$offer->id}/invoice", ['lines' => [
            ['line_id' => $atelier->id, 'amount' => '1800', 'description' => '1 Atelier - 75% done'],
        ]])->assertSessionHasNoErrors();
        $first = Invoice::query()->with('lines')->sole();
        $this->assertSame('1.00', (string) $first->lines[0]->quantity);
        $this->assertSame('1800.00', (string) $first->lines[0]->unit_price);
        $this->assertSame('1 Atelier - 75% done', $first->lines[0]->description);
        $this->assertSame('1945.80', (string) $first->total);

        $balance = app(Offers::class)->balance($offer->fresh('lines'));
        $this->assertSame('600.00', $balance[$atelier->id]['remaining']);
        $this->actAsOrg()->get("/offers/{$offer->id}")->assertInertia(fn ($page) => $page
            ->where('offer.invoiced', '1800.00')->where('offer.remaining', '649.99')->has('offer.invoices', 1)->where('offer.can_invoice', true));
        $this->actAsOrg()->get('/offers')->assertInertia(fn ($page) => $page->where('offers.data.0.invoicing', 'partial')
            ->where('stats.to_invoice.count', 1)->where('stats.to_invoice.total', '649.99'));

        // zero, wrong sign, a line of another offer, the same line twice: refused
        $otherLine = $this->offer()->lines()->where('type', 'item')->firstOrFail();
        foreach ([
            'lines.0.amount' => [['line_id' => $atelier->id, 'amount' => '0', 'description' => 'x']],
            'lines.0.amount ' => [['line_id' => $atelier->id, 'amount' => '-10', 'description' => 'x']],
            'lines.0.line_id' => [['line_id' => $otherLine->id, 'amount' => '10', 'description' => 'x']],
            'lines.1.line_id' => [['line_id' => $rapport->id, 'amount' => '10', 'description' => 'x'], ['line_id' => $rapport->id, 'amount' => '10', 'description' => 'x']],
        ] as $key => $lines) {
            $this->actAsOrg()->post("/offers/{$offer->id}/invoice", ['lines' => $lines])->assertSessionHasErrors(trim($key));
        }
        $this->assertSame(1, Invoice::query()->count());

        // the rest in a second invoice
        $this->actAsOrg()->post("/offers/{$offer->id}/invoice", ['lines' => [
            ['line_id' => $atelier->id, 'amount' => '600', 'description' => '1 Atelier - solde'],
            ['line_id' => $rapport->id, 'amount' => '49.99', 'description' => '2 Rapport'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(2, Invoice::query()->count());
        $this->actAsOrg()->get("/offers/{$offer->id}")->assertInertia(fn ($page) => $page->where('offer.remaining', '0.00')->where('offer.can_invoice', true));

        // an invoice may exceed the offer: the position is then over-invoiced, the offer counts as invoiced
        $this->actAsOrg()->post("/offers/{$offer->id}/invoice", ['lines' => [['line_id' => $atelier->id, 'amount' => '250', 'description' => 'Extra']]])->assertSessionHasNoErrors();
        $this->assertSame('-250.00', app(Offers::class)->balance($offer->fresh('lines'))[$atelier->id]['remaining']);
        $this->actAsOrg()->get("/offers/{$offer->id}")->assertInertia(fn ($page) => $page->where('offer.remaining', '0.00')->where('offer.invoiced', '2699.99'));
        $this->actAsOrg()->get('/offers?status=accepted')->assertInertia(fn ($page) => $page->where('offers.data.0.invoicing', 'full')->where('stats.to_invoice.count', 0));
        Invoice::query()->orderByDesc('number')->first()->delete(); // the extra invoice, newest number

        // a cancelled or deleted invoice no longer counts
        $first->update(['status' => InvoiceStatus::Cancelled]);
        $this->assertSame('1800.00', app(Offers::class)->balance($offer->fresh('lines'))[$atelier->id]['remaining']);
        Invoice::query()->whereKeyNot($first->id)->sole()->delete();
        $this->assertFalse($offer->fresh()->hasInvoice());
        $this->actAsOrg()->post("/offers/{$offer->id}/reopen")->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_invoice_form_adds_lines_from_offers_and_the_offer_follows_their_edits(): void
    {
        $offer = app(Offers::class)->transition($this->sent(), 'accept');
        $atelier = $offer->lines()->where('label', '1')->sole();
        app(Offers::class)->transition($this->offer(['title' => 'Draft only']), 'send'); // sent, not accepted: not offered

        // the picker lists the accepted offers of the client with what remains
        $this->actAsOrg()->get("/offers/line-source?customer_id={$this->contact->id}")->assertOk()
            ->assertJsonCount(1, 'groups')
            ->assertJsonCount(2, 'groups.0.options')
            ->assertJsonPath('hide_complete_label', __('offers::of.hide_fully_invoiced'))
            ->assertJsonPath('groups.0.options.0.complete', false)
            ->assertJsonPath('groups.0.options.0.source_id', (string) $atelier->id)
            ->assertJsonPath('groups.0.options.0.line.description', 'OF-2026-001 · 1 Atelier (jour)')
            ->assertJsonPath('groups.0.options.0.introduction', 'Analyse des processus')
            ->assertJsonPath('groups.0.options.0.line.quantity', '2.00')
            ->assertJsonPath('groups.0.options.0.line.unit_price', '1200.00')
            ->assertJsonPath('groups.0.options.0.line.vat_rate_id', (string) $this->vat->id);
        $this->actAsOrg()->get('/offers/line-source?customer_id=999999')->assertOk()->assertJsonCount(0, 'groups');
        $otherOrgContact = Contact::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->actAsOrg()->get("/offers/line-source?customer_id={$otherOrgContact->id}")->assertOk()->assertJsonCount(0, 'groups');
        $this->actAsOrg()->get('/invoices/create')->assertInertia(fn ($page) => $page->where('lineSources.0.type', 'offer_line'));

        // a core invoice with a line taken from the offer, amount changed
        $payload = fn (string $price, bool $withLine = true): array => [
            'customer_id' => $this->contact->id, 'issue_date' => '2026-04-01', 'due_date' => '2026-04-30', 'currency' => 'CHF',
            'lines' => array_values(array_filter([
                $withLine ? ['description' => 'OF-2026-001 · 1 Atelier (jour) - 50%', 'quantity' => 1, 'unit_price' => $price, 'vat_rate_id' => $this->vat->id, 'source_type' => 'offer_line', 'source_id' => (string) $atelier->id] : null,
                ['description' => 'Frais', 'quantity' => 1, 'unit_price' => 50],
            ])),
        ];
        $create = $this->actAsOrg()->post('/invoices', $payload('1200'));
        $invoice = Invoice::query()->findOrFail(basename((string) $create->headers->get('Location')));
        $balance = fn (): array => app(Offers::class)->balance($offer->fresh('lines'))[$atelier->id];
        $this->assertSame('1200.00', $balance()['invoiced']);
        $this->actAsOrg()->get("/offers/{$offer->id}")->assertInertia(fn ($page) => $page->where('offer.invoices.0.number', $invoice->number)->where('offer.invoices.0.net_from_offer', '1200.00'));
        $line = $invoice->lines()->where('source_type', 'offer_line')->sole();
        $this->actAsOrg()->get("/invoices/{$invoice->id}")->assertInertia(fn ($page) => $page
            ->where("lineSourceRefs.{$line->id}.label", __('offers::of.source_reference', ['number' => 'OF-2026-001', 'pos' => '1']))
            ->where("lineSourceRefs.{$line->id}.url", "/offers/{$offer->id}"));

        // edited in core: the offer follows
        $this->actAsOrg()->put("/invoices/{$invoice->id}", ['number' => $invoice->number] + $payload('1500'))->assertSessionHasNoErrors();
        $this->assertSame('1500.00', $balance()['invoiced']);
        $this->assertSame('900.00', $balance()['remaining']);

        // the line removed in core: nothing invoiced any more
        $this->actAsOrg()->put("/invoices/{$invoice->id}", ['number' => $invoice->number] + $payload('0', withLine: false))->assertSessionHasNoErrors();
        $this->assertSame('0.00', $balance()['invoiced']);
        $this->assertFalse($offer->fresh()->hasInvoice());

        // fully invoiced positions stay listed, marked complete, prefilled as offered
        $full = $this->actAsOrg()->post('/invoices', $payload('2400'));
        $full->assertSessionHasNoErrors();
        $this->actAsOrg()->get("/offers/line-source?customer_id={$this->contact->id}")
            ->assertJsonCount(2, 'groups.0.options')
            ->assertJsonPath('groups.0.options.0.complete', true)
            ->assertJsonPath('groups.0.options.0.line.quantity', '2.00')
            ->assertJsonPath('groups.0.options.1.complete', false);
        Invoice::query()->findOrFail(basename((string) $full->headers->get('Location')))->delete();

        // a line of another organisation's offer cannot be referenced
        $foreign = Offer::query()->create([
            'organization_id' => Organization::factory()->create()->id, 'number' => 'X-1', 'contact_id' => $this->contact->id,
            'title' => 'Foreign', 'offer_date' => '2026-01-10', 'status' => Offer::STATUS_ACCEPTED,
        ]);
        $foreignLine = $foreign->lines()->create(['type' => 'item', 'description' => 'X', 'quantity' => 1, 'unit_price' => 1, 'amount' => 1]);
        $bad = $payload('10');
        $bad['lines'][0]['source_id'] = (string) $foreignLine->id;
        $this->actAsOrg()->post('/invoices', $bad)->assertSessionHasErrors('lines.0.source_id');

        // an invoice to another client does not count for the offer
        $other = Contact::factory()->create(['organization_id' => $this->org->id]);
        $elsewhere = $payload('500');
        $elsewhere['customer_id'] = $other->id;
        $this->actAsOrg()->post('/invoices', $elsewhere)->assertSessionHasNoErrors();
        $this->assertSame('0.00', $balance()['invoiced']);

        // once the VAT rate of the offer changed, the picker no longer offers it
        $this->vat->update(['rate' => 7.70]);
        $this->actAsOrg()->get("/offers/line-source?customer_id={$this->contact->id}")->assertJsonCount(0, 'groups');
    }

    #[Test]
    public function the_offer_side_invoice_writes_the_same_source_on_invoice_lines(): void
    {
        $offer = app(Offers::class)->transition($this->sent(), 'accept');
        $this->actAsOrg()->post("/offers/{$offer->id}/invoice", ['lines' => $this->whole($offer)])->assertSessionHasNoErrors();
        $sources = Invoice::query()->sole()->lines()->orderBy('sort_order')->get(['source_type', 'source_id'])->toArray();
        $ids = $offer->lines()->where('type', 'item')->orderBy('sort')->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $this->assertSame([['source_type' => 'offer_line', 'source_id' => $ids[0]], ['source_type' => 'offer_line', 'source_id' => $ids[1]]], $sources);
    }

    #[Test]
    public function the_contact_page_lists_the_contacts_offers_with_what_remains(): void
    {
        $accepted = app(Offers::class)->transition($this->sent(), 'accept');
        $atelier = $accepted->lines()->where('label', '1')->sole();
        $this->actAsOrg()->post("/offers/{$accepted->id}/invoice", ['lines' => [
            ['line_id' => $atelier->id, 'amount' => '1800', 'description' => 'Acompte'],
        ]])->assertSessionHasNoErrors();
        $draft = $this->offer(['title' => 'Brouillon']);
        $other = Contact::factory()->create(['organization_id' => $this->org->id]);
        $this->offer(['contact_id' => $other->id, 'contact_person_id' => null]);

        $this->actAsOrg()->get("/contacts/{$this->contact->uuid}")->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('panels', 1)
                ->where('panels.0.key', 'offers')
                ->has('panels.0.rows', 2)
                ->where('panels.0.action.href', "/offers?contact={$this->contact->id}")
                ->where('panels.0.rows.0.cells.number', $draft->number)
                ->where('panels.0.rows.0.cells.remaining', null)
                ->where('panels.0.rows.1.cells.number', $accepted->number)
                ->where('panels.0.rows.1.cells.date', '2026-03-02')
                ->where('panels.0.rows.1.cells.total', '2648.44')
                ->where('panels.0.rows.1.cells.remaining', '649.99')
                ->where('panels.0.rows.1.currency', 'CHF')
                ->where('panels.0.rows.1.href', "/offers/{$accepted->id}"));

        // an expired offer shows as expired; offers of another organisation never appear
        $this->sent(['offer_date' => '2020-01-01', 'valid_until' => '2020-01-31']);
        Offer::query()->create([
            'organization_id' => Organization::factory()->create()->id, 'number' => 'X-9', 'contact_id' => $this->contact->id,
            'title' => 'Foreign', 'offer_date' => '2026-05-01', 'status' => Offer::STATUS_SENT,
        ]);
        $this->actAsOrg()->get("/contacts/{$this->contact->uuid}")
            ->assertInertia(fn ($page) => $page->has('panels.0.rows', 3)->where('panels.0.rows.2.cells.status', __('offers::of.status_expired')));

        // no panel for a user who may not see offers
        $employee = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->org->users()->attach($employee->id, ['role' => 'employee']);
        $this->assignOrganizationRole($employee, $this->org, 'employee');
        $this->assertFalse($employee->hasPermissionTo('invoicing.view'));
        $panel = (new OfferContactPanel);
        $this->actingAs($employee);
        $this->assertNull($panel($this->contact));
    }

    #[Test]
    public function a_stored_word_document_is_downloaded_only_on_request(): void
    {
        Storage::fake('local');
        $offer = $this->offer();
        Storage::disk('local')->put("offers/{$this->org->id}/{$offer->id}.docx", 'PK-not-really-a-docx');
        $offer->update(['document_path' => "offers/{$this->org->id}/{$offer->id}.docx", 'document_name' => '2025012.docx', 'source' => 'airtable']);

        $this->actAsOrg()->get("/offers/{$offer->id}")->assertInertia(fn ($page) => $page->where('offer.document_ext', 'DOCX')->where('offer.has_document', true));
        $inline = $this->actAsOrg()->get("/offers/{$offer->id}/document");
        $inline->assertOk();
        $this->assertStringStartsWith('attachment', (string) $inline->headers->get('Content-Disposition'));
        $this->assertStringContainsString('2025012.docx', (string) $inline->headers->get('Content-Disposition'));

        // a stored PDF is shown inline
        Storage::disk('local')->put("offers/{$this->org->id}/{$offer->id}.pdf", '%PDF-1.4 fake');
        $offer->update(['document_path' => "offers/{$this->org->id}/{$offer->id}.pdf", 'document_name' => '2025017.pdf']);
        $this->actAsOrg()->get("/offers/{$offer->id}")->assertInertia(fn ($page) => $page->where('offer.document_ext', 'PDF'));
        $this->assertStringStartsWith('inline', (string) $this->actAsOrg()->get("/offers/{$offer->id}/document")->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('attachment', (string) $this->actAsOrg()->get("/offers/{$offer->id}/document?download=1")->headers->get('Content-Disposition'));

        // a stored file that went missing: the document is drawn again, as a PDF
        Storage::disk('local')->delete("offers/{$this->org->id}/{$offer->id}.pdf");
        $this->actAsOrg()->get("/offers/{$offer->id}")->assertInertia(fn ($page) => $page->where('offer.document_ext', 'PDF'));
        $this->assertStringStartsWith('%PDF', (string) $this->actAsOrg()->get("/offers/{$offer->id}/document")->getContent());
    }

    #[Test]
    public function the_schema_keeps_one_default_template_per_organisation_and_the_creator_reference(): void
    {
        OfferTemplate::query()->create(['organization_id' => $this->org->id, 'name' => 'A', 'is_default' => true]);
        try {
            // a savepoint, so the refused insert does not abort the test transaction
            DB::transaction(fn () => OfferTemplate::query()->create(['organization_id' => $this->org->id, 'name' => 'B', 'is_default' => true]));
            $this->fail('two default templates were accepted');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }
        // saving through the controller replaces the default instead
        $this->actAsOrg()->post('/offer-templates', ['name' => 'C', 'is_default' => true, 'lines' => []])->assertRedirect('/offer-templates');
        $this->assertSame(['C'], OfferTemplate::query()->where('is_default', true)->pluck('name')->all());

        $offer = app(Offers::class)->saveDraft($this->org->id, $this->payload(), null, $this->user->id);
        $this->assertSame($this->user->id, $offer->created_by);
        $this->user->forceDelete();
        $this->assertNull($offer->fresh()->created_by);
    }

    #[Test]
    public function rebates_are_invoiced_with_positive_lines_only(): void
    {
        $offer = app(Offers::class)->transition($this->sent(['lines' => [
            ['type' => 'item', 'label' => 'A', 'description' => 'Conseil', 'quantity' => '1', 'unit_price' => '1000'],
            ['type' => 'item', 'label' => 'B', 'description' => 'Option', 'quantity' => '1', 'unit_price' => '100'],
            ['type' => 'item', 'label' => 'R', 'description' => 'Rabais', 'quantity' => '1', 'unit_price' => '-100'],
        ]]), 'accept');
        [$a, $b, $r] = $offer->lines()->orderBy('sort')->get()->all();

        // a rebate alone, or one outweighing the rest, would be a credit note
        $this->actAsOrg()->post("/offers/{$offer->id}/invoice", ['lines' => [['line_id' => $r->id, 'amount' => '-100', 'description' => 'R']]])
            ->assertSessionHasErrors('lines');
        $this->actAsOrg()->post("/offers/{$offer->id}/invoice", ['lines' => [
            ['line_id' => $b->id, 'amount' => '50', 'description' => 'B'], ['line_id' => $r->id, 'amount' => '-100', 'description' => 'R'],
        ]])->assertSessionHasErrors('lines');

        // A whole: 1000 of a 1000 net total — still partly invoiced, B and the rebate remain
        $this->actAsOrg()->post("/offers/{$offer->id}/invoice", ['lines' => [['line_id' => $a->id, 'amount' => '1000', 'description' => 'A']]])
            ->assertSessionHasNoErrors();
        $this->actAsOrg()->get('/offers')->assertInertia(fn ($page) => $page->where('offers.data.0.invoicing', 'partial')
            ->where('stats.to_invoice.count', 1)->where('stats.to_invoice.total', '0.00'));

        // B with the rebate: 0 left on every line
        $this->actAsOrg()->post("/offers/{$offer->id}/invoice", ['lines' => [
            ['line_id' => $b->id, 'amount' => '100', 'description' => 'B'], ['line_id' => $r->id, 'amount' => '-50', 'description' => 'R'],
        ]])->assertSessionHasNoErrors();
        $this->actAsOrg()->post("/offers/{$offer->id}/invoice", ['lines' => [['line_id' => $r->id, 'amount' => '-50', 'description' => 'R']]])
            ->assertSessionHasErrors('lines'); // a rebate alone is still no invoice
        $this->assertSame('-50.00', app(Offers::class)->balance($offer->fresh('lines'))[$r->id]['remaining']);
    }

    #[Test]
    public function templates_prefill_new_offers_and_offers_can_become_templates(): void
    {
        $this->actAsOrg()->post('/offer-templates', [
            'name' => 'Conseil', 'title' => 'Mandat de conseil', 'intro' => 'Bonjour {attention}', 'closing' => null,
            'is_default' => true, 'layout' => ['from' => ['logo' => false, 'email' => true], 'to' => ['phone' => true]],
            'lines' => [['type' => 'item', 'label' => '1', 'description' => 'Conseil', 'quantity' => '1', 'unit' => 'jour', 'unit_price' => '1500']],
        ])->assertRedirect('/offer-templates');
        $template = OfferTemplate::query()->firstOrFail();
        $this->assertTrue($template->is_default);

        $this->actAsOrg()->get('/offers/create')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Offers/Form', false)->where('initialTemplateId', $template->id)->has('templates', 1)->has('contacts.0.persons', 1));

        $offer = $this->offer();
        $this->actAsOrg()->post("/offers/{$offer->id}/save-as-template", ['name' => 'Analyse'])->assertSessionHasNoErrors();
        $copy = OfferTemplate::query()->where('name', 'Analyse')->firstOrFail();
        $this->assertCount(3, $copy->lines ?? []);
        $this->assertSame(Layout::DEFAULT, $copy->layout);
        $this->assertSame(['logo' => false, 'address' => true, 'email' => true, 'phone' => true], $template->layout['from']);

        // a new offer from the template copies its layout
        $fromTemplate = $this->offer(['template_id' => $template->id]);
        $this->assertSame($template->id, $fromTemplate->template_id);
        $this->assertTrue($fromTemplate->layout['to']['phone']);
        $this->assertFalse($copy->is_default);

        $this->actAsOrg()->put("/offer-templates/{$copy->id}", ['name' => 'Analyse', 'is_default' => true, 'lines' => []])->assertRedirect('/offer-templates');
        $this->assertFalse($template->fresh()->is_default);
        $this->actAsOrg()->delete("/offer-templates/{$template->id}")->assertRedirect('/offer-templates');
        $this->assertNull(OfferTemplate::query()->find($template->id));
    }

    #[Test]
    public function viewers_can_read_but_not_change_offers(): void
    {
        $offer = $this->offer();
        $viewer = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->org->users()->attach($viewer->id, ['role' => 'viewer']);
        $this->assignOrganizationRole($viewer, $this->org, 'viewer');
        $as = fn () => $this->actingAs($viewer)->withSession(['current_organization_id' => $this->org->id]);

        $as()->get('/offers')->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false));
        $as()->get("/offers/{$offer->id}")->assertOk();
        $as()->get("/offers/{$offer->id}/document")->assertOk();
        $as()->get('/offers/create')->assertForbidden();
        $as()->post('/offers', $this->payload())->assertForbidden();
        $as()->post("/offers/{$offer->id}/send")->assertForbidden();
        $as()->delete("/offers/{$offer->id}")->assertForbidden();
        $as()->post('/offer-templates', ['name' => 'X', 'lines' => []])->assertForbidden();
    }

    #[Test]
    public function the_list_filters_expired_offers_and_by_client(): void
    {
        $this->sent(['offer_date' => '2020-01-01', 'valid_until' => '2020-01-31']);
        $this->sent(['offer_date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString()]);
        $other = Contact::factory()->create(['organization_id' => $this->org->id]);
        $this->offer(['contact_id' => $other->id, 'contact_person_id' => null]);

        $this->actAsOrg()->get('/offers?status=expired')->assertInertia(fn ($page) => $page->has('offers.data', 1)->where('offers.data.0.status', 'expired'));
        $this->actAsOrg()->get('/offers')->assertInertia(fn ($page) => $page->has('offers.data', 3)->where('stats.open.count', 1)->where('stats.drafts', 1)->where('stats.to_invoice.count', 0));
        $this->actAsOrg()->get("/offers?contact={$other->id}")->assertInertia(fn ($page) => $page->has('offers.data', 1));
        $this->actAsOrg()->get('/offers?year=2020')->assertInertia(fn ($page) => $page->has('offers.data', 1));
    }

    #[Test]
    public function the_menu_lists_offers_in_the_activity_section(): void
    {
        $entry = collect(app(PluginNavigation::class)->toArray())->firstWhere('key', 'offers');
        $this->assertSame('after:invoices', $entry['parent']);
        $this->assertSame('FilePen', $entry['icon']);
    }

    #[Test]
    public function validity_comes_from_the_organisation_settings(): void
    {
        $this->actAsOrg()->get('/offers/create')->assertInertia(fn ($page) => $page->where('defaults.validity_months', 2));
        $this->actAsOrg()->put('/offer-templates/settings', ['validity_months' => 25])->assertSessionHasErrors('validity_months');
        $this->actAsOrg()->put('/offer-templates/settings', ['validity_months' => 3])
            ->assertRedirect('/offer-templates');
        $this->actAsOrg()->get('/offers/create')->assertInertia(fn ($page) => $page->where('defaults.validity_months', 3));

        // a revision is valid for the organisation's months from today
        $revision = app(Offers::class)->revise($this->sent());
        $this->assertSame(now()->startOfDay()->addMonthsNoOverflow(3)->toDateString(), $revision->valid_until?->toDateString());

        // the From/To boxes follow the layout: shown by default (D-2026-10-17)...
        $this->org->update(['invoice_email' => 'offres@example.test', 'invoice_phone' => '+41 21 000 00 00', 'vat_number' => 'CHE-123.456.789']);
        $this->person->update(['phone' => '+41 79 000 00 00']);
        $offer = $this->offer();
        $offer->update(['layout' => ['from' => ['email' => true, 'phone' => true, 'address' => false], 'to' => ['phone' => true, 'address' => false]]]);
        $html = app(OfferPdf::class)->html($offer->fresh());
        $this->assertStringContainsString('offres@example.test', $html);
        $this->assertStringContainsString('+41 21 000 00 00', $html);
        $this->assertStringContainsString('+41 79 000 00 00', $html);
        $this->assertStringContainsString('CHE-123.456.789 TVA', $html);
        $this->assertStringNotContainsString('Route des Mines 1', substr($html, (int) strpos($html, 'class="recipient"')));

        // ...but each offer may still turn them off.
        $offer->update(['layout' => ['from' => ['email' => false, 'phone' => false], 'to' => ['phone' => false]]]);
        $html = app(OfferPdf::class)->html($offer->fresh());
        $this->assertStringNotContainsString('offres@example.test', $html);
        $this->assertStringNotContainsString('+41 21 000 00 00', $html);
        $this->assertStringNotContainsString('+41 79 000 00 00', $html);
    }

    #[Test]
    public function members_can_create_offers(): void
    {
        $member = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->org->users()->attach($member->id, ['role' => 'member']);
        $this->assignOrganizationRole($member, $this->org, 'member');

        $this->actingAs($member)->withSession(['current_organization_id' => $this->org->id])
            ->post('/offers', $this->payload())->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Offer::query()->count());
    }

    #[Test]
    public function a_revision_cannot_be_sent_once_the_original_was_accepted(): void
    {
        $original = $this->sent();
        $revision = app(Offers::class)->revise($original);
        app(Offers::class)->transition($original, 'accept');

        $this->actAsOrg()->post("/offers/{$revision->id}/send")->assertSessionHasErrors('status');
        $this->assertSame(Offer::STATUS_DRAFT, $revision->fresh()->status);
        $this->assertSame(Offer::STATUS_ACCEPTED, $original->fresh()->status);
    }

    #[Test]
    public function reverting_a_sent_revision_reopens_the_original(): void
    {
        $original = $this->sent();
        $revision = app(Offers::class)->transition(app(Offers::class)->revise($original), 'send');
        $this->assertSame(Offer::STATUS_SUPERSEDED, $original->fresh()->status);

        app(Offers::class)->transition($revision, 'revert');
        $this->assertSame(Offer::STATUS_SENT, $original->fresh()->status);
        $this->assertNull($original->fresh()->decided_at);

        app(Offers::class)->delete($revision);
        $this->assertSame(Offer::STATUS_SENT, $original->fresh()->status);

        // a refused original comes back as refused
        $refused = app(Offers::class)->transition($this->sent(), 'refuse');
        $revision = app(Offers::class)->transition(app(Offers::class)->revise($refused), 'send');
        $this->assertSame(Offer::STATUS_SUPERSEDED, $refused->fresh()->status);
        app(Offers::class)->transition($revision, 'revert');
        $this->assertSame(Offer::STATUS_REFUSED, $refused->fresh()->status);
    }

    #[Test]
    public function a_draft_keeps_its_vat_rate_after_the_rate_was_deactivated(): void
    {
        $offer = $this->offer();
        $this->vat->update(['is_active' => false]);

        $this->actAsOrg()->put("/offers/{$offer->id}", $this->payload(['title' => 'Changed']))->assertSessionHasNoErrors();
        $this->assertSame('Changed', $offer->fresh()->title);
        $this->actAsOrg()->post('/offers', $this->payload())->assertSessionHasErrors('vat_rate_id');
    }

    #[Test]
    public function conversion_is_refused_when_the_vat_rate_changed_or_was_deleted(): void
    {
        $offer = app(Offers::class)->transition($this->sent(), 'accept');
        $this->vat->update(['rate' => 7.70]);
        $this->actAsOrg()->post("/offers/{$offer->id}/invoice", ['lines' => $this->whole($offer)])->assertSessionHasErrors('status');

        $this->vat->update(['rate' => 8.10]);
        $this->vat->delete();
        $this->actAsOrg()->post("/offers/{$offer->id}/invoice", ['lines' => $this->whole($offer)])->assertSessionHasErrors('status');
        $this->assertSame(0, Invoice::query()->count());
    }

    #[Test]
    public function ids_of_another_organisation_in_the_payload_are_refused_or_ignored(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignContact = Contact::factory()->create(['organization_id' => $otherOrg->id]);
        $foreignVat = VatRate::factory()->create(['organization_id' => $otherOrg->id]);
        $foreignTemplate = OfferTemplate::query()->create(['organization_id' => $otherOrg->id, 'name' => 'Foreign']);

        $this->actAsOrg()->post('/offers', $this->payload(['contact_id' => $foreignContact->id, 'contact_person_id' => null]))->assertSessionHasErrors('contact_id');
        $this->actAsOrg()->post('/offers', $this->payload(['vat_rate_id' => $foreignVat->id]))->assertSessionHasErrors('vat_rate_id');
        $this->actAsOrg()->post('/offers', $this->payload(['template_id' => $foreignTemplate->id]))->assertSessionHasNoErrors();
        $this->assertNull(Offer::query()->firstOrFail()->template_id);
        $this->actAsOrg()->get('/offers/create?template=not-a-uuid')->assertOk();
    }

    #[Test]
    public function line_bounds_match_invoice_lines(): void
    {
        $line = fn (string $qty, string $price): array => ['lines' => [['type' => 'item', 'description' => 'X', 'quantity' => $qty, 'unit_price' => $price]]];

        $this->actAsOrg()->post('/offers', $this->payload($line('0', '100')))->assertSessionHasErrors('lines.0.quantity');
        $this->actAsOrg()->post('/offers', $this->payload($line('1.555', '100')))->assertSessionHasErrors('lines.0.quantity');
        $this->actAsOrg()->post('/offers', $this->payload($line('100000000', '1')))->assertSessionHasErrors('lines.0.quantity');
        $this->actAsOrg()->post('/offers', $this->payload($line('99999999', '999999999')))->assertSessionHasErrors('lines.0.unit_price');

        $offer = $this->offer(['lines' => [
            ['type' => 'item', 'description' => 'Conseil', 'quantity' => '1', 'unit_price' => '1000'],
            ['type' => 'item', 'description' => 'Rabais', 'quantity' => '1', 'unit_price' => '-100'],
        ]]);
        $this->assertSame('900.00', (string) $offer->subtotal);
        $this->assertSame('72.90', (string) $offer->vat_amount); // 81.00 - 8.10
    }

    #[Test]
    public function a_number_taken_concurrently_is_retried(): void
    {
        $this->offer(); // OF-2026-001
        $offers = \Mockery::mock(Offers::class, [app(OfferPdf::class), app(CreateInvoiceAction::class), app(InvoiceNumberGenerator::class)])->makePartial();
        $offers->shouldReceive('nextNumber')->andReturn('OF-2026-001', 'OF-2026-002');

        $this->assertSame('OF-2026-002', $offers->saveDraft($this->org->id, $this->payload())->number);
    }

    #[Test]
    public function markdown_images_are_not_drawn(): void
    {
        $offer = $this->offer(['intro' => "![logo](/var/www/html/storage/app/secret.png)\n\n![x](file:///etc/passwd)", 'closing' => null]);
        $html = app(OfferPdf::class)->html($offer);

        $this->assertStringNotContainsString('<img', substr($html, (int) strpos($html, '<body')));
        $this->assertStringNotContainsString('secret.png', $html);
    }

    #[Test]
    public function placeholder_values_are_not_read_as_markdown(): void
    {
        $this->contact->update(['name' => 'A_B*C [x](y) #1']);
        $offer = $this->offer(['intro' => 'Pour {company}. `{number}`']);
        $html = app(OfferPdf::class)->html($offer);

        $this->assertStringContainsString('Pour A_B*C [x](y) #1.', $html);
        $this->assertStringContainsString('<code>OF-2026-001</code>', $html);
    }

    #[Test]
    public function creating_without_delete_permission_cannot_delete(): void
    {
        $offer = $this->offer();
        $accountant = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->org->users()->attach($accountant->id, ['role' => 'accountant']);
        $this->assignOrganizationRole($accountant, $this->org, 'accountant');
        $as = fn () => $this->actingAs($accountant)->withSession(['current_organization_id' => $this->org->id]);

        $as()->get("/offers/{$offer->id}")->assertOk()->assertInertia(fn ($page) => $page->where('canManage', true)->where('canDelete', false));
        $as()->delete("/offers/{$offer->id}")->assertForbidden();
        $as()->post("/offers/{$offer->id}/send")->assertSessionHasNoErrors();
    }
}
