<?php

namespace Plugins\ExpenseClaims\Services;

use App\Domains\Contacts\Models\Contact;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Plugins\ExpenseClaims\Models\Place;

/**
 * Destinations of a km line: the organisation's places, its contacts with an
 * address (`contact:<id>`, resolved to a client place) and any address
 * (saved as a place of kind `other`). Places made here are reused, never duplicated.
 * A contact's address is copied once, when its place is created; afterwards the
 * place is maintained in the settings.
 */
final class Destinations
{
    public const CONTACT_PREFIX = 'contact:';

    /** Geocoder timeout on the save path (seconds). */
    private const TIMEOUT = 4;

    public function __construct(private Distances $distances) {}

    /**
     * Contacts offered as destinations: those with a city.
     *
     * @return Collection<int, Contact>
     */
    public function contacts(string $orgId): Collection
    {
        return Contact::query()->where('organization_id', $orgId)
            ->whereNotNull('city')->where('city', '!=', '')
            ->orderBy('name')
            ->get(['id', 'name', 'city']);
    }

    /** A place id, or (when contacts are allowed) a contact reference, of the organisation. */
    public function exists(string $orgId, mixed $value, bool $contacts): bool
    {
        if (! is_string($value)) {
            return false;
        }
        if (str_starts_with($value, self::CONTACT_PREFIX)) {
            $id = substr($value, strlen(self::CONTACT_PREFIX));

            return $contacts && ctype_digit($id)
                && Contact::query()->where('organization_id', $orgId)->whereKey((int) $id)->exists();
        }

        return Str::isUuid($value) && Place::query()->where('organization_id', $orgId)->whereKey($value)->exists();
    }

    /** The place for a validated destination. */
    public function resolve(string $orgId, ?string $value): ?Place
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (str_starts_with($value, self::CONTACT_PREFIX)) {
            $contact = Contact::query()->where('organization_id', $orgId)
                ->whereKey((int) substr($value, strlen(self::CONTACT_PREFIX)))->firstOrFail();

            return $this->forContact($orgId, $contact);
        }

        return Place::query()->where('organization_id', $orgId)->whereKey($value)->firstOrFail();
    }

    /** The contact's client place: reused when one exists, else created from its address and geocoded. */
    public function forContact(string $orgId, Contact $contact): Place
    {
        $existing = Place::query()->where('organization_id', $orgId)->where('kind', 'client')
            ->where('contact_id', $contact->id)->ordered()->first();
        if ($existing !== null) {
            return $existing;
        }

        $blank = fn (?string $v): ?string => $v === null || trim($v) === '' ? null : trim($v);
        $place = new Place([
            'organization_id' => $orgId, 'kind' => 'client', 'label' => $contact->name, 'contact_id' => $contact->id,
            'address' => $blank($contact->address), 'postal_code' => $blank($contact->postal_code),
            'city' => $blank($contact->city), 'country' => strtoupper($blank($contact->country) ?? 'CH'),
        ]);
        $place->fill($this->geocodeContact($place));
        $place->save();

        return $place;
    }

    /**
     * Any address: a geocoder result (chosen in the search) gets its coordinates,
     * other text is kept as typed without coordinates (km by hand). The country
     * column is not nullable: such a place keeps the default CH.
     */
    public function forAddress(string $orgId, string $text): Place
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $place = Place::query()->where('organization_id', $orgId)->where('kind', 'other')
            ->whereRaw('lower(label) = ?', [mb_strtolower($text)])->first()
            ?? new Place(['organization_id' => $orgId, 'kind' => 'other', 'label' => $text, 'address' => $text, 'country' => 'CH']);
        if ($place->exists && $place->hasCoordinates()) {
            return $place;
        }

        // A new place, or a reused one without coordinates: (re)try the geocoder once.
        $match = collect($this->search($text))->first(fn (array $r): bool => self::normalize($r['label']) === self::normalize($text));
        if ($match !== null) {
            $place->fill(['lat' => $match['lat'], 'lon' => $match['lon']] + self::splitLabel($match['label']));
        }
        if (! $place->exists || $place->isDirty()) {
            $place->save();
        }

        return $place;
    }

    /**
     * geo.admin labels read "Street 12 1234 City".
     *
     * @return array{address?: string, postal_code?: string, city?: string}
     */
    public static function splitLabel(string $label): array
    {
        return preg_match('/^(.*?)\s+(\d{4})\s+(.+)$/u', $label, $m) === 1
            ? ['address' => $m[1], 'postal_code' => $m[2], 'city' => $m[3]]
            : [];
    }

    /**
     * Coordinates of a contact's Swiss (or Liechtenstein) address, only from a
     * result that starts with the street and contains the postal code; else
     * none (the km are then entered by hand).
     *
     * @return array{lat?: float, lon?: float}
     */
    private function geocodeContact(Place $place): array
    {
        if ($place->address === null || $place->city === null || ! in_array($place->country, ['CH', 'LI'], true)) {
            return [];
        }
        $street = self::normalize($place->address);
        foreach ($this->search($place->addressLine()) as $result) {
            $label = self::normalize($result['label']);
            if (str_starts_with($label, $street) && ($place->postal_code === null || str_contains($label, self::normalize($place->postal_code)))) {
                return ['lat' => $result['lat'], 'lon' => $result['lon']];
            }
        }

        return [];
    }

    /**
     * @return list<array{label: string, lat: float, lon: float}>
     */
    private function search(string $query): array
    {
        return mb_strlen($query) < 3 ? [] : $this->distances->search($query, self::TIMEOUT);
    }

    private static function normalize(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
