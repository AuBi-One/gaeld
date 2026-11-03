<?php

namespace Tests\Feature\Contacts;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\ContactPerson;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Contact persons are added, edited and removed on the contact page, which
 * addresses the contact by its public uuid like every other contact URL.
 */
class ContactPersonCrudTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    }

    private function person(Contact $contact, array $attributes = []): ContactPerson
    {
        return ContactPerson::query()->create([
            'contactable_type' => Contact::class,
            'contactable_id' => $contact->id,
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            ...$attributes,
        ]);
    }

    public function test_a_person_is_added_to_the_contact_by_uuid(): void
    {
        $this->actAsOrg()
            ->postJson("/contacts/{$this->contact->uuid}/contact-persons", [
                'first_name' => 'Anna',
                'last_name' => 'Muster',
                'email' => 'anna@example.com',
                'is_primary' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('contact_person.first_name', 'Anna');

        $person = $this->contact->contactPersons()->sole();
        $this->assertSame('Muster', $person->last_name);
        $this->assertTrue($person->is_primary);
    }

    public function test_a_person_is_updated_by_uuid_and_takes_over_primary(): void
    {
        $old = $this->person($this->contact, ['is_primary' => true]);
        $person = $this->person($this->contact, ['first_name' => 'Paul']);

        $this->actAsOrg()
            ->putJson("/contacts/{$this->contact->uuid}/contact-persons/{$person->id}", [
                'first_name' => 'Paula',
                'last_name' => 'Dupont',
                'is_primary' => true,
            ])
            ->assertOk()
            ->assertJsonPath('contact_person.first_name', 'Paula');

        $this->assertTrue($person->fresh()->is_primary);
        $this->assertFalse($old->fresh()->is_primary);
    }

    public function test_a_person_is_removed_by_uuid(): void
    {
        $person = $this->person($this->contact);

        $this->actAsOrg()
            ->deleteJson("/contacts/{$this->contact->uuid}/contact-persons/{$person->id}")
            ->assertNoContent();

        $this->assertNull(ContactPerson::query()->find($person->id));
    }

    public function test_a_contact_that_is_not_found_by_uuid_is_a_404(): void
    {
        $person = $this->person($this->contact);

        // the internal id, a malformed value and an unknown uuid
        foreach ([(string) $this->contact->id, 'not-a-uuid', '00000000-0000-4000-8000-000000000000'] as $key) {
            $this->actAsOrg()->postJson("/contacts/{$key}/contact-persons", ['first_name' => 'A', 'last_name' => 'B'])->assertNotFound();
            $this->actAsOrg()->putJson("/contacts/{$key}/contact-persons/{$person->id}", ['first_name' => 'A', 'last_name' => 'B'])->assertNotFound();
            $this->actAsOrg()->deleteJson("/contacts/{$key}/contact-persons/{$person->id}")->assertNotFound();
        }

        $this->assertSame(1, ContactPerson::query()->count());
        $this->assertSame('Jean', $person->fresh()->first_name);
    }

    public function test_another_organisations_contact_is_refused(): void
    {
        $foreign = Contact::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $person = $this->person($foreign);

        $this->actAsOrg()->postJson("/contacts/{$foreign->uuid}/contact-persons", ['first_name' => 'A', 'last_name' => 'B'])->assertNotFound();
        $this->actAsOrg()->putJson("/contacts/{$foreign->uuid}/contact-persons/{$person->id}", ['first_name' => 'A', 'last_name' => 'B'])->assertNotFound();
        $this->actAsOrg()->deleteJson("/contacts/{$foreign->uuid}/contact-persons/{$person->id}")->assertNotFound();

        $this->assertSame(1, ContactPerson::query()->count());
        $this->assertSame('Jean', $person->fresh()->first_name);
    }

    public function test_a_person_of_another_contact_cannot_be_changed_through_this_one(): void
    {
        $other = Contact::factory()->create(['organization_id' => $this->org->id]);
        $person = $this->person($other);

        $this->actAsOrg()->putJson("/contacts/{$this->contact->uuid}/contact-persons/{$person->id}", ['first_name' => 'A', 'last_name' => 'B'])->assertNotFound();
        $this->actAsOrg()->deleteJson("/contacts/{$this->contact->uuid}/contact-persons/{$person->id}")->assertNotFound();

        $this->assertSame('Jean', $person->fresh()->first_name);
    }

    public function test_a_deleted_contact_takes_no_new_persons(): void
    {
        $this->contact->delete();

        $this->actAsOrg()->postJson("/contacts/{$this->contact->uuid}/contact-persons", ['first_name' => 'A', 'last_name' => 'B'])->assertNotFound();

        $this->assertSame(0, ContactPerson::query()->count());
    }
}
