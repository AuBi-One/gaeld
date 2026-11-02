<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\ContactPerson;
use App\Domains\Invoicing\Actions\CreateInvoiceAction;
use App\Domains\Invoicing\Enums\RecurrenceFrequency;
use App\Domains\Invoicing\Jobs\GenerateRecurringInvoicesJob;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\RecurringInvoice;
use App\Domains\Invoicing\Services\InvoiceNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class RecurringInvoiceFlowTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-15 08:00:00');

        $this->setUpOrganization();

        Account::create(['organization_id' => $this->org->id, 'code' => '1100', 'name' => 'Accounts Receivable', 'type' => AccountType::Asset->value]);
        Account::create(['organization_id' => $this->org->id, 'code' => '3000', 'name' => 'Revenue', 'type' => AccountType::Revenue->value]);
        Account::create(['organization_id' => $this->org->id, 'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value]);
        Account::create(['organization_id' => $this->org->id, 'code' => '2200', 'name' => 'VAT Output', 'type' => AccountType::Liability->value]);
        Account::create(['organization_id' => $this->org->id, 'code' => '3900', 'name' => 'Rounding', 'type' => AccountType::Revenue->value]);

        $this->customer = Contact::create([
            'organization_id' => $this->org->id,
            'name' => 'Recurring Client AG',
            'email' => 'billing@client.ch',
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  CRUD via controller
    // ──────────────────────────────────────────────────────────────

    public function test_index_page_renders(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->get(route('invoices.recurring.index'));

        $response->assertStatus(200);
    }

    public function test_create_page_renders(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->get(route('invoices.recurring.create'));

        $response->assertStatus(200);
    }

    public function test_can_store_recurring_invoice(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->post(route('invoices.recurring.store'), [
                'customer_id' => $this->customer->id,
                'frequency' => 'monthly',
                'next_issue_date' => '2026-04-01',
                'end_date' => null,
                'template_data' => [
                    'currency' => 'CHF',
                    'notes' => 'Recurring service',
                    'lines' => [
                        ['description' => 'Monthly Support', 'quantity' => 1, 'unit_price' => '500.00'],
                    ],
                ],
            ]);

        $response->assertRedirect(route('invoices.recurring.index'));

        $this->assertDatabaseHas('recurring_invoices', [
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'frequency' => 'monthly',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->post(route('invoices.recurring.store'), []);

        $response->assertSessionHasErrors(['customer_id', 'frequency', 'next_issue_date', 'template_data']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Job: GenerateRecurringInvoicesJob
    // ──────────────────────────────────────────────────────────────

    public function test_job_generates_invoice_when_due(): void
    {
        RecurringInvoice::create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'frequency' => RecurrenceFrequency::Monthly,
            'next_issue_date' => '2026-03-15',
            'template_data' => [
                'currency' => 'CHF',
                'lines' => [
                    ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => '200.00', 'sort_order' => 0],
                ],
            ],
            'is_active' => true,
        ]);

        $this->assertDatabaseCount('invoices', 0);

        app(GenerateRecurringInvoicesJob::class)->handle(
            app(CreateInvoiceAction::class),
            app(InvoiceNumberGenerator::class),
        );

        $this->assertDatabaseCount('invoices', 1);

        $invoice = Invoice::first();
        $this->assertEquals($this->customer->id, $invoice->customer_id);
        $this->assertEquals($this->org->id, $invoice->organization_id);
    }

    public function test_the_contact_person_is_stored_validated_and_carried_to_the_generated_invoices(): void
    {
        $person = ContactPerson::query()->create(['contactable_type' => Contact::class, 'contactable_id' => $this->customer->id, 'first_name' => 'Jean', 'last_name' => 'Dupont']);
        $other = Contact::create(['organization_id' => $this->org->id, 'name' => 'Other AG']);
        $otherPerson = ContactPerson::query()->create(['contactable_type' => Contact::class, 'contactable_id' => $other->id, 'first_name' => 'Eva', 'last_name' => 'Muster']);
        $payload = fn (string $personId): array => [
            'customer_id' => $this->customer->id, 'frequency' => 'monthly', 'next_issue_date' => now()->addYear()->toDateString(),
            'template_data' => ['currency' => 'CHF', 'contact_person_id' => $personId, 'lines' => [['description' => 'Hosting', 'quantity' => 1, 'unit_price' => '200.00']]],
        ];

        $this->actAsOrg()->get(route('invoices.recurring.create'))
            ->assertInertia(fn ($page) => $page->where("contactPersons.{$this->customer->id}.0.name", 'Jean Dupont'));
        $this->actAsOrg()->post(route('invoices.recurring.store'), $payload($otherPerson->id))
            ->assertSessionHasErrors('template_data.contact_person_id');
        // A malformed id is a validation error, not a database error.
        $this->actAsOrg()->post(route('invoices.recurring.store'), $payload('not-a-uuid'))
            ->assertSessionHasErrors('template_data.contact_person_id');
        $this->actAsOrg()->post(route('invoices.recurring.store'), $payload($person->id))->assertSessionHasNoErrors();
        $recurring = RecurringInvoice::query()->sole();
        $this->assertSame($person->id, $recurring->template_data['contact_person_id']);
        $recurring->update(['next_issue_date' => '2026-03-15']);

        $run = fn () => app(GenerateRecurringInvoicesJob::class)->handle(app(CreateInvoiceAction::class), app(InvoiceNumberGenerator::class));
        $run();
        $invoice = Invoice::query()->sole();
        $this->assertSame($person->id, $invoice->contact_person_id);
        $this->assertSame('Jean Dupont', $invoice->customer_snapshot['attention']);

        // A person who no longer belongs to the customer is not carried over.
        ContactPerson::query()->whereKey($person->id)->update(['contactable_id' => $other->id]);
        Carbon::setTestNow('2026-04-15 08:00:00');
        $run();
        $this->assertNull(Invoice::query()->whereKeyNot($invoice->id)->sole()->contact_person_id);
    }

    public function test_job_advances_next_issue_date(): void
    {
        $recurring = RecurringInvoice::create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'frequency' => RecurrenceFrequency::Monthly,
            'next_issue_date' => '2026-03-15',
            'template_data' => [
                'currency' => 'CHF',
                'lines' => [
                    ['description' => 'Service', 'quantity' => 1, 'unit_price' => '100.00', 'sort_order' => 0],
                ],
            ],
            'is_active' => true,
        ]);

        app(GenerateRecurringInvoicesJob::class)->handle(
            app(CreateInvoiceAction::class),
            app(InvoiceNumberGenerator::class),
        );

        $recurring->refresh();
        $this->assertEquals('2026-04-15', $recurring->next_issue_date->toDateString());
        $this->assertTrue($recurring->is_active);
    }

    public function test_job_deactivates_when_end_date_reached(): void
    {
        $recurring = RecurringInvoice::create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'frequency' => RecurrenceFrequency::Monthly,
            'next_issue_date' => '2026-03-15',
            'end_date' => '2026-03-20',
            'template_data' => [
                'currency' => 'CHF',
                'lines' => [
                    ['description' => 'Service', 'quantity' => 1, 'unit_price' => '100.00', 'sort_order' => 0],
                ],
            ],
            'is_active' => true,
        ]);

        app(GenerateRecurringInvoicesJob::class)->handle(
            app(CreateInvoiceAction::class),
            app(InvoiceNumberGenerator::class),
        );

        $recurring->refresh();
        $this->assertFalse($recurring->is_active);
    }

    public function test_job_skips_inactive_recurring_invoices(): void
    {
        RecurringInvoice::create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'frequency' => RecurrenceFrequency::Monthly,
            'next_issue_date' => '2026-03-15',
            'template_data' => [
                'currency' => 'CHF',
                'lines' => [
                    ['description' => 'Service', 'quantity' => 1, 'unit_price' => '100.00', 'sort_order' => 0],
                ],
            ],
            'is_active' => false,
        ]);

        app(GenerateRecurringInvoicesJob::class)->handle(
            app(CreateInvoiceAction::class),
            app(InvoiceNumberGenerator::class),
        );

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_job_skips_not_yet_due_recurring_invoices(): void
    {
        RecurringInvoice::create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'frequency' => RecurrenceFrequency::Monthly,
            'next_issue_date' => '2026-04-15',
            'template_data' => [
                'currency' => 'CHF',
                'lines' => [
                    ['description' => 'Service', 'quantity' => 1, 'unit_price' => '100.00', 'sort_order' => 0],
                ],
            ],
            'is_active' => true,
        ]);

        app(GenerateRecurringInvoicesJob::class)->handle(
            app(CreateInvoiceAction::class),
            app(InvoiceNumberGenerator::class),
        );

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get(route('invoices.recurring.index'));

        $response->assertRedirect();
    }
}
