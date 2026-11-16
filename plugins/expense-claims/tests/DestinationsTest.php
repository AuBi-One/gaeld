<?php

namespace Plugins\ExpenseClaims\Tests;

require_once __DIR__.'/ExpenseClaimsTestCase.php';

use App\Domains\Contacts\Models\Contact;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\Distance;
use Plugins\ExpenseClaims\Models\Person;
use Plugins\ExpenseClaims\Models\Place;

/**
 * Destinations of km lines: contacts and any address (geo.admin is faked).
 */
class DestinationsTest extends ExpenseClaimsTestCase
{
    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['api3.geo.admin.ch/*' => Http::response(['results' => [
            ['attrs' => ['label' => '<b>Rue de Bourg 1</b> 1003 Lausanne', 'lat' => 46.52, 'lon' => 6.63]],
            ['attrs' => ['label' => '<b>Bahnhofstrasse 5</b> 8001 Zürich', 'lat' => 47.37, 'lon' => 8.54]],
        ]])]);
        $this->person = Person::create(['organization_id' => $this->org->id, 'name' => 'Anna Muster', 'employee_id' => $this->employee->id]);
    }

    private function contact(?Organization $org = null, string $address = 'Rue de Bourg 1', string $postal = '1003', string $city = 'Lausanne'): Contact
    {
        return Contact::factory()->create([
            'organization_id' => ($org ?? $this->organization)->id, 'name' => 'Client SA',
            'address' => $address, 'postal_code' => $postal, 'city' => $city, 'country' => 'CH',
        ]);
    }

    /** @return array<string, mixed> */
    private function claimTo(string $to, string $title = 'Visite'): array
    {
        return [
            'person_id' => $this->person->id, 'date' => '2026-04-02', 'title' => $title,
            'lines' => [['type' => 'km', 'to_place_id' => $to, 'km' => '20', 'round_trip' => false]],
        ];
    }

    #[Test]
    public function a_contact_becomes_one_client_place_that_is_reused_and_follows_the_contact_address(): void
    {
        $contact = $this->contact();

        $this->actAsOrg()->post('/expense-claims', $this->claimTo('contact:'.$contact->id, 'First'))->assertSessionHasNoErrors();
        $place = Place::where('contact_id', $contact->id)->sole();
        $this->assertSame(['client', 'Client SA', 'Lausanne'], [$place->kind, $place->label, $place->city]);
        $this->assertEqualsWithDelta(46.52, (float) $place->lat, 0.0001);
        $this->assertSame($place->id, Claim::where('title', 'First')->sole()->lines->sole()->to_place_id);

        // The form now offers the contact by its place, once.
        $this->actAsOrg()->get('/expense-claims/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('contacts.0.value', $place->id)
                ->where('places', fn ($places) => ! collect($places)->contains('id', $place->id)));

        $this->actAsOrg()->post('/expense-claims', $this->claimTo('contact:'.$contact->id, 'Second'))->assertSessionHasNoErrors();
        $this->assertSame(1, Place::where('contact_id', $contact->id)->count());
        $this->assertSame($place->id, Claim::where('title', 'Second')->sole()->lines->sole()->to_place_id);

        // The contact moved: the place follows and its cached distances are forgotten.
        $hq = Place::create(['organization_id' => $this->org->id, 'kind' => 'hq', 'label' => 'Office', 'lat' => 46.5, 'lon' => 6.6]);
        Distance::create(['from_place_id' => $hq->id, 'to_place_id' => $place->id, 'km' => '5.0', 'provider' => 'ors']);
        $contact->update(['address' => 'Bahnhofstrasse 5', 'postal_code' => '8001', 'city' => 'Zürich']);

        $this->actAsOrg()->post('/expense-claims', $this->claimTo($place->id, 'Third'))->assertSessionHasNoErrors();
        $place->refresh();
        $this->assertSame(['Bahnhofstrasse 5', '8001', 'Zürich'], [$place->address, $place->postal_code, $place->city]);
        $this->assertEqualsWithDelta(47.37, (float) $place->lat, 0.0001);
        $this->assertSame(0, Distance::count());
        $this->assertSame(1, Place::where('contact_id', $contact->id)->count());
    }

    #[Test]
    public function a_contact_whose_address_is_not_found_gets_a_place_without_coordinates(): void
    {
        $contact = $this->contact(address: 'Chemin inconnu 9', postal: '1950', city: 'Sion');

        $this->actAsOrg()->post('/expense-claims', $this->claimTo('contact:'.$contact->id))->assertSessionHasNoErrors();

        $place = Place::where('contact_id', $contact->id)->sole();
        $this->assertFalse($place->hasCoordinates());
        $this->assertSame('Sion', $place->city);
    }

    #[Test]
    public function any_address_becomes_one_place_of_kind_other_that_is_reused(): void
    {
        $first = $this->actAsOrg()->postJson('/expense-claims/addresses', ['address' => 'Rue de Bourg 1 1003 Lausanne'])
            ->assertOk()->json('place');
        $again = $this->actAsOrg()->postJson('/expense-claims/addresses', ['address' => '  rue de bourg 1  1003 lausanne '])
            ->assertOk()->json('place');

        $this->assertSame($first['id'], $again['id']);
        $place = Place::findOrFail($first['id']);
        $this->assertSame(['other', 'Rue de Bourg 1', '1003', 'Lausanne'], [$place->kind, $place->address, $place->postal_code, $place->city]);
        $this->assertTrue($place->hasCoordinates());
        $this->assertSame(1, Place::where('kind', 'other')->count());

        // Typed as is (e.g. abroad): kept without coordinates, the km by hand.
        $typed = $this->actAsOrg()->postJson('/expense-claims/addresses', ['address' => '12 rue de Rivoli, Paris'])->assertOk()->json('place');
        $this->assertNull($typed['lat']);
        $this->actAsOrg()->postJson('/expense-claims/addresses', ['address' => 'ab'])->assertUnprocessable();

        $this->actAsOrg()->post('/expense-claims', $this->claimTo($typed['id']))->assertSessionHasNoErrors();
        $this->assertSame($typed['id'], Claim::sole()->lines->sole()->to_place_id);
    }

    #[Test]
    public function another_organisations_contact_or_place_is_refused(): void
    {
        $other = Organization::factory()->create();
        $foreign = $this->contact($other);
        $foreignPlace = Place::withoutGlobalScopes()->create(['organization_id' => $other->id, 'kind' => 'hq', 'label' => 'Elsewhere']);

        $this->actAsOrg()->post('/expense-claims', $this->claimTo('contact:'.$foreign->id))->assertSessionHasErrors('lines.0.to_place_id');
        $this->actAsOrg()->post('/expense-claims', $this->claimTo($foreignPlace->id))->assertSessionHasErrors('lines.0.to_place_id');
        $this->actAsOrg()->post('/expense-claims', $this->claimTo('contact:abc'))->assertSessionHasErrors('lines.0.to_place_id');
        $this->actAsOrg()->getJson('/expense-claims/distance?from=contact:'.$foreign->id.'&to='.$foreignPlace->id)->assertUnprocessable();

        $this->assertSame(0, Claim::count());
        $this->assertSame(0, Place::withoutGlobalScopes()->where('contact_id', $foreign->id)->count());
    }

    #[Test]
    public function a_non_manager_sees_contacts_but_not_other_peoples_homes_and_may_add_an_address(): void
    {
        $staff = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($staff->id, ['role' => 'employee']);
        $this->assignOrganizationRole($staff, $this->organization, 'employee');
        $home = Place::create(['organization_id' => $this->org->id, 'kind' => 'home', 'label' => 'Home me']);
        Place::create(['organization_id' => $this->org->id, 'kind' => 'home', 'label' => 'Home other']);
        Person::create(['organization_id' => $this->org->id, 'name' => 'Staff', 'user_id' => $staff->id, 'home_place_id' => $home->id]);
        $contact = $this->contact();
        Contact::factory()->create(['organization_id' => $this->organization->id, 'name' => 'No address', 'city' => null]);
        $asStaff = fn () => $this->actingAs($staff)->withSession(['current_organization_id' => $this->organization->id]);

        $asStaff()->get('/expense-claims/create')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('places', fn ($places) => collect($places)->pluck('label')->all() === ['Home me'])
                ->where('contacts', fn ($contacts) => collect($contacts)->pluck('value')->all() === ['contact:'.$contact->id]));

        $asStaff()->getJson('/expense-claims/address-search?q=Rue de Bourg')->assertOk()->assertJsonPath('results.0.label', 'Rue de Bourg 1 1003 Lausanne');
        $asStaff()->postJson('/expense-claims/addresses', ['address' => 'Rue de Bourg 1 1003 Lausanne'])->assertOk()->assertJsonPath('place.kind', 'other');
        $asStaff()->getJson('/settings/expense-claims/address-search?q=Rue de Bourg')->assertForbidden();
    }
}
