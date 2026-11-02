<?php

namespace Tests\Feature\Api;

use App\Domains\Api\Enums\TokenType;
use App\Domains\Api\Resources\InvoiceResource;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Organizations\Services\CurrentOrganization;
use Tests\Security\SecurityTestCase;

/**
 * The API exposes the per-invoice automatic-reminders switch and accepts it
 * on update, alone also for a sent invoice.
 */
class InvoiceApiAutoRemindersTest extends SecurityTestCase
{
    public function test_the_switch_is_exposed_and_can_be_turned_off_on_a_sent_invoice(): void
    {
        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);
        $customer = Contact::create(['organization_id' => $this->orgA->id, 'name' => 'Client AG']);
        $invoice = Invoice::factory()->sent()->create(['organization_id' => $this->orgA->id, 'customer_id' => $customer->id]);
        $token = $this->createApiToken($this->ownerA, $this->orgA);

        $this->withToken($token)->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertOk()->assertJsonPath('data.auto_reminders', true);

        $this->withToken($token)->patchJson("/api/v1/invoices/{$invoice->id}", ['auto_reminders' => false])
            ->assertOk()->assertJsonPath('data.auto_reminders', false);
        $this->assertFalse($invoice->fresh()->auto_reminders);

        // With other fields, the usual draft-only rule applies (409 for a sent invoice).
        $this->withToken($token)->patchJson("/api/v1/invoices/{$invoice->id}", ['auto_reminders' => true, 'notes' => 'x'])
            ->assertStatus(409);
        $this->assertFalse($invoice->fresh()->auto_reminders);
    }

    public function test_a_token_without_invoicing_edit_cannot_turn_the_switch(): void
    {
        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);
        $customer = Contact::create(['organization_id' => $this->orgA->id, 'name' => 'Client AG']);
        $invoice = Invoice::factory()->sent()->create(['organization_id' => $this->orgA->id, 'customer_id' => $customer->id]);
        $result = $this->ownerA->createToken('read-only', [Permission::InvoicingView->value]);
        $result->accessToken->update(['organization_id' => $this->orgA->id, 'type' => TokenType::Personal]);

        $this->withToken($result->plainTextToken)->patchJson("/api/v1/invoices/{$invoice->id}", ['auto_reminders' => false])
            ->assertForbidden();
        $this->assertTrue($invoice->fresh()->auto_reminders);
    }

    public function test_a_just_created_invoice_reports_automatic_reminders_on(): void
    {
        $customer = Contact::create(['organization_id' => $this->orgA->id, 'name' => 'Client AG']);
        // Not reloaded: what the API create returns right away.
        $invoice = Invoice::factory()->create(['organization_id' => $this->orgA->id, 'customer_id' => $customer->id]);

        $this->assertTrue($invoice->auto_reminders);
        $this->assertSame(true, (new InvoiceResource($invoice))->resolve()['auto_reminders']);
    }
}
