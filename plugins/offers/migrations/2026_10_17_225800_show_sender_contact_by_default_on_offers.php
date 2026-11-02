<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The From box's phone/e-mail now default to shown (Layout::DEFAULT) — there
 * is no UI yet to choose otherwise, so every existing draft just has the old
 * hidden default baked into its own `layout` column at creation time.
 *
 * Drafts only: a sent, accepted, refused or superseded offer keeps the layout
 * the client received. Plain table updates, so `updated_at` and the model's
 * future shape do not matter.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setFromContact(true);
    }

    public function down(): void
    {
        $this->setFromContact(false);
    }

    private function setFromContact(bool $shown): void
    {
        DB::table('of_offers')->where('status', 'draft')->orderBy('id')->select(['id', 'layout'])
            ->each(function (object $offer) use ($shown): void {
                $layout = json_decode((string) ($offer->layout ?? ''), true);
                $layout = is_array($layout) ? $layout : [];
                $from = is_array($layout['from'] ?? null) ? $layout['from'] : [];
                $layout['from'] = ['email' => $shown, 'phone' => $shown] + $from;

                DB::table('of_offers')->where('id', $offer->id)->update(['layout' => json_encode($layout)]);
            });
    }
};
