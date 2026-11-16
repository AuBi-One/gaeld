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
 */
final class Destinations
{
    public const CONTACT_PREFIX = 'contact:';

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
            ->orderBy('name')->limit(500)
            ->get(['id', 'name', 'address', 'postal_code', 'city', 'country']);
    }

    /** A place id or a contact reference of the organisation. */
    public function exists(string $orgId, mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        if (str_starts_with($value, self::CONTACT_PREFIX)) {
            $id = substr($value, strlen(self::CONTACT_PREFIX));

            return ctype_digit($id) && Contact::query()->where('organization_id', $orgId)->whereKey((int) $id)->exists();
        }

        return Str::isUuid($value) && Place::query()->where('organization_id', $orgId)->whereKey($value)->exists();
    }

    /**
     * The place for a validated destination; a contact's place follows the contact's current address.
     */
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

        $place = Place::query()->where('organization_id', $orgId)->whereKey($value)->firstOrFail();
        if ($place->kind === 'client' && $place->contact_id !== null) {
            $contact = Contact::query()->where('organization_id', $orgId)->whereKey($place->contact_id)->first();
            if ($contact !== null) {
                $this->follow($place, $contact);
            }
        }

        return $place;
    }

    /** The contact's client place: reused when one exists, else created and geocoded. */
    public function forContact(string $orgId, Contact $contact): Place
    {
        $place = Place::query()->where('organization_id', $orgId)->where('kind', 'client')
            ->where('contact_id', $contact->id)->ordered()->first();
        if ($place !== null) {
            $this->follow($place, $contact);

            return $place;
        }

        $place = new Place(['organization_id' => $orgId, 'kind' => 'client', 'label' => $contact->name, 'contact_id' => $contact->id]);
        $place->fill($this->contactAddress($contact));
        $place->fill($this->geocode($place));
        $place->save();

        return $place;
    }

    /**
     * Any address: a geocoder result (chosen in the search) gets its coordinates,
     * other text is kept as typed without coordinates (km by hand).
     */
    public function forAddress(string $orgId, string $text): Place
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $existing = Place::query()->where('organization_id', $orgId)->where('kind', 'other')
            ->whereRaw('lower(label) = ?', [mb_strtolower($text)])->first();
        if ($existing !== null) {
            return $existing;
        }

        $place = new Place(['organization_id' => $orgId, 'kind' => 'other', 'label' => $text, 'address' => $text, 'country' => 'CH']);
        $match = collect($this->search($text))->first(fn (array $r): bool => mb_strtolower($r['label']) === mb_strtolower($text));
        if ($match !== null) {
            $place->fill(['lat' => $match['lat'], 'lon' => $match['lon']] + self::splitLabel($match['label']));
        }
        $place->save();

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

    /** Keep a contact's place on the contact's address; a move forgets the cached distances. */
    private function follow(Place $place, Contact $contact): void
    {
        $place->fill($this->contactAddress($contact));
        if (! $place->isDirty(['address', 'postal_code', 'city', 'country'])) {
            return;
        }
        $place->fill($this->geocode($place));
        $this->distances->forget($place);
        $place->save();
    }

    /**
     * @return array{address: ?string, postal_code: ?string, city: ?string, country: string}
     */
    private function contactAddress(Contact $contact): array
    {
        $blank = fn (?string $v): ?string => $v === null || trim($v) === '' ? null : trim($v);

        return [
            'address' => $blank($contact->address),
            'postal_code' => $blank($contact->postal_code),
            'city' => $blank($contact->city),
            'country' => strtoupper($blank($contact->country) ?? 'CH'),
        ];
    }

    /**
     * Coordinates of a Swiss (or Liechtenstein) address: the first result in the
     * same postal code, else none (the km are then entered by hand).
     *
     * @return array{lat: ?float, lon: ?float}
     */
    private function geocode(Place $place): array
    {
        $none = ['lat' => null, 'lon' => null];
        if (! in_array($place->country, ['CH', 'LI'], true) || $place->city === null) {
            return $none;
        }
        foreach ($this->search($place->addressLine()) as $result) {
            if ($place->postal_code === null || str_contains($result['label'], $place->postal_code)) {
                return ['lat' => $result['lat'], 'lon' => $result['lon']];
            }
        }

        return $none;
    }

    /**
     * @return list<array{label: string, lat: float, lon: float}>
     */
    private function search(string $query): array
    {
        if (mb_strlen($query) < 3) {
            return [];
        }
        try {
            return $this->distances->search($query);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }
}
