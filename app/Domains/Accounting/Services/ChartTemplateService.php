<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\ChartTemplates\AccountDefinition;
use App\Domains\Accounting\ChartTemplates\ChartTemplateInterface;
use App\Domains\Accounting\ChartTemplates\SwissAssociationTemplate;
use App\Domains\Accounting\ChartTemplates\SwissFreelancerTemplate;
use App\Domains\Accounting\ChartTemplates\SwissSmeTemplate;
use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Organizations\Models\Organization;

/**
 * Manages chart-of-accounts templates and applies them to organizations.
 *
 * Provides a registry of available Swiss accounting templates (Freelancer,
 * SME, Association) and seeds the selected template into an organization's
 * account table.
 */
class ChartTemplateService
{
    /** @var array<string, ChartTemplateInterface> */
    private array $templates;

    public function __construct()
    {
        $this->templates = collect([
            new SwissSmeTemplate,
            new SwissFreelancerTemplate,
            new SwissAssociationTemplate,
        ])->keyBy(fn (ChartTemplateInterface $t) => $t->key())->all();
    }

    // ──────────────────────────────────────────────────────────────
    //  Template Discovery
    // ──────────────────────────────────────────────────────────────

    /**
     * List available templates with their translation keys.
     *
     * @return array<int, array{key: string, label_key: string, description_key: string}>
     */
    public function availableTemplates(): array
    {
        return collect($this->templates)->map(fn (ChartTemplateInterface $t) => [
            'key' => $t->key(),
            'label_key' => $t->labelKey(),
            'description_key' => $t->descriptionKey(),
        ])->values()->all();
    }

    /**
     * List valid template keys (for validation rules).
     *
     * @return string[]
     */
    public function validKeys(): array
    {
        return array_keys($this->templates);
    }

    /**
     * Get the template instance by key.
     */
    public function resolve(string $key): ?ChartTemplateInterface
    {
        return $this->templates[$key] ?? null;
    }

    // ──────────────────────────────────────────────────────────────
    //  Template Seeding
    // ──────────────────────────────────────────────────────────────

    /**
     * Seed a chart of accounts for an organization using the given template.
     * Account names are resolved to the organization's locale.
     */
    public function seedTemplate(Organization $organization, string $templateKey): void
    {
        $template = $this->resolve($templateKey);

        if (! $template) {
            return;
        }

        $locale = $organization->locale ?? 'en';

        // The template decides where invoice revenue goes, unless the organisation already chose.
        if ($organization->default_revenue_account_code === null) {
            $organization->forceFill(['default_revenue_account_code' => $template->revenueAccountCode()])->save();
        }

        $systemCodes = array_filter(
            (new \ReflectionClass(AccountCode::class))->getConstants(),
            fn ($v, $name) => is_string($v) && ctype_digit($v) && $name !== 'REVENUE',
            ARRAY_FILTER_USE_BOTH,
        );
        $systemCodes[] = $organization->revenueAccountCode();

        foreach ($template->accounts() as $account) {
            $def = new AccountDefinition(
                code: $account['code'],
                type: AccountType::from($account['type']),
                name: $account['name'],
            );

            $name = $def->name[$locale] ?? $def->name['en'];

            // Use firstOrCreate keyed on (organization_id, code) so the seeder is
            // idempotent and safe against double form submissions or retries that
            // would otherwise trigger accounts_organization_id_code_unique.
            Account::withoutGlobalScopes()->firstOrCreate(
                [
                    'organization_id' => $organization->id,
                    'code' => $def->code,
                ],
                [
                    'name' => $name,
                    'type' => $def->type->value,
                    'is_system' => in_array($def->code, $systemCodes, true),
                ],
            );
        }
    }

    /**
     * Ensure the mandatory system accounts exist for an organization.
     *
     * These accounts are required by core operations (invoice posting,
     * payment recording, VAT settlement) and must exist even when the
     * user chooses a custom chart of accounts or selects 'none'.
     */
    public function ensureSystemAccounts(Organization $organization): void
    {
        $locale = $organization->locale ?? 'en';

        // From now on the code is the organisation's own: AccountCode::REVENUE only decides at creation.
        if ($organization->default_revenue_account_code === null) {
            $organization->forceFill(['default_revenue_account_code' => AccountCode::REVENUE])->save();
        }

        foreach ($this->systemAccounts($organization->revenueAccountCode()) as $account) {
            // firstOrCreate replaces the previous pluck-then-check pattern, which
            // was racy under concurrent organization creation requests.
            Account::withoutGlobalScopes()->firstOrCreate(
                [
                    'organization_id' => $organization->id,
                    'code' => $account['code'],
                ],
                [
                    'name' => $account['name'][$locale] ?? $account['name']['en'],
                    'type' => $account['type']->value,
                    'is_system' => true,
                ],
            );
        }
    }

