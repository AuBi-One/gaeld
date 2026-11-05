<?php

namespace Tests\Feature\Accounting;

use App\Domains\Accounting\Models\Account;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Services\OrganizationSetupService;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Traits\WithActiveSubscription;
use Tests\Traits\WithOrganizationPermissions;

class AccountDisplayNameTest extends TestCase
{
    use RefreshDatabase, WithActiveSubscription, WithOrganizationPermissions;

    private User $owner;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPermissions();
        $this->owner = User::factory()->create();
        $this->organization = Organization::create(['name' => 'Display Name Org', 'currency' => 'CHF', 'locale' => 'fr']);
        $this->organization->users()->attach($this->owner->id, ['role' => 'owner']);
        $this->assignOrganizationRole($this->owner, $this->organization, 'owner');
        $this->ensureSubscriptionIfSaas($this->organization);
        app(OrganizationSetupService::class)->seedChartOfAccounts($this->organization, 'swiss_sme');
    }

    private function account(string $code): Account
    {
        return Account::withoutGlobalScopes()->where('organization_id', $this->organization->id)->where('code', $code)->firstOrFail();
    }

    private function asOwner(): self
    {
        return $this->actingAs($this->owner)->withSession(['current_organization_id' => $this->organization->id]);
    }

    private function rename(string $code, string $name): void
    {
        $account = $this->account($code);
        $this->asOwner()->put(route('accounting.accounts.update', $account), [
            'name' => $name, 'code' => $account->code, 'type' => $account->type->value, 'is_active' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();
    }

    /** The name the journal page's account picker shows for a code (a partial select: id, code, name, type). */
    private function journalPickerName(string $code): string
    {
        $name = null;
        $this->asOwner()->get(route('accounting.journal'))->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($code, &$name): void {
                $name = collect($page->toArray()['props']['accounts'])->firstWhere('code', $code)['name'] ?? null;
            });

        return (string) $name;
    }

    public function test_a_seeded_account_is_shown_under_its_translation_in_the_viewers_locale(): void
    {
        App::setLocale('de');

        $this->assertSame(__('accounts.6500'), $this->account('6500')->display_name);
        $this->assertNotSame($this->account('6500')->name, $this->account('6500')->display_name);
    }

    public function test_a_renamed_seeded_account_is_shown_under_its_new_name_everywhere(): void
    {
        $this->rename('6500', "Frais d'administration");

        foreach (['fr', 'de', 'en'] as $locale) {
            App::setLocale($locale);
            $this->assertSame("Frais d'administration", $this->account('6500')->display_name);
        }
        $this->assertSame("Frais d'administration", $this->journalPickerName('6500'));
    }

    public function test_renaming_back_to_a_seeded_name_brings_the_translation_back(): void
    {
        $seeded = $this->account('6500')->name;
        $this->rename('6500', "Frais d'administration");
        $this->rename('6500', $seeded);

        App::setLocale('de');
        $this->assertSame(__('accounts.6500'), $this->account('6500')->display_name);
    }

    public function test_an_account_created_on_a_translated_code_shows_its_own_name(): void
    {
        $this->account('6510')->delete();
        Account::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id, 'code' => '6510', 'name' => 'Abonnements logiciels', 'type' => 'expense',
        ]);

        $this->assertSame('Abonnements logiciels', $this->account('6510')->display_name);
    }
}
