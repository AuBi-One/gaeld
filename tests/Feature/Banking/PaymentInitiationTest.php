<?php

namespace Tests\Feature\Banking;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Queries\ContactQuery;
use App\Domains\Expenses\Enums\ExpenseStatus;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

class PaymentInitiationTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $bank = Account::create([
            'organization_id' => $this->organization->id,
            'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value,
        ]);

        $this->bankAccount = BankAccount::create([
            'organization_id' => $this->organization->id,
            'account_id' => $bank->id,
            'name' => 'Main',
            'iban' => 'CH9300762011623852957',
            'currency' => 'CHF',
            'balance' => 1000,
        ]);
    }

    private function createPayableExpense(?string $supplierIban = 'CH4431999123000889012'): Expense
    {
        $supplier = Contact::create([
            'organization_id' => $this->organization->id,
            'name' => 'Supplier '.uniqid(),
            'email' => uniqid('s').'@example.com',
            'city' => 'Bern',
            'country' => 'CH',
            'currency' => 'CHF',
            'iban' => $supplierIban,
        ]);

        return Expense::create([
            'organization_id' => $this->organization->id,
            'supplier_id' => $supplier->id,
            'category' => 'consulting',
            'description' => 'Audit fee',
            'amount' => '750.00',
            'vat_amount' => '0.00',
            'date' => now()->toDateString(),
            'status' => ExpenseStatus::Approved->value,
            'currency' => 'CHF',
        ]);
    }

    public function test_index_lists_payable_expenses_for_current_org(): void
    {
        $this->createPayableExpense();
        // Excluded: no supplier IBAN
        $this->createPayableExpense(null);
        // Excluded: cross-org leak check
        $otherOrgExpense = Expense::create([
            'organization_id' => Organization::factory()->create()->id,
            'category' => 'consulting',
            'description' => 'Other org',
            'amount' => '99.00',
            'vat_amount' => '0.00',
            'date' => now()->toDateString(),
            'status' => ExpenseStatus::Approved->value,
            'currency' => 'CHF',
        ]);

        $this->actAsOrg()
            ->get('/payments/outgoing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Banking/PaymentsOutgoing')
                ->has('expenses', 1)
                ->where('expenses.0.description', 'Audit fee')
                ->has('bankAccounts', 1)
            );
    }

    public function test_download_returns_pain001_xml(): void
    {
        $expense = $this->createPayableExpense();

        $response = $this->actAsOrg()->post('/payments/outgoing/download', [
            'bank_account_id' => $this->bankAccount->id,
            'expense_ids' => [$expense->id],
            'execution_date' => now()->addDay()->toDateString(),
        ]);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/xml; charset=utf-8');
        $body = (string) $response->getContent();
        $this->assertStringContainsString('urn:iso:std:iso:20022:tech:xsd:pain.001.001.09', $body);
        $this->assertStringContainsString('CH9300762011623852957', $body);
        $this->assertStringContainsString('CH4431999123000889012', $body);
        $this->assertStringContainsString('<CtrlSum>750.00</CtrlSum>', $body);
    }

    public function test_download_rejects_empty_selection(): void
    {
        $this->actAsOrg()->post('/payments/outgoing/download', [
            'bank_account_id' => $this->bankAccount->id,
            'expense_ids' => [],
        ])->assertSessionHasErrors('expense_ids');
    }

    public function test_download_writes_the_supplier_address_and_flags_incomplete_ones(): void
    {
        $expense = $this->createPayableExpense();
        $expense->supplier->update([
            'address' => 'Bahnhofstrasse 10',
            'postal_code' => '8001',
            'city' => 'Zürich',
            'country' => 'CH',
        ]);
        $incomplete = $this->createPayableExpense();
        $incomplete->supplier->update(['city' => null, 'name' => 'No Town AG']);

        $this->actAsOrg()
            ->get('/payments/outgoing')
            ->assertInertia(fn ($page) => $page
                ->where('expenses', fn ($rows) => collect($rows)->pluck('supplier.has_address', 'id')->all() === [
                    $expense->id => true,
                    $incomplete->id => false,
                ]));

        $body = (string) $this->actAsOrg()->post('/payments/outgoing/download', [
            'bank_account_id' => $this->bankAccount->id,
            'expense_ids' => [$expense->id],
            'execution_date' => now()->addDay()->toDateString(),
        ])->assertOk()->getContent();

        $this->assertStringContainsString(
            '<PstlAdr><StrtNm>Bahnhofstrasse 10</StrtNm><PstCd>8001</PstCd><TwnNm>Zürich</TwnNm><Ctry>CH</Ctry></PstlAdr>',
            (string) preg_replace('/>\s+</', '><', $body),
        );
    }

    public function test_download_refuses_a_supplier_without_town_or_country(): void
    {
        $expense = $this->createPayableExpense();
        $expense->supplier->update(['city' => null, 'name' => 'No Town AG']);

        $this->actAsOrg()->post('/payments/outgoing/download', [
            'bank_account_id' => $this->bankAccount->id,
            'expense_ids' => [$expense->id],
            'execution_date' => now()->addDay()->toDateString(),
        ])->assertRedirect()->assertSessionHas('error', fn ($m) => str_contains((string) $m, 'No Town AG'));
    }

    public function test_payment_file_uses_the_account_holder_and_falls_back_to_the_contact_name(): void
    {
        $withHolder = $this->createPayableExpense();
        $withHolder->supplier->update(['name' => 'DRP Trading Name', 'account_holder' => 'Holder Name SA']);
        $withoutHolder = $this->createPayableExpense();
        $withoutHolder->supplier->update(['name' => 'Plain Contact AG', 'account_holder' => '  ']);

        $body = (string) $this->actAsOrg()->post('/payments/outgoing/download', [
            'bank_account_id' => $this->bankAccount->id,
            'expense_ids' => [$withHolder->id, $withoutHolder->id],
            'execution_date' => now()->addDay()->toDateString(),
        ])->assertOk()->getContent();

        $doc = new \DOMDocument;
        $doc->loadXML($body);
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.09');
        $names = [];
        foreach ($xp->query('//p:CdtTrfTxInf/p:Cdtr/p:Nm') as $n) {
            $names[] = $n->textContent;
        }
        sort($names);

        $this->assertSame(['Holder Name SA', 'Plain Contact AG'], $names);
    }

    public function test_contact_form_stores_and_clears_the_account_holder(): void
    {
        $this->actAsOrg()->post('/contacts', [
            'name' => 'Holder Test AG',
            'country' => 'CH',
            'currency' => 'CHF',
            'account_holder' => 'Holder Test SA',
        ])->assertRedirect();
        $contact = Contact::where('name', 'Holder Test AG')->firstOrFail();
        $this->assertSame('Holder Test SA', $contact->account_holder);

        $this->actAsOrg()->put("/contacts/{$contact->uuid}", [
            'name' => 'Holder Test AG',
            'country' => 'CH',
            'currency' => 'CHF',
            'account_holder' => '',
        ])->assertRedirect();
        $this->assertNull($contact->fresh()->account_holder);

        $this->actAsOrg()->post('/contacts', [
            'name' => 'Too Long AG',
            'account_holder' => str_repeat('x', 71),
        ])->assertSessionHasErrors('account_holder');
    }

    public function test_bank_import_matches_a_supplier_by_its_account_holder(): void
    {
        $expense = $this->createPayableExpense();
        $expense->supplier->update([
            'name' => 'Trading Name GmbH',
            'account_holder' => 'Registered Holder SA',
            'default_expense_category' => 'consulting',
        ]);
        $orgId = $this->organization->id;

        $this->assertTrue(ContactQuery::hasMatchingSupplier($orgId, 'Registered Holder SA'));
        $this->assertSame($expense->supplier_id, ContactQuery::findByCreditorName($orgId, 'Registered Holder SA')?->id);
        $this->assertSame($expense->supplier_id, ContactQuery::findByCreditorName($orgId, 'Trading Name')?->id);
        $this->assertFalse(ContactQuery::hasMatchingSupplier($orgId, 'Someone Else'));
    }

    public function test_download_rejects_an_expense_from_another_organization(): void
    {
        $otherOrganization = Organization::factory()->create();
        $foreignExpense = Expense::create([
            'organization_id' => $otherOrganization->id,
            'category' => 'consulting',
            'description' => 'Foreign expense',
            'amount' => '99.00',
            'vat_amount' => '0.00',
            'date' => now()->toDateString(),
            'status' => ExpenseStatus::Approved->value,
            'currency' => 'CHF',
        ]);

        $this->actAsOrg()->post('/payments/outgoing/download', [
            'bank_account_id' => $this->bankAccount->id,
            'expense_ids' => [$foreignExpense->id],
        ])->assertSessionHasErrors('expense_ids.0');
    }
}
