<?php

namespace Plugins\Offers\Tests;

require_once __DIR__.'/OffersTestCase.php';

use PHPUnit\Framework\Attributes\Test;
use Plugins\Offers\Models\Offer;
use Plugins\Offers\Models\OfferSetting;
use Plugins\Offers\Services\Offers;

/** Offer numbers advance by the organisation's number step (of_settings.number_step). */
class OfferNumberStepTest extends OffersTestCase
{
    /** @param  array<string, mixed>  $overrides */
    private function offer(array $overrides = []): Offer
    {
        return app(Offers::class)->saveDraft($this->org->id, array_merge([
            'contact_id' => $this->contact->id,
            'contact_person_id' => $this->person->id,
            'title' => 'Analyse',
            'offer_date' => '2026-03-02',
            'valid_until' => '2026-04-01',
            'language' => 'fr',
            'currency' => 'CHF',
            'vat_rate_id' => $this->vat->id,
            'lines' => [['type' => 'item', 'label' => '1', 'description' => 'Atelier', 'quantity' => '1', 'unit' => 'jour', 'unit_price' => '1000']],
        ], $overrides));
    }

    #[Test]
    public function the_default_step_is_one(): void
    {
        $this->assertSame(1, OfferSetting::for($this->org->id)->number_step);
        $this->assertSame('OF-2026-001', $this->offer()->number);
        $this->assertSame('OF-2026-002', $this->offer()->number);
    }

    #[Test]
    public function new_offers_and_revisions_advance_by_the_step(): void
    {
        OfferSetting::query()->create(['organization_id' => $this->org->id, 'validity_months' => 2, 'number_step' => 6]);

        $this->assertSame('OF-2026-006', $this->offer()->number);
        $first = $this->offer();
        $this->assertSame('OF-2026-012', $first->number);
        $this->assertSame('OF-2025-006', $this->offer(['offer_date' => '2025-12-01', 'valid_until' => null])->number);

        // a revision gets a new number by the same rule (year of today)
        $this->travelTo('2026-06-01 10:00:00');
        $revision = app(Offers::class)->revise(app(Offers::class)->transition($first, 'send'));
        $this->assertSame('OF-2026-018', $revision->number);
    }

    #[Test]
    public function the_step_is_validated_and_saved_with_the_settings(): void
    {
        $this->actAsOrg()->put('/offer-templates/settings', ['validity_months' => 2, 'number_step' => 0])->assertSessionHasErrors('number_step');
        $this->actAsOrg()->put('/offer-templates/settings', ['validity_months' => 2, 'number_step' => 101])->assertSessionHasErrors('number_step');
        $this->assertSame(1, OfferSetting::for($this->org->id)->number_step);

        $this->actAsOrg()->put('/offer-templates/settings', ['validity_months' => 2, 'number_step' => 6])->assertRedirect('/offer-templates');
        $this->assertSame(6, OfferSetting::for($this->org->id)->number_step);

        // a client that only sends the validity keeps the step
        $this->actAsOrg()->put('/offer-templates/settings', ['validity_months' => 3])->assertRedirect('/offer-templates');
        $this->assertSame(6, OfferSetting::for($this->org->id)->fresh()->number_step);

        $this->actAsOrg()->get('/offer-templates')->assertInertia(fn ($page) => $page->where('settings.number_step', 6));
    }
}
