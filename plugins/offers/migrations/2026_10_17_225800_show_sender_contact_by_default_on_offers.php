<?php

use Illuminate\Database\Migrations\Migration;
use Plugins\Offers\Models\Offer;

/**
 * The From box's phone/e-mail now default to shown (Layout::DEFAULT) — there
 * is no UI yet to choose otherwise, so every existing offer just has the old
 * hidden default baked into its own `layout` column at creation time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Offer::query()->withoutGlobalScopes()->each(function (Offer $offer): void {
            $layout = $offer->layout ?? [];
            $layout['from']['email'] = true;
            $layout['from']['phone'] = true;
            $offer->update(['layout' => $layout]);
        });
    }

    public function down(): void
    {
        Offer::query()->withoutGlobalScopes()->each(function (Offer $offer): void {
            $layout = $offer->layout ?? [];
            $layout['from']['email'] = false;
            $layout['from']['phone'] = false;
            $offer->update(['layout' => $layout]);
        });
    }
};
