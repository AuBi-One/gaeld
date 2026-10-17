<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\ContactPerson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The "Attention: ..." contact person a customer may be addressed to on an
 * invoice — the create/edit forms offer each customer's own people, and a
 * person must actually belong to the chosen customer.
 */
class InvoiceContactPersonTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    private ContactPerson $person;

    private VatRate $vatRate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->customer = Contact::factory()->create(['organization_id' => $this->org->id]);
        $this->person = ContactPerson::query()->create([
            'contactable_type' => Contact::class,
            'contactable_id' => $this->customer->id,
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
        ]);
        $this->vatRate = VatRate::factory()->create(['organization_id' => $this->org->id, 'rate' => 8.10]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return [
            'customer_id' => $this->customer->id,
            'number' => 'INV-CP-001',
            'issue_date' => '2026-05-01',
            'due_date' => '2026-05-31',
            'currency' => 'CHF',
            'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100, 'vat_rate_id' => $this->vatRate->id]],
            ...$extra,
        ];
    }

    public function test_the_create_page_offers_each_customers_own_persons(): void
    {
        $this->actAsOrg()->get('/invoices/create')->assertInertia(
            fn (AssertableInertia $page) => $page->where("contactPersons.{$this->customer->id}.0.name", 'Jean Dupont')
        );
    }

    public function test_a_chosen_person_is_stored_and_snapshotted(): void
    {
        $this->actAsOrg()->post('/invoices', $this->payload(['contact_person_id' => $this->person->id]))
            ->assertRedirect();

        $invoice = $this->customer->invoices()->first();
        $this->assertSame($this->person->id, $invoice->contact_person_id);
        $this->assertSame('Jean Dupont', $invoice->customer_snapshot['attention']);
    }

    public function test_a_person_of_another_customer_is_refused(): void
    {
        $otherCustomer = Contact::factory()->create(['organization_id' => $this->org->id]);
        $otherPerson = ContactPerson::query()->create([
            'contactable_type' => Contact::class,
            'contactable_id' => $otherCustomer->id,
            'first_name' => 'Marie',
            'last_name' => 'Rochat',
        ]);

        $this->actAsOrg()->post('/invoices', $this->payload(['contact_person_id' => $otherPerson->id]))
            ->assertSessionHasErrors('contact_person_id');
    }
}
