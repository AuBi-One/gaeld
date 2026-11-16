<?php

namespace Tests\Feature\Contacts;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Expenses\Models\ExpenseCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * A salutation belongs to a person, never to an organisation; the contact form picks the
 * default expense category from the organisation's categories.
 */
class ContactSalutationAndCategoryTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_an_organisation_is_created_without_a_salutation(): void
    {
        $response = $this->actAsOrg()->postJson('/contacts', [
            'type' => 'organization',
            'salutation' => 'Monsieur',
            'name' => 'Muster AG',
            'country' => 'CH',
            'currency' => 'CHF',
        ]);

        $response->assertCreated()->assertJsonPath('contact.salutation', null);
    }

    public function test_switching_a_person_to_an_organisation_clears_the_salutation(): void
    {
        $contact = Contact::factory()->for($this->organization)->create([
            'type' => 'individual',
            'salutation' => 'Madame',
        ]);

        $this->actAsOrg()->put("/contacts/{$contact->uuid}", [
            'name' => $contact->name,
            'type' => 'organization',
        ])->assertRedirect("/contacts/{$contact->uuid}");

        $this->assertNull($contact->refresh()->salutation);
    }

    public function test_a_person_keeps_the_salutation(): void
    {
        $contact = Contact::factory()->for($this->organization)->create(['type' => 'individual']);

        $this->actAsOrg()->put("/contacts/{$contact->uuid}", [
            'name' => $contact->name,
            'type' => 'individual',
            'salutation' => 'Madame',
        ])->assertRedirect("/contacts/{$contact->uuid}");

        $this->assertSame('Madame', $contact->refresh()->salutation);
    }

    public function test_an_organisation_never_keeps_a_salutation_whatever_the_path(): void
    {
        // The API saves through the model directly, without the form DTOs.
        $contact = Contact::factory()->for($this->organization)->create(['type' => 'organization', 'salutation' => 'Monsieur']);
        $this->assertNull($contact->refresh()->salutation);

        // An update that does not send the type cannot add one either.
        $this->actAsOrg()->put("/contacts/{$contact->uuid}", [
            'name' => $contact->name,
            'salutation' => 'Monsieur',
        ])->assertRedirect("/contacts/{$contact->uuid}");
        $this->assertNull($contact->refresh()->salutation);
    }

    public function test_a_person_keeps_the_salutation_when_an_update_does_not_send_the_type(): void
    {
        $contact = Contact::factory()->for($this->organization)->create(['type' => 'individual', 'salutation' => 'Madame']);

        $this->actAsOrg()->put("/contacts/{$contact->uuid}", ['name' => 'Renamed'])
            ->assertRedirect("/contacts/{$contact->uuid}");

        $this->assertSame('Madame', $contact->refresh()->salutation);
    }

    public function test_the_contact_forms_receive_the_active_expense_categories(): void
    {
        $contact = Contact::factory()->for($this->organization)->create();
        $this->category('Travel', 'travel');
        $this->category('Software', 'software');
        $this->category('Rent', 'rent', active: false);
        $expected = ['Software', 'Travel'];

        $this->actAsOrg()->get('/contacts/create')
            ->assertInertia(fn (Assert $page) => $page->component('Contacts/Create')
                ->where('categories', fn ($categories) => collect($categories)->pluck('name')->sort()->values()->all() === $expected));
        $this->actAsOrg()->get("/contacts/{$contact->uuid}/edit")
            ->assertInertia(fn (Assert $page) => $page->component('Contacts/Edit')->has('categories'));
    }

    public function test_the_migration_replaces_old_quick_create_codes_with_category_names(): void
    {
        $travel = $this->category('Reisen', 'travel');
        $coded = Contact::factory()->for($this->organization)->create(['default_expense_category' => 'travel']);
        $unknown = Contact::factory()->for($this->organization)->create(['default_expense_category' => 'equipment']);

        (require database_path('migrations/2026_11_16_100000_map_contact_expense_category_codes_to_names.php'))->up();

        $this->assertSame($travel->name, $coded->refresh()->default_expense_category);
        $this->assertSame('equipment', $unknown->refresh()->default_expense_category);
    }

    private function category(string $name, string $code, bool $active = true): ExpenseCategory
    {
        return ExpenseCategory::create([
            'organization_id' => $this->organization->id,
            'name' => $name,
            'code' => $code,
            'is_active' => $active,
        ]);
    }
}
