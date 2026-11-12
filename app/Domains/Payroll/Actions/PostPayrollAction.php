<?php

namespace App\Domains\Payroll\Actions;

use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Services\LedgerQueryService;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Contracts\ReimbursementSourceInterface;
use App\Domains\Payroll\Contracts\SourceTaxServiceInterface;
use App\Domains\Payroll\Exceptions\PostingDateOutsidePeriodException;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Services\NullReimbursementSource;
use App\Domains\Payroll\Services\PayrollPeriod;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Posts a salary slip to the accounting ledger (gross salary, deductions, net pay).
 */
class PostPayrollAction
{
    /**
     * Deduction-code prefix => fixed account, used when a code has no
     * explicit account mapping on its deduction rate line (none configured,
     * or the organisation has no rate set and the built-in defaults were
     * used, which carry no account at all). Keeps pre-existing organisations
     * posting exactly where they always did.
     */
    private const LEGACY_ACCOUNT_BY_PREFIX = [
        'avs_' => AccountCode::AVS_PAYABLE,
        'aanp_' => AccountCode::AVS_PAYABLE,
        'ac_' => AccountCode::AC_PAYABLE,
        'lpp_' => AccountCode::LPP_PAYABLE,
    ];

    public function __construct(
        private LedgerService $ledger,
        private LedgerQueryService $ledgerQuery,
        private SendSalarySlipEmailAction $sendEmail,
        private SourceTaxServiceInterface $sourceTax,
        private ReimbursementSourceInterface $reimbursements = new NullReimbursementSource,
    ) {}

