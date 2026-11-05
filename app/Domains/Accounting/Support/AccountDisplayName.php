<?php

namespace App\Domains\Accounting\Support;

use App\Domains\Accounting\Services\ChartTemplateService;
use Illuminate\Support\Facades\Lang;

/**
 * Resolves a localized display name for an account code.
 *
 * Seeded system accounts have translations under `lang/{locale}/accounts.php`,
 * used while the account keeps a name Gäld seeded for its code (any template,
 * any locale). User-created and renamed accounts show the stored name.
 */
final class AccountDisplayName
{
    /** @var array<string, array<string, true>>|null code => set of seeded names */
    private static ?array $seeded = null;

    public static function for(string $code, ?string $fallback = null): string
    {
        $key = 'accounts.'.$code;

        if (Lang::has($key) && ($fallback === null || self::isSeeded($code, $fallback))) {
            return (string) __($key);
        }

        return (string) ($fallback ?? $code);
    }

    private static function isSeeded(string $code, string $name): bool
    {
        if (self::$seeded === null) {
            $names = app(ChartTemplateService::class)->seededNames();
            // The translations themselves count as seeded names (older seeders stored them)
            foreach (glob(lang_path('*/accounts.php')) ?: [] as $file) {
                foreach ((array) Lang::get('accounts', [], basename(dirname($file)), false) as $c => $n) {
                    $names[(string) $c][] = (string) $n;
                }
            }
            self::$seeded = array_map(fn (array $list): array => array_fill_keys($list, true), $names);
        }

        return isset(self::$seeded[$code][$name]);
    }
}
