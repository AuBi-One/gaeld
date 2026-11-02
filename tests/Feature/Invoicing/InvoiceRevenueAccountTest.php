<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\CreateCreditNoteAction;
use App\Domains\Invoicing\Actions\CreateInvoiceAction;
use App\Domains\Invoicing\Actions\FinalizeInvoiceAction;
use App\Domains\Invoicing\DTOs\CreateInvoiceData;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Services\OrganizationSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The account invoice revenue is credited to is a per-organisation setting
 * (organizations.default_revenue_account_code); credit notes follow their invoice.
 */
class InvoiceRevenueAccountTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->customer = Contact::create(['organization_id' => $this->org->id, 'name' => 'Test Client AG']);
    }

    private function account(string $code, AccountType $type = AccountType::Revenue): Account
    {
        return Account::withoutGlobalScopes()->firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => $code],
            ['name' => "Account {$code}", 'type' => $type->value],
        );
    }

    private function baseAccounts(): void
    {
        $this->account('1100', AccountType::Asset);
        $this->account('2200', AccountType::Liability);
    }

    private function finalizedInvoice(string $number = 'INV-2026-001'): Invoice
    {
        $invoice = app(CreateInvoiceAction::class)->execute(CreateInvoiceData::fromArray([
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'number' => $number,
            'issue_date' => '2026-03-15',
            'due_date' => '2026-04-15',
            'currency' => 'CHF',
            'lines' => [['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '500.00']],
        ]));

        return app(FinalizeInvoiceAction::class)->execute($invoice);
    }

    /** @return array<string, string> account code => net revenue amount (credit - debit) */
    private function revenueByCode(Invoice $invoice): array
    {
        $invoice->load('journalEntry.lines.account');
        $result = [];
        foreach ($invoice->journalEntry->lines as $line) {
            if ($line->account->type === AccountType::Revenue) {
                $result[$line->account->code] = bcsub((string) $line->credit, (string) $line->debit, 2);
            }
        }

        return $result;
    }

    public function test_sme_template_books_to_3400_and_association_template_keeps_3000(): void
    {
        $setup = app(OrganizationSetupService::class);

        $sme = Organization::factory()->create(['locale' => 'de']);
        $setup->seedChartOfAccounts($sme, 'swiss_sme');
        $this->assertSame('3400', $sme->fresh()->default_revenue_account_code);
        $sme3400 = Account::withoutGlobalScopes()->where('organization_id', $sme->id)->where('code', '3400')->firstOrFail();
        $sme3000 = Account::withoutGlobalScopes()->where('organization_id', $sme->id)->where('code', '3000')->firstOrFail();
        $this->assertTrue($sme3400->is_system);
        $this->assertFalse($sme3000->is_system);
        $this->assertSame('Dienstleistungserlöse', $sme3400->name);

        $association = Organization::factory()->create(['locale' => 'en']);
        $setup->seedChartOfAccounts($association, 'swiss_association');
        $this->assertSame('3000', $association->fresh()->default_revenue_account_code);
        $membership = Account::withoutGlobalScopes()->where('organization_id', $association->id)->where('code', '3000')->firstOrFail();
        $sponsoring = Account::withoutGlobalScopes()->where('organization_id', $association->id)->where('code', '3400')->firstOrFail();
        $this->assertTrue($membership->is_system);
        $this->assertFalse($sponsoring->is_system);
        $this->assertSame('Membership Fees', $membership->name);
    }

    public function test_an_organisation_without_a_chart_gets_the_default_services_account(): void
    {
        $org = Organization::factory()->create(['locale' => 'fr']);
        app(OrganizationSetupService::class)->ensureSystemAccounts($org);

        // Stored at creation: a later change of the default does not move this organisation.
        $this->assertSame('3400', $org->fresh()->default_revenue_account_code);
        $account = Account::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', '3400')->firstOrFail();
        $this->assertSame('Ventes de prestations', $account->name);
        $this->assertFalse(Account::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', '3000')->exists());
    }

    public function test_invoice_books_revenue_to_the_organisation_setting(): void
    {
        $this->baseAccounts();
        $this->account('3000');
        $this->account('3400');
        $this->org->update(['default_revenue_account_code' => '3000']);

        $this->assertSame(['3000' => '1000.00'], $this->revenueByCode($this->finalizedInvoice()));
    }

    public function test_finalising_creates_only_the_missing_revenue_account_instead_of_failing(): void
    {
        $this->baseAccounts();
        $this->org->update(['default_revenue_account_code' => '3200', 'locale' => 'de']);
        $accountsBefore = Account::withoutGlobalScopes()->where('organization_id', $this->org->id)->count();

        $invoice = $this->finalizedInvoice();

        $this->assertSame(['3200' => '1000.00'], $this->revenueByCode($invoice));
        $created = Account::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('code', '3200')->firstOrFail();
        $this->assertTrue($created->is_system);
        $this->assertSame(AccountType::Revenue, $created->type);
        // Named like the templates' services account, in the organisation's language.
        $this->assertSame('Dienstleistungserlöse', $created->name);
        // No other system account (bank, opening balance, ...) was added on the way.
        $this->assertSame($accountsBefore + 1, Account::withoutGlobalScopes()->where('organization_id', $this->org->id)->count());
    }

    public function test_credit_note_takes_revenue_back_from_the_account_of_its_invoice(): void
    {
        $this->baseAccounts();
        $this->account('3000');
        $this->account('3400');
        $this->org->update(['default_revenue_account_code' => '3000']);
        $invoice = $this->finalizedInvoice();

        // The organisation switches to 3400 afterwards: new invoices go there,
        // but the credit note of the old invoice reverses 3000.
        $this->org->update(['default_revenue_account_code' => '3400']);
        $creditNote = app(FinalizeInvoiceAction::class)->execute(app(CreateCreditNoteAction::class)->execute($invoice));

        $this->assertSame(['3000' => '-1000.00'], $this->revenueByCode($creditNote));
        $this->assertSame(['3400' => '1000.00'], $this->revenueByCode($this->finalizedInvoice('INV-2026-002')));
    }

    public function test_settings_update_the_revenue_account(): void
    {
        $this->account('3000');
        $services = $this->account('3400');
        $bank = $this->account('1020', AccountType::Asset);
        $other = Organization::factory()->create();
        Account::withoutGlobalScopes()->create(['organization_id' => $other->id, 'code' => '3600', 'name' => 'Other', 'type' => AccountType::Revenue->value]);

        $this->actAsOrg()->get('/settings')->assertOk()->assertInertia(fn ($page) => $page
            ->where('revenueAccountCode', '3400')
            ->where('revenueAccounts', fn ($accounts) => collect($accounts)->pluck('code')->all() === ['3000', '3400']));

        $this->actAsOrg()->put('/settings/revenue-account', ['default_revenue_account_code' => $bank->code])
            ->assertSessionHasErrors('default_revenue_account_code');
        $this->actAsOrg()->put('/settings/revenue-account', ['default_revenue_account_code' => '3600'])
            ->assertSessionHasErrors('default_revenue_account_code');

        $this->actAsOrg()->put('/settings/revenue-account', ['default_revenue_account_code' => '3400'])
            ->assertSessionHasNoErrors()->assertRedirect('/settings');
        $this->assertSame('3400', $this->org->fresh()->default_revenue_account_code);
        $this->assertTrue($services->fresh()->is_system);
    }

    public function test_migration_keeps_organisations_on_3000_only_where_3000_was_the_system_revenue_account(): void
    {
        $migration = require database_path('migrations/2026_11_02_120000_add_default_revenue_account_code_to_organizations_table.php');
        // Created before ac317775: 3000 is the system revenue account (template or ensureSystemAccounts).
        $legacy = Organization::factory()->create();
        Account::withoutGlobalScopes()->create(['organization_id' => $legacy->id, 'code' => '3000', 'name' => 'Revenue from Services', 'type' => AccountType::Revenue->value, 'is_system' => true]);
        // 3000 present but not the system account (relabelled chart), and no 3000 at all.
        $relabelled = Organization::factory()->create();
        Account::withoutGlobalScopes()->create(['organization_id' => $relabelled->id, 'code' => '3000', 'name' => 'Revenue from Manufactured Goods', 'type' => AccountType::Revenue->value, 'is_system' => false]);
        $without = Organization::factory()->create();

        // PostgreSQL DDL is transactional: RefreshDatabase rolls this back.
        $migration->down();
        $migration->up();

        $codes = DB::table('organizations')->whereIn('id', [$legacy->id, $relabelled->id, $without->id])->pluck('default_revenue_account_code', 'id');
        $this->assertSame('3000', $codes[$legacy->id]);
        $this->assertNull($codes[$relabelled->id]);
        $this->assertNull($codes[$without->id]);
        $this->assertSame('3400', Organization::query()->findOrFail($without->id)->revenueAccountCode());
    }
}
