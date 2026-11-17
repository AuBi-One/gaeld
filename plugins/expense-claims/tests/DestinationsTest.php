<?php

namespace Plugins\ExpenseClaims\Tests;

require_once __DIR__.'/ExpenseClaimsTestCase.php';

use App\Domains\Contacts\Models\Contact;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Plugins\ExpenseClaims\Models\Claim;
use Plugins\ExpenseClaims\Models\Person;
use Plugins\ExpenseClaims\Models\Place;
use Plugins\ExpenseClaims\Services\Claims;
use Plugins\ExpenseClaims\Services\Destinations;

/**
 * Destinations of km lines: contacts and any address (geo.admin is faked by search text).
 */
class DestinationsTest extends ExpenseClaimsTestCase
{
    private const BOURG = '<b>Rue de Bourg 1</b> 1003 Lausanne';

    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $text = self::searchText($request);
            $results = match (true) {
                str_contains($text, 'Rue de Bourg'), str_contains($text, 'Rue Fausse') => [['attrs' => ['label' => self::BOURG, 'lat' => 46.52, 'lon' => 6.63]]],
                default => [],
            };

            return Http::response(['results' => $results]);
        });
        $this->person = Person::create(['organization_id' => $this->org->id, 'name' => 'Anna Muster', 'employee_id' => $this->employee->id]);
    }

    private static function searchText(Request $request): string
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return (string) ($query['searchText'] ?? '');
    }

    private function contact(?Organization $org = null, ?string $address = 'Rue de Bourg 1', string $postal = '1003', string $city = 'Lausanne'): Contact
    {
        return Contact::factory()->create([
            'organization_id' => ($org ?? $this->organization)->id, 'name' => 'Client SA',
            'address' => $address, 'postal_code' => $postal, 'city' => $city, 'country' => 'CH',
        ]);
    }

    /** @return array<string, mixed> */
    private function claimTo(string $to, string $title = 'Visite', ?string $personId = null): array
    {
        return [
            'person_id' => $personId ?? $this->person->id, 'date' => '2026-04-02', 'title' => $title,
            'lines' => [['type' => 'km', 'to_place_id' => $to, 'km' => '20', 'round_trip' => false]],
        ];
    }

    /** @return array{0: User, 1: \Closure(): static, 2: Person} an employee (expenses.create, no contacts.view) */
    private function staff(): array
    {
        $staff = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($staff->id, ['role' => 'employee']);
        $this->assignOrganizationRole($staff, $this->organization, 'employee');
        $home = Place::create(['organization_id' => $this->org->id, 'kind' => 'home', 'label' => 'Home me']);
        $person = Person::create(['organization_id' => $this->org->id, 'name' => 'Staff', 'user_id' => $staff->id, 'home_place_id' => $home->id]);

        return [$staff, fn () => $this->actingAs($staff)->withSession(['current_organization_id' => $this->organization->id]), $person];
    }

    #[Test]
    public function a_contact_becomes_one_client_place_that_is_reused(): void
    {
        $contact = $this->contact();

        $this->actAsOrg()->post('/expense-claims', [
            'person_id' => $this->person->id, 'date' => '2026-04-02', 'title' => 'First',
            'lines' => [
                ['type' => 'km', 'to_place_id' => 'contact:'.$contact->id, 'km' => '20', 'round_trip' => false],
                ['type' => 'km', 'from_place_id' => 'contact:'.$contact->id, 'km' => '20', 'round_trip' => false],
            ],
        ])->assertSessionHasNoErrors();
        $place = Place::where('contact_id', $contact->id)->sole();
        $this->assertSame(['client', 'Client SA', 'Rue de Bourg 1', 'Lausanne'], [$place->kind, $place->label, $place->address, $place->city]);
        $this->assertEqualsWithDelta(46.52, (float) $place->lat, 0.0001);
        $lines = Claim::where('title', 'First')->sole()->lines;
        $this->assertSame([$place->id, $place->id], [$lines[0]->to_place_id, $lines[1]->from_place_id]);
        Http::assertSentCount(1); // the same contact twice in one request: resolved once
        Http::assertSent(fn (Request $r) => self::searchText($r) === 'Rue de Bourg 1, 1003 Lausanne');

        // The form now offers the contact by its place, once; the ref lets a pending `contact:` value follow.
        $this->actAsOrg()->get('/expense-claims/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('contacts.0.value', $place->id)
                ->where('contacts.0.ref', 'contact:'.$contact->id)
                ->where('places', fn ($places) => ! collect($places)->contains('id', $place->id)));

        $this->actAsOrg()->post('/expense-claims', $this->claimTo('contact:'.$contact->id, 'Second'))->assertSessionHasNoErrors();
        $this->assertSame(1, Place::where('contact_id', $contact->id)->count());
        $this->assertSame($place->id, Claim::where('title', 'Second')->sole()->lines->sole()->to_place_id);
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_client_place_edited_in_the_settings_is_not_overwritten_by_its_contact(): void
    {
        $contact = $this->contact();
        $place = app(Destinations::class)->forContact($this->org->id, $contact);
        $place->update(['address' => 'Avenue de la Gare 2', 'postal_code' => '1003', 'lat' => 46.51, 'lon' => 6.62]);
        $contact->update(['address' => 'Bahnhofstrasse 5', 'postal_code' => '8001', 'city' => 'Zürich']);

        $this->actAsOrg()->post('/expense-claims', $this->claimTo('contact:'.$contact->id, 'A'))->assertSessionHasNoErrors();
        $this->actAsOrg()->post('/expense-claims', $this->claimTo($place->id, 'B'))->assertSessionHasNoErrors();

        $place->refresh();
        $this->assertSame(['Avenue de la Gare 2', 'Lausanne'], [$place->address, $place->city]);
        $this->assertEqualsWithDelta(46.51, (float) $place->lat, 0.0001);
        $this->assertSame(1, Place::where('contact_id', $contact->id)->count());
    }

    #[Test]
    public function a_contact_is_geocoded_only_on_a_result_with_its_street_and_postal_code(): void
    {
        // The geocoder answers Rue de Bourg 1 for "Rue Fausse": not the contact's street.
        $wrongStreet = $this->contact(address: 'Rue Fausse 3');
        $noStreet = $this->contact(address: null);
        $otherPostal = $this->contact(postal: '1950', city: 'Sion');

        foreach ([$wrongStreet, $noStreet, $otherPostal] as $i => $contact) {
            $this->actAsOrg()->post('/expense-claims', $this->claimTo('contact:'.$contact->id, "T{$i}"))->assertSessionHasNoErrors();
            $this->assertFalse(Place::where('contact_id', $contact->id)->sole()->hasCoordinates(), "contact {$i}");
        }
        Http::assertSent(fn (Request $r) => self::searchText($r) === 'Rue Fausse 3, 1003 Lausanne');
        Http::assertSentCount(2); // no street: no lookup
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
        Http::assertSentCount(1); // reused with coordinates: no new lookup

        // Typed as is (e.g. abroad): kept without coordinates, the km by hand; the country column keeps CH.
        $typed = $this->actAsOrg()->postJson('/expense-claims/addresses', ['address' => '12 rue de Rivoli, Paris'])->assertOk()->json('place');
        $this->assertFalse(Place::findOrFail($typed['id'])->hasCoordinates());
        $this->actAsOrg()->postJson('/expense-claims/addresses', ['address' => 'ab'])->assertUnprocessable();

        $this->actAsOrg()->post('/expense-claims', $this->claimTo($typed['id']))->assertSessionHasNoErrors();
        $this->assertSame($typed['id'], Claim::sole()->lines->sole()->to_place_id);
    }

    #[Test]
    public function a_reused_address_without_coordinates_is_geocoded_again(): void
    {
        $place = Place::create(['organization_id' => $this->org->id, 'kind' => 'other', 'label' => 'Rue de Bourg 1 1003 Lausanne', 'address' => 'Rue de Bourg 1 1003 Lausanne']);

        $this->actAsOrg()->postJson('/expense-claims/addresses', ['address' => 'Rue de Bourg 1 1003 Lausanne'])
            ->assertOk()->assertJsonPath('place.id', $place->id);

        $this->assertTrue($place->fresh()->hasCoordinates());
        $this->assertSame(1, Place::where('kind', 'other')->count());
    }

    #[Test]
    public function another_organisations_contact_or_place_is_refused(): void
    {
        $other = Organization::factory()->create();
        $foreign = $this->contact($other);
        $foreignPlace = Place::withoutGlobalScopes()->create(['organization_id' => $other->id, 'kind' => 'hq', 'label' => 'Elsewhere']);
        $own = Place::create(['organization_id' => $this->org->id, 'kind' => 'hq', 'label' => 'Office']);

        $this->actAsOrg()->post('/expense-claims', $this->claimTo('contact:'.$foreign->id))->assertSessionHasErrors('lines.0.to_place_id');
        $this->actAsOrg()->post('/expense-claims', $this->claimTo($foreignPlace->id))->assertSessionHasErrors('lines.0.to_place_id');
        $this->actAsOrg()->post('/expense-claims', $this->claimTo('contact:abc'))->assertSessionHasErrors('lines.0.to_place_id');
        $this->actAsOrg()->getJson('/expense-claims/distance?from=contact:'.$foreign->id.'&to='.$own->id)
            ->assertUnprocessable()->assertJsonValidationErrors('from')->assertJsonMissingValidationErrors('to');
        $this->actAsOrg()->getJson('/expense-claims/distance?from='.$own->id.'&to='.$foreignPlace->id)
            ->assertUnprocessable()->assertJsonValidationErrors('to')->assertJsonMissingValidationErrors('from');

        $this->assertSame(0, Claim::count());
        $this->assertSame(0, Place::withoutGlobalScopes()->where('contact_id', $foreign->id)->count());
    }

    #[Test]
    public function the_distance_of_a_contact_to_its_own_place_is_refused_as_the_same_place(): void
    {
        $contact = $this->contact();
        $place = app(Destinations::class)->forContact($this->org->id, $contact);

        $this->actAsOrg()->getJson('/expense-claims/distance?from=contact:'.$contact->id.'&to='.$place->id)
            ->assertUnprocessable()->assertJsonPath('message', __('expense-claims::ec.distance_same_place'));
    }

    #[Test]
    public function an_employee_without_contacts_access_picks_a_contact_by_name_and_city(): void
    {
        [, $asStaff, $me] = $this->staff();
        Place::create(['organization_id' => $this->org->id, 'kind' => 'home', 'label' => 'Home other']);
        $contact = $this->contact();

        $asStaff()->get('/expense-claims/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('contacts', [[
                'value' => 'contact:'.$contact->id, 'ref' => 'contact:'.$contact->id, 'name' => $contact->name, 'city' => $contact->city,
            ]])->where('places', fn ($places) => collect($places)->pluck('label')->all() === ['Home me']));
        $asStaff()->post('/expense-claims', $this->claimTo('contact:'.$contact->id, 'X', $me->id))->assertSessionHasNoErrors();
        $place = Place::where('contact_id', $contact->id)->sole();
        $asStaff()->get('/expense-claims/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('contacts.0.value', $place->id)
                ->where('places', fn ($places) => collect($places)->every(fn ($p) => array_keys((array) $p) === ['id', 'kind', 'label', 'city'])));
        $asStaff()->getJson('/expense-claims/distance?from=contact:'.$contact->id.'&to='.Place::where('label', 'Home me')->value('id'))
            ->assertJsonMissingValidationErrors(['from', 'to']);
        $asStaff()->get('/contacts/'.$contact->uuid)->assertForbidden();

        // A contact without a city is not offered, and its id is refused.
        $hidden = $this->contact(city: '');
        $asStaff()->post('/expense-claims', $this->claimTo('contact:'.$hidden->id, 'Y', $me->id))->assertSessionHasErrors('lines.0.to_place_id');

        // "Other address" stays open to them, the settings search does not.
        $asStaff()->getJson('/expense-claims/address-search?q=Rue de Bourg')->assertOk()->assertJsonPath('results.0.label', 'Rue de Bourg 1 1003 Lausanne');
        $asStaff()->postJson('/expense-claims/addresses', ['address' => 'Rue de Bourg 1 1003 Lausanne'])->assertOk()->assertJsonPath('place.kind', 'other');
        $asStaff()->getJson('/settings/expense-claims/address-search?q=Rue de Bourg')->assertForbidden();
    }

    #[Test]
    public function an_employee_sees_typed_addresses_only_on_the_claim_that_uses_them(): void
    {
        [, $asStaff, $me] = $this->staff();
        $mine = Place::create(['organization_id' => $this->org->id, 'kind' => 'other', 'label' => 'Used here']);
        Place::create(['organization_id' => $this->org->id, 'kind' => 'other', 'label' => 'Typed by someone']);
        $claim = app(Claims::class)->saveDraft($this->org->id, $this->claimTo($mine->id, 'Mine', $me->id));

        $asStaff()->get('/expense-claims/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('places', fn ($places) => collect($places)->pluck('label')->all() === ['Home me']));
        $asStaff()->get("/expense-claims/{$claim->id}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->where('places', fn ($places) => collect($places)->pluck('label')->all() === ['Home me', 'Used here']));
        $this->actAsOrg()->get('/expense-claims/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('places', fn ($places) => collect($places)->pluck('label')->sort()->values()->all() === ['Home me', 'Typed by someone', 'Used here']));
    }
}
