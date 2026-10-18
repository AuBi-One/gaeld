<?php

use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\Models\Account;
use Illuminate\Database\Migrations\Migration;

/**
 * Account 3000 was mislabeled "services" and 3400 "other revenue" — the
 * reverse of the Swiss KMU chart (3000 = manufactured goods, 3400 =
 * services). AccountCode::REVENUE (invoice default) moves from 3000 to
 * 3400 to match. Already-seeded accounts keep the old names and is_system
 * flag until renamed here; the chart templates and seeders are fixed
 * separately for new organizations.
 */
return new class extends Migration
{
    /** @var array<string, string> old name => new name */
    private const RENAMES = [
        'Revenue from Services' => 'Revenue from Manufactured Goods',
        'Produits des prestations de services' => 'Ventes de produits fabriqués',
        'Other Revenue' => 'Revenue from Services',
        'Autres produits' => 'Vente de prestations',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $old => $new) {
            Account::withoutGlobalScopes()->whereIn('code', ['3000', '3400'])->where('name', $old)->update(['name' => $new]);
        }

        Account::withoutGlobalScopes()->where('code', '3000')->update(['is_system' => false]);
        Account::withoutGlobalScopes()->where('code', AccountCode::REVENUE)->update(['is_system' => true]);
    }

    public function down(): void
    {
        foreach (self::RENAMES as $old => $new) {
            Account::withoutGlobalScopes()->whereIn('code', ['3000', '3400'])->where('name', $new)->update(['name' => $old]);
        }

        Account::withoutGlobalScopes()->where('code', '3000')->update(['is_system' => true]);
        Account::withoutGlobalScopes()->where('code', AccountCode::REVENUE)->update(['is_system' => false]);
    }
};
