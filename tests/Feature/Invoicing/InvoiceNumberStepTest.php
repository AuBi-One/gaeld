<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\CreateInvoiceAction;
use App\Domains\Invoicing\DTOs\CreateInvoiceData;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceNumberGenerator;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Invoice numbers advance by the organisation's invoice_number_step; credit notes stay consecutive.
 */
class InvoiceNumberStepTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->customer = Contact::create(['organization_id' => $this->org->id, 'name' => 'Test Client AG']);
    }

    private function createInvoice(string $number): Invoice
    {
        return app(CreateInvoiceAction::class)->execute(CreateInvoiceData::fromArray([
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'number' => $number,
            'issue_date' => '2026-03-15',
            'due_date' => '2026-04-15',
            'currency' => 'CHF',
            'lines' => [['description' => 'Service', 'quantity' => '1', 'unit_price' => '100.00']],
        ]));
    }

    public function test_the_default_step_keeps_numbers_consecutive(): void
    {
        $this->assertSame(1, $this->org->fresh()->invoice_number_step);
        $this->assertSame('INV-2026-001', app(InvoiceNumberGenerator::class)->next($this->org->id, null, 2026));

        $this->createInvoice('INV-2026-001');

        $this->assertSame('INV-2026-002', app(InvoiceNumberGenerator::class)->next($this->org->id, null, 2026));
    }

    public function test_invoice_numbers_advance_by_the_step(): void
    {
        $this->org->update(['invoice_number_step' => 6]);
        $generator = app(InvoiceNumberGenerator::class);

        $this->assertSame('INV-2026-006', $generator->next($this->org->id, null, 2026));
        $this->assertSame('INV-2026-006', $generator->next($this->org->id, 'INV', 2026));

        $this->createInvoice('INV-2026-001');
        $this->assertSame('INV-2026-007', $generator->next($this->org->id, null, 2026));

        $this->createInvoice('INV-2026-007');
        $this->assertSame('INV-2026-013', $generator->next($this->org->id, null, 2026));

        // another year starts again from 0 + step
        $this->assertSame('INV-2025-006', $generator->next($this->org->id, null, 2025));
    }

    public function test_credit_notes_stay_consecutive(): void
    {
        $this->org->update(['invoice_number_step' => 6]);

        $this->assertSame('CN-2026-001', app(InvoiceNumberGenerator::class)->next($this->org->id, 'CN', 2026));
    }

    public function test_the_step_of_another_organisation_does_not_apply(): void
    {
        $this->org->update(['invoice_number_step' => 6]);
        $other = Organization::factory()->create();

        $this->assertSame('INV-2026-001', app(InvoiceNumberGenerator::class)->next($other->id, null, 2026));
    }

    public function test_a_collision_retries_with_the_step(): void
    {
        $this->org->update(['invoice_number_step' => 6]);
        $this->createInvoice('INV-2026-006');

        // the suggested number was taken meanwhile: regenerated as highest + step
        $second = $this->createInvoice('INV-2026-006');

        $this->assertSame('INV-2026-012', $second->number);
    }

    public function test_the_create_form_suggests_the_stepped_number(): void
    {
        $this->org->update(['invoice_number_step' => 6]);
        $year = now()->year;

        $this->actAsOrg()->get('/invoices/create')->assertInertia(
            fn ($page) => $page->where('suggestedNumber', "INV-{$year}-006")
        );
    }

    public function test_the_step_is_edited_in_the_invoice_settings(): void
    {
        $texts = ['invoice_header_text' => null, 'invoice_footer_text' => null, 'default_invoice_notes' => null];

        $this->actAsOrg()->put('/settings/invoice', $texts + ['invoice_number_step' => 0])->assertSessionHasErrors('invoice_number_step');
        $this->actAsOrg()->put('/settings/invoice', $texts + ['invoice_number_step' => 101])->assertSessionHasErrors('invoice_number_step');
        $this->actAsOrg()->put('/settings/invoice', $texts + ['invoice_number_step' => 'x'])->assertSessionHasErrors('invoice_number_step');
        $this->assertSame(1, $this->org->fresh()->invoice_number_step);

        $this->actAsOrg()->put('/settings/invoice', $texts + ['invoice_number_step' => 6])->assertSessionHasNoErrors();
        $this->assertSame(6, $this->org->fresh()->invoice_number_step);

        // a client that does not send the field keeps the step
        $this->actAsOrg()->put('/settings/invoice', $texts)->assertSessionHasNoErrors();
        $this->assertSame(6, $this->org->fresh()->invoice_number_step);

        $this->actAsOrg()->get('/settings')->assertInertia(fn ($page) => $page->where('organization.invoice_number_step', 6));
    }
}
