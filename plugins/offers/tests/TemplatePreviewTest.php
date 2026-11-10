<?php

namespace Plugins\Offers\Tests;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use Plugins\Offers\Models\Offer;
use Plugins\Offers\Models\OfferTemplate;
use Plugins\Offers\Services\OfferPdf;

class TemplatePreviewTest extends OffersTestCase
{
    /** @return array<string, mixed> */
    private function template(): array
    {
        return [
            'name' => '',
            'title' => 'Analyse de {company}',
            'intro' => 'Bonjour {attention}',
            'closing' => 'Meilleures salutations',
            'layout' => ['from' => ['logo' => false, 'address' => true, 'email' => true, 'phone' => false], 'to' => ['address' => true, 'email' => false, 'phone' => false]],
            'lines' => [
                ['type' => 'item', 'label' => '1', 'description' => 'Étude', 'quantity' => '2', 'unit' => 'h', 'unit_price' => '150.00'],
                ['type' => 'text', 'label' => null, 'description' => 'Remarque'],
                ['type' => 'item', 'label' => '2', 'description' => 'Rabais', 'quantity' => '1', 'unit' => null, 'unit_price' => '-50.00'],
            ],
        ];
    }

    private function previewUrl(): string
    {
        return (string) $this->actAsOrg()->postJson('/offer-templates/preview', $this->template())->assertOk()->json('url');
    }

    public function test_preview_returns_a_pdf_and_stores_nothing(): void
    {
        $response = $this->actAsOrg()->get($this->previewUrl());

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertSame(0, Offer::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, OfferTemplate::query()->withoutGlobalScopes()->count());
    }

    public function test_preview_draws_the_template_with_the_default_vat_rate_and_a_sample_recipient(): void
    {
        $this->vat->update(['is_default' => true, 'is_active' => true]);
        $drawn = null;
        $this->mock(OfferPdf::class, function ($mock) use (&$drawn): void {
            $mock->shouldReceive('render')->once()->andReturnUsing(function (Offer $offer) use (&$drawn): string {
                $drawn = $offer;

                return '%PDF-fake';
            });
        });

        $this->actAsOrg()->get($this->previewUrl())->assertOk();

        $this->assertInstanceOf(Offer::class, $drawn);
        $this->assertFalse($drawn->exists);
        $this->assertSame('Analyse de {company}', $drawn->title);
        $this->assertSame('OF-'.today()->year.'-NNN', $drawn->number);
        $this->assertSame(__('offers::of.preview_company', [], $drawn->language), $drawn->recipient['company']);
        $this->assertCount(3, $drawn->lines);
        $this->assertSame('250.00', (string) $drawn->subtotal);
        $this->assertSame('20.25', (string) $drawn->vat_amount);
        $this->assertSame('270.25', (string) $drawn->total);
        $this->assertSame(today()->toDateString(), $drawn->offer_date->toDateString());
    }

    public function test_a_preview_url_is_of_no_use_to_another_user(): void
    {
        $url = $this->previewUrl();
        $other = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($other->id, ['role' => 'owner']);
        $this->assignOrganizationRole($other, $this->organization, 'owner');

        $this->actingAs($other)->withSession(['current_organization_id' => $this->organization->id])->get($url)->assertNotFound();
        $this->actAsOrg()->get('/offer-templates/preview/'.str_repeat('a', 40))->assertNotFound();
    }

    public function test_a_preview_url_is_of_no_use_in_another_organisation(): void
    {
        $url = $this->previewUrl();
        $other = Organization::factory()->create();
        $other->users()->attach($this->user->id, ['role' => 'owner']);
        $this->assignOrganizationRole($this->user, $other, 'owner');

        $this->actingAs($this->user)->withSession(['current_organization_id' => $other->id])->get($url)->assertNotFound();
    }

    public function test_without_a_default_vat_rate_the_total_has_no_vat(): void
    {
        $this->vat->update(['is_default' => false]);
        $drawn = null;
        $this->mock(OfferPdf::class, function ($mock) use (&$drawn): void {
            $mock->shouldReceive('render')->once()->andReturnUsing(function (Offer $offer) use (&$drawn): string {
                $drawn = $offer;

                return '%PDF-fake';
            });
        });

        $this->previewUrl();

        $this->assertNull($drawn->vat_rate);
        $this->assertSame('0.00', (string) $drawn->vat_amount);
        $this->assertSame('250.00', (string) $drawn->total);
    }

    public function test_preview_validates_the_lines(): void
    {
        $data = $this->template();
        $data['lines'][0]['quantity'] = '0';

        $this->actAsOrg()->postJson('/offer-templates/preview', $data)->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');
    }
}
