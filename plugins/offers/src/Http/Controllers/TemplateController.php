<?php

namespace Plugins\Offers\Http\Controllers;

use App\Domains\Organizations\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Response;
use Plugins\Offers\Models\Offer;
use Plugins\Offers\Models\OfferSetting;
use Plugins\Offers\Models\OfferTemplate;
use Plugins\Offers\Support\Layout;

class TemplateController extends PluginController
{
    public function index(): Response
    {
        $this->authorizeView();
        $used = Offer::query()->whereNotNull('template_id')->groupBy('template_id')
            ->selectRaw('template_id, COUNT(*) AS n')->pluck('n', 'template_id');

        return $this->page('Offers/Templates', [
            'templates' => OfferTemplate::query()->orderBy('name')->get()
                ->map(fn (OfferTemplate $t): array => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'title' => $t->title,
                    'lines' => count($t->lines ?? []),
                    'is_default' => $t->is_default,
                    'used' => (int) ($used[$t->id] ?? 0),
                ]),
            'settings' => OfferSetting::for($this->orgId())->only(['validity_months']),
        ]);
    }

    /** Offer settings of the organisation (shown on the templates page). */
    public function updateSettings(Request $request): RedirectResponse
    {
        $this->authorizeWrite();
        $data = $request->validate([
            'validity_months' => ['required', 'integer', 'min:1', 'max:24'],
        ]);
        OfferSetting::query()->updateOrCreate(['organization_id' => $this->orgId()], $data);

        return redirect('/offer-templates')->with('success', __('offers::of.settings_saved'));
    }

    public function create(): Response
    {
        $this->authorizeWrite();

        $organization = Organization::query()->findOrFail($this->orgId());
        $language = in_array($organization->locale, ['fr', 'de', 'it', 'en'], true) ? $organization->locale : 'fr';

        return $this->page('Offers/TemplateForm', [
            'template' => null,
            'defaultLayout' => Layout::DEFAULT,
            // Fixed texts of the Word offer model, as a starting point (editable).
            'defaultClosing' => (string) trans('offers::of.default_closing', [], $language),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite();
        $this->save($this->validated($request), new OfferTemplate(['organization_id' => $this->orgId()]));

        return redirect('/offer-templates')->with('success', __('offers::of.template_saved'));
    }

    public function edit(OfferTemplate $template): Response
    {
        $this->authorizeWrite();

        return $this->page('Offers/TemplateForm', [
            'template' => ['layout' => Layout::normalize($template->layout)] + $template->only(['id', 'name', 'title', 'intro', 'closing', 'lines', 'is_default']),
            'defaultLayout' => Layout::DEFAULT,
        ]);
    }

    public function update(Request $request, OfferTemplate $template): RedirectResponse
    {
        $this->authorizeWrite();
        $this->save($this->validated($request), $template);

        return redirect('/offer-templates')->with('success', __('offers::of.template_saved'));
    }

    public function destroy(OfferTemplate $template): RedirectResponse
    {
        $this->authorizeWrite();
        $template->delete();

        return redirect('/offer-templates')->with('success', __('offers::of.template_deleted'));
    }

    /** @param  array<string, mixed>  $data */
    private function save(array $data, OfferTemplate $template): void
    {
        DB::transaction(function () use ($data, $template): void {
            if (! empty($data['is_default'])) {
                // Serialise concurrent default changes of the organisation (its row is the lock, so the
                // first template of an organisation is covered too); a partial unique index enforces it.
                DB::table('organizations')->where('id', $this->orgId())->lockForUpdate()->value('id');
                OfferTemplate::query()->when($template->exists, fn ($q) => $q->whereKeyNot($template->id))->update(['is_default' => false]);
            }
            $template->fill([
                'name' => $data['name'],
                'title' => $data['title'] ?? null,
                'intro' => $data['intro'] ?? null,
                'closing' => $data['closing'] ?? null,
                'layout' => Layout::normalize($data['layout'] ?? null),
                'is_default' => (bool) ($data['is_default'] ?? false),
                'lines' => array_map(fn (array $l): array => [
                    'type' => $l['type'],
                    'label' => $l['label'] ?? null,
                    'description' => $l['description'],
                    'quantity' => $l['type'] === 'item' ? (string) $l['quantity'] : null,
                    'unit' => $l['type'] === 'item' ? ($l['unit'] ?? null) : null,
                    'unit_price' => $l['type'] === 'item' ? (string) $l['unit_price'] : null,
                ], array_values($data['lines'])),
            ])->save();
        });
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'intro' => ['nullable', 'string', 'max:20000'],
            'closing' => ['nullable', 'string', 'max:20000'],
            'is_default' => ['boolean'],
            ...Layout::rules(),
            ...$this->lineRules(0),
        ]);
    }
}
