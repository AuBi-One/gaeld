<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Jobs\SendPaymentRemindersJob;
use App\Domains\Invoicing\Mail\InvoiceReminderMail;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Per-invoice switch for the scheduled payment reminders: on by default,
 * turned off on the invoice page; the job then skips the invoice, while a
 * manual reminder still goes out.
 */
class InvoiceAutoRemindersTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    private function overdueInvoice(string $number, ?Organization $organization = null): Invoice
    {
        $organization ??= $this->organization;
        $customer = Contact::factory()->for($organization)->create(['email' => "billing-{$number}@reminder.test"]);

        return Invoice::factory()->for($organization)->for($customer, 'customer')->overdue()->create(['number' => $number]);
    }

    public function test_automatic_reminders_are_on_by_default(): void
    {
        $invoice = $this->overdueInvoice('INV-AR-DEFAULT');

        $this->assertTrue($invoice->fresh()->auto_reminders);
        $this->actAsOrg()->get("/invoices/{$invoice->id}")
            ->assertInertia(fn ($page) => $page->where('invoice.auto_reminders', true)->where('canManageReminders', true));
    }

    public function test_the_job_skips_invoices_whose_automatic_reminders_are_off(): void
    {
        Mail::fake();
        $on = $this->overdueInvoice('INV-AR-ON');
        $off = $this->overdueInvoice('INV-AR-OFF');
        $this->actAsOrg()->patch("/invoices/{$off->id}/auto-reminders", ['auto_reminders' => false])
            ->assertRedirect("/invoices/{$off->id}");
        $this->assertFalse($off->fresh()->auto_reminders);

        app()->call([app(SendPaymentRemindersJob::class), 'handle']);

        $this->assertSame(1, $on->fresh()->reminder_count);
        $this->assertSame(0, $off->fresh()->reminder_count);
        Mail::assertSent(InvoiceReminderMail::class, 1);

        // A manual reminder from the invoice page still goes out.
        $this->actAsOrg()->post("/invoices/{$off->id}/reminder")->assertRedirect("/invoices/{$off->id}");
        $this->assertSame(1, $off->fresh()->reminder_count);

        // And the switch turns them back on.
        $this->actAsOrg()->patch("/invoices/{$off->id}/auto-reminders", ['auto_reminders' => true]);
        $this->assertTrue($off->fresh()->auto_reminders);
    }

    public function test_the_switch_is_validated_authorised_and_scoped_to_the_organisation(): void
    {
        $invoice = $this->overdueInvoice('INV-AR-AUTH');
        $this->actAsOrg()->patch("/invoices/{$invoice->id}/auto-reminders", [])->assertSessionHasErrors('auto_reminders');

        $other = $this->overdueInvoice('INV-AR-OTHER', Organization::factory()->create());
        $this->actAsOrg()->patch("/invoices/{$other->id}/auto-reminders", ['auto_reminders' => false])->assertNotFound();
        $this->assertTrue($other->fresh()->auto_reminders);

        $employee = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->org->users()->attach($employee->id, ['role' => 'employee']);
        $this->assignOrganizationRole($employee, $this->org, 'employee');
        $this->actingAs($employee)->withSession(['current_organization_id' => $this->org->id])
            ->patch("/invoices/{$invoice->id}/auto-reminders", ['auto_reminders' => false])->assertForbidden();

        $invoice->update(['archived_at' => now()]);
        $this->actAsOrg()->patch("/invoices/{$invoice->id}/auto-reminders", ['auto_reminders' => false])->assertForbidden();
        $this->assertTrue($invoice->fresh()->auto_reminders);
    }
}
