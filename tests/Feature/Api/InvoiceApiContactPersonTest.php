<?php

namespace Tests\Feature\Api;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Services\CurrentOrganization;
use Tests\Security\SecurityTestCase;

/** The invoice contact person through the API: kept by a sparse update, set, validated, removed. */
class InvoiceApiContactPersonTest extends SecurityTestCase
{
    public function test_a_sparse_update_keeps_the_contact_person_and_it_can_be_changed(): void
    {
        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);

        $customer = Contact::create(['organization_id' => $this->orgA->id, 'name' => 'Client AG']);
        $person = $customer->contactPersons()->create(['first_name' => 'Anna', 'last_name' => 'Muster']);
        $colleague = $customer->contactPersons()->create(['first_name' => 'Beat', 'last_name' => 'Beispiel']);
        $other = Contact::create(['organization_id' => $this->orgA->id, 'name' => 'Other AG']);
        $stranger = $other->contactPersons()->create(['first_name' => 'Carla', 'last_name' => 'Fremd']);
        $invoice = Invoice::factory()->create([
            'organization_id' => $this->orgA->id,
            'customer_id' => $customer->id,
            'contact_person_id' => $person->id,
        ]);
        $token = $this->createApiToken($this->ownerA, $this->orgA);

        $this->withToken($token)->getJson("/api/v1/invoices/{$invoice->id}")->assertOk()->assertJsonPath('data.contact_person_id', $person->id);

        $this->withToken($token)->withHeader('Idempotency-Key', 'cp-1')->putJson("/api/v1/invoices/{$invoice->id}", ['notes' => 'Merci'])->assertOk();
        $this->assertSame($person->id, $invoice->fresh()->contact_person_id);
        $this->assertSame('Anna Muster', $invoice->fresh()->customer_snapshot['attention'] ?? null);

        $this->withToken($token)->withHeader('Idempotency-Key', 'cp-2')->putJson("/api/v1/invoices/{$invoice->id}", ['contact_person_id' => $stranger->id])
            ->assertUnprocessable()->assertJsonValidationErrors('contact_person_id');

        $this->withToken($token)->withHeader('Idempotency-Key', 'cp-3')->putJson("/api/v1/invoices/{$invoice->id}", ['contact_person_id' => $colleague->id])->assertOk();
        $this->assertSame($colleague->id, $invoice->fresh()->contact_person_id);

        // Another customer without a person: the old customer's person is dropped.
        $this->withToken($token)->withHeader('Idempotency-Key', 'cp-4')->putJson("/api/v1/invoices/{$invoice->id}", ['customer_id' => $other->uuid])->assertOk();
        $this->assertNull($invoice->fresh()->contact_person_id);

        $this->withToken($token)->withHeader('Idempotency-Key', 'cp-5')->putJson("/api/v1/invoices/{$invoice->id}", ['contact_person_id' => $stranger->id])->assertOk();
        $this->assertSame($stranger->id, $invoice->fresh()->contact_person_id);
        $this->withToken($token)->withHeader('Idempotency-Key', 'cp-6')->putJson("/api/v1/invoices/{$invoice->id}", ['contact_person_id' => null])->assertOk();
        $this->assertNull($invoice->fresh()->contact_person_id);
    }
}