    public function execute(SalarySlip $slip): SalarySlip
    {
        $this->ensureSourceTaxApplied($slip);

        $deductions = $slip->deductions;
        $orgId = $slip->organization_id;

        $employee = $slip->employee;
        // Explicitly, not lazily: lazy loading is refused outside production.
        $organization = $slip->relationLoaded('organization')
            ? $slip->organization
            : Organization::query()->find($orgId);
        // Journal texts in the organization's language, not the language of
        // whoever posts: they are written once and read for years.
        $locale = $organization?->locale ?: app()->getLocale();
        $name = $employee->fullName();
        $text = fn (string $key, array $replace = []): string => __('app.'.$key, $replace + ['name' => $name], $locale);
        $description = $text('payroll_journal_description', ['period' => "{$slip->period_month}/{$slip->period_year}"]);

        // Resolve accounts
        $salaryAccount = $this->ledgerQuery->resolveAccount($orgId, AccountCode::SALARIES);
        $socialChargesAccount = $this->ledgerQuery->resolveAccount($orgId, AccountCode::SOCIAL_CHARGES_EMPLOYER);
        $bankAccount = $this->ledgerQuery->resolveAccount($orgId, AccountCode::BANK_CASH);

        $sourceTaxAmount = Money::normalize((string) ($deductions['source_tax'] ?? $slip->source_tax_amount ?? '0.00'));

        $lines = [];

        // Debit: Gross salary
        $lines[] = new JournalLineData(
            accountId: (string) $salaryAccount->id,
            debit: $slip->gross_salary,
            credit: '0',
            description: $text('payroll_journal_gross_salary'),
        );

        // Debit: Employer social charges
        $totalEmployer = $deductions['total_employer'] ?? '0';
        if (Money::isPositive($totalEmployer)) {
            $lines[] = new JournalLineData(
                accountId: (string) $socialChargesAccount->id,
                debit: $totalEmployer,
                credit: '0',
                description: $text('payroll_journal_employer_charges'),
            );
        }

        // Credit: Bank (net salary)
        $lines[] = new JournalLineData(
            accountId: (string) $bankAccount->id,
            debit: '0',
            credit: $slip->net_salary,
            description: $text('payroll_journal_net_salary'),
        );

        // Itemised reimbursements are re-checked against their source (still
        // open, same amount) and debited, summed per account, to the account(s)
        // the source names (optional splits); the manual remainder keeps the
        // general expense account.
        $reimbursementAmount = (string) ($deductions['reimbursement_amount'] ?? '0.00');
        $items = $this->currentReimbursementItems($slip);
        $byAccount = [];
        foreach ($items as $item) {
            $reimbursementAmount = Money::subtract($reimbursementAmount, $item['amount']);
            foreach ($item['splits'] ?? [['account_code' => $item['account_code'], 'amount' => $item['amount']]] as $split) {
                $byAccount[$split['account_code']][] = ['amount' => $split['amount'], 'label' => $item['label']];
            }
        }
        foreach ($byAccount as $accountCode => $group) {
            $lines[] = new JournalLineData(
                accountId: (string) $this->ledgerQuery->resolveAccount($orgId, (string) $accountCode)->id,
                debit: array_reduce($group, fn (string $sum, array $item): string => Money::add($sum, $item['amount']), Money::zero()),
                credit: '0',
                description: mb_strimwidth($text('payroll_journal_reimbursement').' — '.implode(', ', array_column($group, 'label')), 0, 255, '…'),
            );
        }
        if (Money::isPositive($reimbursementAmount)) {
            $reimbursementAccount = $this->ledgerQuery->resolveAccount($orgId, AccountCode::GENERAL_EXPENSE);
            $lines[] = new JournalLineData(
                accountId: (string) $reimbursementAccount->id,
                debit: $reimbursementAmount,
                credit: '0',
                description: $text('payroll_journal_reimbursement'),
            );
        }

        // Credit: every deduction line, grouped by the account its rate is
        // mapped to in Payroll > Charges sociales (falling back to the fixed
        // AVS/AC/LPP accounts when a line has no mapping, so an organisation
        // that never opened that screen posts exactly as before). Grouping
        // by resolved account, not by code, keeps the entry balanced however
        // many distinct deduction codes or accounts are involved: every code
        // present in `deductions` lands in exactly one credit line here.
        foreach ($this->creditsByAccount($slip, $locale) as $accountId => $credit) {
            $lines[] = new JournalLineData(
                accountId: (string) $accountId,
                debit: '0',
                credit: $credit['amount'],
                description: $credit['description'],
            );
        }

        if (Money::isPositive($sourceTaxAmount)) {
            $sourceTaxAccount = $this->ledgerQuery->resolveAccount($orgId, AccountCode::WITHHOLDING_TAX_PAYABLE);
            $lines[] = new JournalLineData(
                accountId: (string) $sourceTaxAccount->id,
                debit: '0',
                credit: $sourceTaxAmount,
                description: $text('payroll_journal_withholding_tax'),
            );
        }

        // Build a human-friendly reference: PAY-<INITIALS>-<YYYY>-<MM>.
        // Falls back to a short employee-id slug when initials are unavailable.
        $initials = collect((array) preg_split('/\s+/', trim((string) $employee->fullName())))
            ->filter()
            ->map(fn ($p) => strtoupper(mb_substr((string) $p, 0, 1)))
            ->take(3)
            ->implode('');
        $tag = $initials !== '' ? $initials : substr((string) $slip->employee_id, 0, 4);
        $monthPad = str_pad((string) $slip->period_month, 2, '0', STR_PAD_LEFT);

        $entry = new JournalEntryData(
            date: self::postingDate($slip, $organization),
            // A slip unposted and posted again: the reversed entry keeps the first reference.
            reference: $this->ledgerQuery->nextReferenceAfterReversals($orgId, "PAY-{$tag}-{$slip->period_year}-{$monthPad}"),
            description: $description,
            lines: $lines,
        );

        DB::transaction(function () use ($orgId, $entry, $slip, $items): void {
            $journalEntry = $this->ledger->postEntry($orgId, $entry);

            $slip->update([
                'journal_entry_id' => $journalEntry->id,
                'posted_at' => now(),
            ]);

            if ($items !== []) {
                $this->reimbursements->settle($slip, $items);
            }
        });

        $postedSlip = $slip->fresh();
        $this->sendEmail->execute($postedSlip);

        return $postedSlip;
    }

    /**
     * The date the slip's entry is posted on: the slip's own posting date,
     * else the organization's payday in the slip's month (a payday past the
     * end of a short month is its last day), else the month's last day, as
     * before posting dates existed.
     *
     * @throws PostingDateOutsidePeriodException when the slip's date is outside its month
     */
    public static function postingDate(SalarySlip $slip, ?Organization $organization): string
    {
        $monthStart = Carbon::create($slip->period_year, $slip->period_month, 1);

        if ($slip->posting_date !== null) {
            $date = $slip->posting_date->toDateString();
            if (! PayrollPeriod::contains($date, $slip->period_month, $slip->period_year)) {
                throw new PostingDateOutsidePeriodException(__('app.payroll_posting_date_outside_period'));
            }

            return $date;
        }

        $payday = $organization?->payroll_payday;
        if ($payday === null || $payday < 1) {
            return $monthStart->copy()->endOfMonth()->toDateString();
        }

        return $monthStart->copy()->day(min($payday, $monthStart->daysInMonth))->toDateString();
    }

