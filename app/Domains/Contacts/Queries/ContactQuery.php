<?php

namespace App\Domains\Contacts\Queries;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\ContactPerson;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Support\QueryBuilder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ContactQuery
{
    /**
     * @return LengthAwarePaginator<int, Contact>
     */
    public static function list(Request $request, int $perPage = 20): LengthAwarePaginator
    {
        return QueryBuilder::for(Contact::query(), $request)
            ->allowedSorts(['name', 'email', 'city', 'country', 'created_at'], 'name', 'asc')
            ->allowedFilters(['country', 'currency', 'default_expense_category'])
            ->searchable(['name', 'email', 'city', 'vat_number', 'contactPersons.first_name', 'contactPersons.last_name', 'contactPersons.email'])
            ->apply()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Soft-deleted contacts, for the "trash" recovery view.
     *
     * @return LengthAwarePaginator<int, Contact>
     */
    public static function trashed(Request $request, int $perPage = 20): LengthAwarePaginator
    {
        return QueryBuilder::for(Contact::onlyTrashed(), $request)
            ->allowedSorts(['name', 'deleted_at'], 'deleted_at', 'desc')
            ->searchable(['name', 'email'])
            ->apply()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return Collection<int, Contact>
     */
    public static function forSelect(): Collection
    {
        $orgId = app(CurrentOrganization::class)->id();

        return Cache::tags(["org:{$orgId}:contacts"])->remember(
            "contacts_select:{$orgId}",
            600,
            fn () => Contact::orderBy('name')->get()
        );
    }

    /**
     * Contact persons grouped by their customer, for the "Attention: ..."
     * selector on the invoice and recurring-invoice forms. Not cached (unlike
     * forSelect(), used for the plain customer list): a person
     * added or edited must show up immediately.
     *
     * @return array<int, array<int, array{id: string, name: string, email: string|null, is_primary: bool}>>
     */
    public static function personsByContact(): array
    {
        return Contact::query()->with('contactPersons')->get()
            ->mapWithKeys(fn (Contact $c): array => [$c->id => $c->contactPersons
                ->sortByDesc('is_primary')
                ->map(fn (ContactPerson $p): array => ['id' => $p->id, 'name' => $p->full_name, 'email' => $p->email, 'is_primary' => (bool) $p->is_primary])
                ->values()
                ->all(),
            ])
            ->all();
    }

    public static function hasMatchingSupplier(string $organizationId, string $creditorName): bool
    {
        return Contact::where('organization_id', $organizationId)
            ->whereNotNull('default_expense_category')
            ->where('name', 'ilike', '%'.$creditorName.'%')
            ->exists();
    }

    public static function findByCreditorName(string $organizationId, string $creditorName): ?Contact
    {
        return Contact::where('organization_id', $organizationId)
            ->whereNotNull('default_expense_category')
            ->where('name', 'ilike', '%'.$creditorName.'%')
            ->first();
    }
}
