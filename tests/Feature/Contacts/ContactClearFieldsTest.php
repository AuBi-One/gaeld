<?php

namespace Tests\Feature\Contacts;

use App\Domains\Contacts\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Emptying a field on the contact form clears it; fields the form does not send keep their value.
 */
class ContactClearFieldsTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_emptied_fields_are_cleared_and_fields_not_sent_are_kept(): void
    {
        $contact = Contact::factory()->for($this->organization)->create([
            'name' => 'Muster GmbH',
            'email' => 'info@example.test',
            'phone' => '+41 21 000 00 00',
            'vat_number' => 'CHE-123.456.789',
            'iban' => 'CH9300762011623852957',
            'bic' => 'POFICHBEXXX',
            'internal_notes' => 'Interne Notiz',
            'address' => 'Rue 2',
            'postal_code' => '1003',
            'city' => 'Lausanne',
            'country' => 'CH',
        ]);

        $this->actAsOrg()->put("/contacts/{$contact->uuid}", [
            'name' => 'Muster GmbH',
            'phone' => '',
            'vat_number' => '',
            'iban' => '',
            'bic' => '',
            'internal_notes' => '',
            'address' => '',
            'postal_code' => '',
            'city' => '',
        ])->assertRedirect("/contacts/{$contact->uuid}");

        $contact->refresh();
        foreach (['phone', 'vat_number', 'iban', 'bic', 'internal_notes', 'address', 'postal_code', 'city'] as $field) {
            $this->assertNull($contact->{$field}, $field);
        }
        $this->assertSame('info@example.test', $contact->email);
        $this->assertSame('CH', $contact->country);
    }

    public function test_an_emptied_email_and_notes_are_cleared(): void
    {
        $contact = Contact::factory()->for($this->organization)->create([
            'name' => 'Muster GmbH',
            'email' => 'info@example.test',
            'notes' => ['default' => 'Remarque'],
        ]);

        $this->actAsOrg()->put("/contacts/{$contact->uuid}", [
            'name' => 'Muster GmbH',
            'email' => '',
            'notes' => '',
        ])->assertRedirect("/contacts/{$contact->uuid}");

        $contact->refresh();
        $this->assertNull($contact->email);
        $this->assertNull($contact->notes);
    }
}