    /**
     * Every per-line deduction amount on the slip, grouped by the
     * chart-of-accounts entry it should credit: the account mapped on the
     * rate line the slip was calculated with (its snapshot), never the
     * current sets.
     *
     * @return array<int, array{amount: string, description: string}>
     */
    private function creditsByAccount(SalarySlip $slip, string $locale): array
    {
        $orgId = $slip->organization_id;
        $lines = $slip->rateLines();

        $credits = [];
        foreach ($slip->chargeAmounts() as $code => $amount) {
            if (! Money::isPositive($amount)) {
                continue;
            }

            $replace = ['code' => $code, 'name' => $slip->employee_name, 'period' => "{$slip->period_month}/{$slip->period_year}"];
            $mappedId = $lines[$code]['account_id'] ?? null;
            $accountId = $mappedId !== null
                ? $this->mappedAccountId($orgId, (int) $mappedId, $replace)
                : $this->legacyAccountId($orgId, $code);
            if ($accountId === null) {
                throw new \DomainException(__('app.deduction_account_not_mapped', $replace));
            }

            $name = $lines[$code]['name'] ?? $code;
            if (! isset($credits[$accountId])) {
                $credits[$accountId] = ['amount' => Money::zero(), 'names' => []];
            }
            $credits[$accountId]['amount'] = Money::add($credits[$accountId]['amount'], $amount);
            if (! in_array($name, $credits[$accountId]['names'], true)) {
                $credits[$accountId]['names'][] = $name;
            }
        }

        return array_map(
            fn (array $credit): array => [
                'amount' => $credit['amount'],
                'description' => __('app.payroll_journal_social_charges', ['names' => implode(', ', $credit['names'])], $locale),
            ],
            $credits,
        );
    }

    /**
     * A mapped account must still exist in the organisation and be a
     * liability: it is credited, and the employer share is already debited
     * to the social charges expense.
     *
     * @param  array<string, string>  $replace
     */
    private function mappedAccountId(string $organizationId, int $accountId, array $replace): int
    {
        $account = Account::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereKey($accountId)
            ->first();
        if ($account === null || $account->type !== AccountType::Liability) {
            throw new \DomainException(__('app.deduction_account_not_liability', $replace));
        }

        return (int) $account->id;
    }

    /**
     * The fixed AVS/AC/LPP account a deduction code posted to before any
     * mapping existed, matched by its code prefix. Null for anything else
     * (APGM, allocations familiales, a custom charge, ...), which then needs
     * an explicit mapping.
     */
    private function legacyAccountId(string $organizationId, string $code): ?int
    {
        foreach (self::LEGACY_ACCOUNT_BY_PREFIX as $prefix => $accountCode) {
            if (str_starts_with($code, $prefix)) {
                return (int) $this->ledgerQuery->resolveAccount($organizationId, $accountCode)->id;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, date: string, label: string, amount: string, account_code: string, splits?: list<array{account_code: string, amount: string}>}>
     */
    private function currentReimbursementItems(SalarySlip $slip): array
    {
        $stored = $slip->adjustments['reimbursement_items'] ?? [];
        if ($stored === []) {
            return [];
        }

        $current = $this->reimbursements->resolve(
            (string) $slip->organization_id,
            (string) $slip->employee_id,
            array_map(fn (array $item): string => (string) $item['id'], $stored),
        );

        // Each item must still have the amount stored on the slip, and its splits must add up to it.
        $storedAmounts = array_column($stored, 'amount', 'id');
        foreach ($current as $item) {
            $splits = $item['splits'] ?? [['account_code' => $item['account_code'], 'amount' => $item['amount']]];
            $splitTotal = array_reduce($splits, fn (string $sum, array $split): string => Money::add($sum, $split['amount']), Money::zero());
            if (! isset($storedAmounts[$item['id']])
                || Money::compare((string) $storedAmounts[$item['id']], $item['amount']) !== 0
                || Money::compare($splitTotal, $item['amount']) !== 0) {
                throw new \DomainException('Reimbursement items changed since the salary slip was generated. Delete and regenerate the slip.');
            }
        }

        return $current;
    }

    private function ensureSourceTaxApplied(SalarySlip $slip): void
    {
        if ($slip->source_tax_base === null) {
            $this->sourceTax->applyToSlip($slip, $slip->employee);
        }

        if ($slip->source_tax_base === null) {
            return;
        }

        $deductions = $slip->deductions;
        if (array_key_exists('source_tax', $deductions)) {
            return;
        }

        $sourceTaxAmount = Money::normalize((string) ($slip->source_tax_amount ?? '0.00'));
        $deductions['source_tax'] = $sourceTaxAmount;
        $deductions['net_salary'] = Money::subtract($slip->net_salary, $sourceTaxAmount);
        $slip->forceFill([
            'net_salary' => $deductions['net_salary'],
            'deductions' => $deductions,
        ]);

        if ($slip->exists) {
            $slip->saveQuietly();
        }
    }
}