    /**
     * Every name the templates and the system accounts give an account code, in any locale. A stored
     * name among them was seeded, not chosen by the organisation (AccountDisplayName translates it).
     * When a template's wording changes, keep the old wording here too, or accounts seeded under it
     * stop being translated.
     *
     * @return array<string, list<string>>
     */
    public function seededNames(): array
    {
        $names = [];
        $add = function (string $code, array $byLocale) use (&$names): void {
            foreach ($byLocale as $name) {
                $names[$code][] = (string) $name;
            }
        };
        foreach ($this->templates as $template) {
            foreach ($template->accounts() as $account) {
                $add($account['code'], (array) $account['name']);
            }
            $add($template->revenueAccountCode(), $this->servicesAccountNames()); // ensureRevenueAccount()
        }
        foreach ($this->systemAccounts(AccountCode::REVENUE) as $account) {
            $add($account['code'], $account['name']);
        }

        return array_map(fn (array $list): array => array_values(array_unique($list)), $names);
    }

    /**
     * The mandatory system accounts, with their names per locale.
     *
     * @return list<array{code: string, type: AccountType, name: array<string, string>}>
     */
    private function systemAccounts(string $revenueCode): array
    {
        return [
            ['code' => AccountCode::BANK_CASH, 'type' => AccountType::Asset, 'name' => [
                'en' => 'Bank Account CHF', 'fr' => 'Compte bancaire CHF', 'de' => 'Bankkonto CHF', 'it' => 'Conto bancario CHF',
            ]],
            ['code' => AccountCode::ACCOUNTS_RECEIVABLE, 'type' => AccountType::Asset, 'name' => [
                'en' => 'Accounts Receivable', 'fr' => 'Débiteurs', 'de' => 'Debitoren', 'it' => 'Debitori',
            ]],
            ['code' => AccountCode::VAT_INPUT, 'type' => AccountType::Asset, 'name' => [
                'en' => 'VAT Input Tax', 'fr' => 'Impôt préalable', 'de' => 'Vorsteuer', 'it' => 'Imposta precedente',
            ]],
            ['code' => AccountCode::VAT_OUTPUT, 'type' => AccountType::Liability, 'name' => [
                'en' => 'VAT Output Tax', 'fr' => 'TVA due', 'de' => 'Umsatzsteuer (MWST)', 'it' => 'IVA dovuta',
            ]],
            ['code' => AccountCode::VAT_PAYABLE_AFC, 'type' => AccountType::Liability, 'name' => [
                'en' => 'VAT Payable AFC', 'fr' => 'TVA à payer AFC', 'de' => 'MWST-Zahllast ESTV', 'it' => 'IVA da versare AFC',
            ]],
            ['code' => $revenueCode, 'type' => AccountType::Revenue, 'name' => $this->servicesAccountNames()],
            ['code' => AccountCode::ROUNDING_DIFFERENCE, 'type' => AccountType::Revenue, 'name' => [
                'en' => 'Revenue Corrections', 'fr' => 'Corrections de produits', 'de' => 'Erlösberichtigungen', 'it' => 'Rettifiche di ricavi',
            ]],
            ['code' => AccountCode::OPENING_BALANCE, 'type' => AccountType::Equity, 'name' => [
                'en' => 'Opening Balance', 'fr' => 'Bilan d\'ouverture', 'de' => 'Eröffnungsbilanz', 'it' => 'Bilancio di apertura',
            ]],
        ];
    }

    /**
     * The organisation's invoice revenue account, created (as a system account named
     * like the templates' services account) when its chart lacks it.
     */
    public function ensureRevenueAccount(Organization $organization): Account
    {
        $names = $this->servicesAccountNames();

        return Account::withoutGlobalScopes()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'code' => $organization->revenueAccountCode(),
            ],
            [
                'name' => $names[$organization->locale ?? 'en'] ?? $names['en'],
                'type' => AccountType::Revenue->value,
                'is_system' => true,
            ],
        );
    }

    /**
     * The services account's name per locale, as the SME template labels it.
     *
     * @return array<string, string>
     */
    private function servicesAccountNames(): array
    {
        $template = new SwissSmeTemplate;
        foreach ($template->accounts() as $account) {
            if ($account['code'] === $template->revenueAccountCode()) {
                return $account['name'];
            }
        }

        return ['en' => 'Revenue from Services'];
    }

    /**
     * Whether the template also provides VAT rates.
     */
    public function templateSeedsVatRates(string $templateKey): bool
    {
        $template = $this->resolve($templateKey);

        return $template?->seedsVatRates() ?? false;
    }
}
