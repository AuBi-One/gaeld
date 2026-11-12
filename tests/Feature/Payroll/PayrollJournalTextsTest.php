<?php

namespace Tests\Feature\Payroll;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Payroll\Actions\PostPayrollAction;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Payroll journal texts are written in the organization's language, whoever
 * posts. The credit lines stay grouped per liability account (D27/D38).
 *
 * Idea from Ideall-Engineering/Gaeld 84e3b0c4 (texts only).
 */
class PayrollJournalTextsTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        foreach ([
            ['code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value],
            ['code' => '5000', 'name' => 'Salaries', 'type' => AccountType::Expense->value],
            ['code' => '5700', 'name' => 'Social Charges', 'type' => AccountType::Expense->value],
            ['code' => '2270', 'name' => 'AVS Payable', 'type' => AccountType::Liability->value],
            ['code' => '2271', 'name' => 'AC Payable', 'type' => AccountType::Liability->value],
            ['code' => '2272', 'name' => 'LPP Payable', 'type' => AccountType::Liability->value],
        ] as $account) {
            Account::create(array_merge($account, ['organization_id' => $this->org->id]));
        }

        $this->employee = Employee::create([
            'organization_id' => $this->org->id,
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'entry_date' => '2025-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
        ]);
    }

    /** @return list<string> */
    private function postAndDescribe(): array
    {
        $slip = app(PayrollCalculator::class)->calculate($this->employee, 3, 2026);
        $slip->save();
        $entry = app(PostPayrollAction::class)->execute($slip)->journalEntry()->with('lines')->firstOrFail();

        return [$entry->description, ...$entry->lines->pluck('description')->all()];
    }

    public function test_texts_follow_the_organization_language_not_the_poster(): void
    {
        $this->org->update(['locale' => 'fr']);
        App::setLocale('en');

        $texts = $this->postAndDescribe();

        $this->assertSame('Salaire Max Muster — 3/2026', $texts[0]);
        $this->assertContains('Salaire brut : Max Muster', $texts);
        $this->assertContains('Charges sociales patronales : Max Muster', $texts);
        $this->assertContains('Salaire net versé : Max Muster', $texts);
        $this->assertNotEmpty(array_filter($texts, fn (string $text): bool => str_starts_with($text, 'Charges sociales : ')));
        $this->assertEmpty(array_filter($texts, fn (string $text): bool => str_contains($text, 'Gross salary')));
        $this->assertSame('en', App::getLocale(), 'Posting must not switch the request locale.');
    }

    public function test_german_organization(): void
    {
        $this->org->update(['locale' => 'de']);

        $texts = $this->postAndDescribe();

        $this->assertSame('Lohn Max Muster — 3/2026', $texts[0]);
        $this->assertContains('Nettolohn ausbezahlt: Max Muster', $texts);
    }

    public function test_credits_stay_one_line_per_liability_account(): void
    {
        $slip = app(PayrollCalculator::class)->calculate($this->employee, 3, 2026);
        $slip->save();
        $lines = app(PostPayrollAction::class)->execute($slip)->journalEntry()->with('lines')->firstOrFail()->lines;

        $liabilityIds = Account::where('organization_id', $this->org->id)->where('type', AccountType::Liability->value)->pluck('id');
        $credits = $lines->whereIn('account_id', $liabilityIds->all());

        $this->assertSame($credits->pluck('account_id')->unique()->count(), $credits->count());
    }
}
