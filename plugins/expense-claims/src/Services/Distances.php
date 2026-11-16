<?php

namespace Plugins\ExpenseClaims\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Plugins\ExpenseClaims\Models\Distance;
use Plugins\ExpenseClaims\Models\Place;

/**
 * Geocoding with the federal geo.admin.ch search service and one-way driving
 * distances from a hosted routing engine (openrouteservice by default, or an
 * OSRM server). Only coordinates leave the application: no names, dates or IDs.
 * Distances are cached per place pair and dropped when a place moves.
 */
final class Distances
{
    /**
     * Address search (Switzerland); an unreachable service finds nothing.
     *
     * @return list<array{label: string, lat: float, lon: float}>
     */
    public function search(string $query, int $timeout = 8): array
    {
        try {
            $response = Http::timeout($timeout)->get('https://api3.geo.admin.ch/rest/services/ech/SearchServer', [
                'type' => 'locations',
                'origins' => 'address',
                'searchText' => $query,
                'limit' => 8,
                'sr' => 4326,
            ]);
        } catch (ConnectionException $e) {
            report($e);

            return [];
        }

        if (! $response->ok()) {
            return [];
        }

        /** @var list<array{attrs?: array<string, mixed>}> $results */
        $results = (array) $response->json('results', []);

        return array_values(collect($results)
            ->map(fn (array $r): array => [
                'label' => trim(strip_tags((string) ($r['attrs']['label'] ?? ''))),
                'lat' => (float) ($r['attrs']['lat'] ?? 0),
                'lon' => (float) ($r['attrs']['lon'] ?? 0),
            ])
            ->filter(fn (array $r): bool => $r['label'] !== '' && $r['lat'] !== 0.0)
            ->all());
    }

    public function isConfigured(): bool
    {
        return match (config('expense-claims.routing.provider')) {
            'ors' => (string) config('expense-claims.routing.ors_key') !== '',
            'osrm' => (string) config('expense-claims.routing.osrm_url') !== '',
            default => false,
        };
    }

    /**
     * One-way driving distance in km (1 decimal), from cache or the routing engine.
     *
     * @throws \DomainException When the places lack coordinates or the lookup fails
     */
    public function km(Place $from, Place $to): string
    {
        if (! $from->hasCoordinates() || ! $to->hasCoordinates()) {
            throw new \DomainException(__('expense-claims::ec.distance_missing_coordinates'));
        }

        $cached = Distance::query()->where('from_place_id', $from->id)->where('to_place_id', $to->id)->first()
            ?? Distance::query()->where('from_place_id', $to->id)->where('to_place_id', $from->id)->first();
        if ($cached !== null) {
            return (string) $cached->km;
        }

        $provider = (string) config('expense-claims.routing.provider');
        $metres = match ($provider) {
            'ors' => $this->ors($from, $to),
            'osrm' => $this->osrm($from, $to),
            default => null,
        };
        if ($metres === null) {
            throw new \DomainException(__('expense-claims::ec.distance_lookup_failed'));
        }

        $km = number_format($metres / 1000, 1, '.', '');
        Distance::query()->create(['from_place_id' => $from->id, 'to_place_id' => $to->id, 'km' => $km, 'provider' => $provider]);

        return $km;
    }

    public function forget(Place $place): void
    {
        Distance::query()->where('from_place_id', $place->id)->orWhere('to_place_id', $place->id)->delete();
    }

    private function ors(Place $from, Place $to): ?float
    {
        $response = Http::timeout(10)
            ->withHeaders(['Authorization' => (string) config('expense-claims.routing.ors_key')])
            ->post('https://api.openrouteservice.org/v2/directions/driving-car', [
                'coordinates' => [[(float) $from->lon, (float) $from->lat], [(float) $to->lon, (float) $to->lat]],
                'instructions' => false,
            ]);

        $metres = $response->ok() ? $response->json('routes.0.summary.distance') : null;

        return is_numeric($metres) ? (float) $metres : null;
    }

    private function osrm(Place $from, Place $to): ?float
    {
        $base = rtrim((string) config('expense-claims.routing.osrm_url'), '/');
        $path = sprintf('%s,%s;%s,%s', $from->lon, $from->lat, $to->lon, $to->lat);
        $response = Http::timeout(10)->get("{$base}/route/v1/driving/{$path}", ['overview' => 'false']);

        $metres = $response->ok() ? $response->json('routes.0.distance') : null;

        return is_numeric($metres) ? (float) $metres : null;
    }
}
